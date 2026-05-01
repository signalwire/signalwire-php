<?php

declare(strict_types=1);

namespace SignalWire\Relay;

use SignalWire\Logging\Logger;

/**
 * RELAY Client – manages the WebSocket connection to SignalWire, sends
 * JSON-RPC requests, and dispatches inbound events to the correct Call
 * or Message objects.
 *
 * The transport layer (WebSocket send/receive) is abstracted behind
 * thin methods so that unit tests can subclass or mock without needing
 * a real WebSocket extension.
 */
class Client
{
    // ── identity / auth ───────────────────────────────────────────────
    public string  $project;
    public string  $token;
    public ?string $jwtToken = null;
    public string  $host;
    public string  $scheme = 'wss';
    public string  $relayPath = '/api/relay/ws';
    public array   $contexts = [];
    public bool    $connected = false;
    public ?string $sessionId = null;
    public ?string $protocol = null;
    public ?string $authorizationState = null;
    public string  $agent = 'signalwire-agents-php/1.0';

    // ── correlation maps ──────────────────────────────────────────────
    /** @var array<string, array{resolve: callable, reject: callable}> id => callbacks */
    public array $pending = [];

    /** @var array<string, Call> callId => Call */
    public array $calls = [];

    /** @var array<string, array{resolve: callable, tag: string}> tag => dial promise */
    public array $pendingDials = [];

    /** @var array<string, Message> messageId => Message */
    public array $messages = [];

    // ── event handlers ────────────────────────────────────────────────
    /** @var callable|null */
    public $onCallHandler = null;

    /** @var callable|null */
    public $onMessageHandler = null;

    /** @var callable|null */
    public $onEventHandler = null;

    // ── internals ─────────────────────────────────────────────────────
    private Logger $logger;

    /**
     * The WebSocket transport. May be null before connect() or after a
     * disconnect; tests may inject a fake to bypass real I/O without
     * removing the real transport from production code (subclassing is
     * the supported path).
     */
    protected ?WebSocket $socket = null;

    /**
     * Read timeout (seconds) for a single WS frame in readOnce(). Kept
     * short so the run loop can interleave reconnect bookkeeping; not
     * the time-to-fail-the-RPC, which is bounded by the run-loop logic.
     */
    private float $readTimeout = 5.0;

    private int $reconnectDelay = 1;
    private const MAX_RECONNECT_DELAY = 30;

    private bool $running = false;
    private bool $closing = false;

    // ══════════════════════════════════════════════════════════════════
    //  Construction
    // ══════════════════════════════════════════════════════════════════

    public function __construct(array $options)
    {
        $this->project  = $options['project']  ?? '';
        $this->token    = $options['token']    ?? '';
        $this->contexts = $options['contexts'] ?? [];

        // JWT-only mode: clients can authenticate with a fresh JWT instead
        // of project/token. Both code paths flow through ``connect()``.
        if (isset($options['jwt_token']) && is_string($options['jwt_token'])) {
            $this->jwtToken = $options['jwt_token'];
        }

        // Host may come from options or env. Tests use SIGNALWIRE_RELAY_HOST
        // to point at a local fixture; production uses SIGNALWIRE_SPACE.
        $relayHost = getenv('SIGNALWIRE_RELAY_HOST');
        $space = getenv('SIGNALWIRE_SPACE');
        $this->host = $options['host']
            ?? ($relayHost !== false && $relayHost !== '' ? $relayHost : '')
            ?: (is_string($space) ? $space : '');

        $scheme = $options['scheme'] ?? null;
        if ($scheme === null) {
            $envScheme = getenv('SIGNALWIRE_RELAY_SCHEME');
            $scheme = (is_string($envScheme) && $envScheme !== '')
                ? $envScheme
                : 'wss';
        }
        $this->scheme = strtolower($scheme);

        if (isset($options['path']) && is_string($options['path'])) {
            $this->relayPath = $options['path'];
        }

        $this->logger = Logger::getLogger('relay.client');

        // Validate credentials at construction. Either project+token or
        // a non-empty jwt_token is required. (Mirrors the Python SDK's
        // ``ValueError: project and token are required`` from
        // ``RelayClient.__init__``.)
        $hasJwt = $this->jwtToken !== null && $this->jwtToken !== '';
        $hasProjTok = $this->project !== '' && $this->token !== '';
        if (!$hasJwt && !$hasProjTok) {
            throw new \InvalidArgumentException(
                'RelayClient: project and token are required (or pass jwt_token)'
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  Connection lifecycle
    // ══════════════════════════════════════════════════════════════════

    /**
     * Open the WebSocket connection and run the JSON-RPC `signalwire.connect`
     * handshake. Throws on transport or auth failure.
     *
     * The transport URI is `<scheme>://<host><relayPath>` (defaults to
     * `wss://<host>/api/relay/ws`). Tests point SIGNALWIRE_RELAY_SCHEME
     * at `ws` and SIGNALWIRE_RELAY_HOST at the fixture's host:port.
     */
    public function connect(): void
    {
        if ($this->host === '') {
            throw new \RuntimeException(
                'RelayClient: host is empty. Pass options.host, set '
                . 'SIGNALWIRE_RELAY_HOST (test) or SIGNALWIRE_SPACE.'
            );
        }
        $uri = "{$this->scheme}://{$this->host}{$this->relayPath}";
        $this->logger->info("Connecting to {$uri}");

        $socket = $this->createSocket();
        $socket->connect($uri);
        $this->socket = $socket;
        $this->connected = true;
        $this->reconnectDelay = 1;
        $this->closing = false;

        $this->authenticate();
    }

    /**
     * Hook for tests to inject a fake transport. Production builds a real
     * WebSocket; subclassing this method is the supported way to drive
     * the JSON-RPC layer in isolation.
     */
    protected function createSocket(): WebSocket
    {
        return new WebSocket();
    }

    /**
     * Send the signalwire.connect RPC to authenticate and bind a session.
     *
     * Sends project/token both at the top level AND nested under
     * `authentication`. Python sends the nested form; the audit fixture
     * accepts either; sending both is forward-compatible (per
     * SUBAGENT_PLAYBOOK lessons-learned).
     */
    public function authenticate(): void
    {
        $this->logger->info('Authenticating');

        $params = [
            'version'    => Constants::PROTOCOL_VERSION,
            'agent'      => $this->agent,
            'event_acks' => true,
        ];

        // JWT-only path: just an authentication.jwt_token field.
        if ($this->jwtToken !== null && $this->jwtToken !== '') {
            $params['authentication'] = ['jwt_token' => $this->jwtToken];
        } else {
            // Top-level project/token for fixtures / older RELAY versions
            // plus the canonical nested form. Sending both is forward-
            // compatible (per SUBAGENT_PLAYBOOK lessons-learned).
            $params['project'] = $this->project;
            $params['token']   = $this->token;
            $params['authentication'] = [
                'project' => $this->project,
                'token'   => $this->token,
            ];
        }
        if (!empty($this->contexts)) {
            $params['contexts'] = $this->contexts;
        }
        // Re-send protocol on reconnect to resume the session.
        if ($this->protocol !== null && $this->protocol !== '') {
            $params['protocol'] = $this->protocol;
        }
        // Re-send authorization_state for fast reconnect.
        if ($this->authorizationState !== null && $this->authorizationState !== '') {
            $params['authorization_state'] = $this->authorizationState;
        }

        $result = $this->execute('signalwire.connect', $params);

        $this->sessionId = $result['session_id'] ?? $this->sessionId;
        $this->protocol  = $result['protocol']   ?? $this->protocol;
        // Capture authorization blob if RELAY/audit fixture sent one.
        $authBlob = $result['authorization'] ?? null;
        if (is_array($authBlob)) {
            $newState = $authBlob['authorization_state'] ?? null;
            if (is_string($newState) && $newState !== '') {
                $this->authorizationState = $newState;
            }
        }

        $this->logger->info("Authenticated, session={$this->sessionId}");
    }

    /**
     * Gracefully close the connection.
     */
    public function disconnect(): void
    {
        $this->logger->info('Disconnecting');
        $this->closing = true;
        $this->running = false;
        if ($this->socket !== null) {
            $this->socket->close();
            $this->socket = null;
        }
        $this->connected = false;
        // Reject all pending requests so callers don't hang.
        foreach ($this->pending as $entry) {
            ($entry['reject'])(['code' => -1, 'message' => 'Connection closed']);
        }
        $this->pending = [];
    }

    /**
     * Reconnect with exponential back-off (1 s -> 30 s cap).
     */
    public function reconnect(): void
    {
        $this->connected = false;

        $delay = $this->reconnectDelay;
        $this->logger->warn("Reconnecting in {$delay}s");

        sleep($delay);

        $this->reconnectDelay = min($this->reconnectDelay * 2, self::MAX_RECONNECT_DELAY);

        $this->connect();

        // Re-subscribe to any previously registered contexts.
        if (!empty($this->contexts)) {
            $this->receive($this->contexts);
        }
    }

    /**
     * Main event loop – reads messages until disconnect.
     *
     * Auto-reconnects with exponential backoff on transport errors,
     * preserving authorization_state across reconnects so the server
     * can fast-path the session resumption.
     */
    public function run(): void
    {
        if (!$this->connected) {
            $this->connect();
        }

        $this->running = true;

        while ($this->running && !$this->closing) {
            try {
                $this->readOnce();
            } catch (\RuntimeException $e) {
                $this->logger->error('Read error: ' . $e->getMessage());
                $this->connected = false;
                if ($this->socket !== null) {
                    $this->socket->close();
                    $this->socket = null;
                }
                if ($this->running && !$this->closing) {
                    $this->reconnect();
                }
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  JSON-RPC transport
    // ══════════════════════════════════════════════════════════════════

    /**
     * Send a JSON-RPC request and synchronously wait for the matching
     * response.  Returns the "result" portion of the response.
     *
     * @throws \RuntimeException on error responses or transport timeout.
     */
    public function execute(string $method, array $params = []): array
    {
        $id = $this->generateUuid();

        $msg = [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'method'  => $method,
            'params'  => $params,
        ];

        $result = null;
        $error  = null;

        // Register a pending-response slot.
        $this->pending[$id] = [
            'resolve' => function (array $r) use (&$result) { $result = $r; },
            'reject'  => function (array $e) use (&$error)  { $error = $e; },
        ];

        $this->send($msg);

        // PHP's relay stack is synchronous — we drive the read loop
        // ourselves until the response arrives, the connection drops, or
        // the deadline expires. Keep this tight (10s default, matching
        // Python's _EXECUTE_TIMEOUT) so half-open connections don't hang
        // the caller forever.
        $deadline = microtime(true) + 10.0;
        while ($result === null && $error === null && microtime(true) < $deadline) {
            try {
                $this->readOnce();
            } catch (\RuntimeException $e) {
                unset($this->pending[$id]);
                throw $e;
            }
        }

        unset($this->pending[$id]);

        if ($error !== null) {
            $code    = $error['code']    ?? 0;
            $message = $error['message'] ?? 'Unknown RPC error';
            throw new RelayError($message, $code);
        }

        if ($result === null) {
            throw new RelayError(
                "RelayClient: timeout waiting for response to {$method} (id={$id})"
            );
        }

        return $result;
    }

    /**
     * Encode and send a JSON message over the WebSocket. Throws if the
     * socket is closed; the run loop catches and triggers reconnect.
     */
    public function send(array $msg): void
    {
        $json = json_encode($msg, JSON_THROW_ON_ERROR);
        $this->logger->debug(">> {$json}");

        if ($this->socket === null || !$this->socket->isConnected()) {
            throw new \RuntimeException('RelayClient: send on disconnected socket');
        }
        $this->socket->sendText($json);
    }

    /**
     * Read a single inbound frame from the WebSocket and dispatch it.
     *
     * Returns silently on a read timeout (no frame within $this->readTimeout
     * seconds — the run loop will call again). Throws on socket errors so
     * the run loop can trigger reconnect.
     */
    public function readOnce(): void
    {
        if ($this->socket === null || !$this->socket->isConnected()) {
            throw new \RuntimeException('RelayClient: read on disconnected socket');
        }
        $payload = $this->socket->receive($this->readTimeout);
        if ($payload === null) {
            return;
        }
        if ($payload === WebSocket::CLOSE_FRAME) {
            $this->logger->info('RelayClient: peer closed connection');
            $this->connected = false;
            throw new \RuntimeException('RelayClient: peer closed');
        }
        $this->handleMessage($payload);
    }

    /**
     * Send an acknowledgement (empty result) for a server-initiated request.
     *
     * Best-effort: an ACK that fails because the socket dropped during
     * the request handling is not propagated as an exception (the run
     * loop will already trigger reconnect on the next read).
     */
    public function sendAck(string $id): void
    {
        try {
            $this->send([
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => new \stdClass(),
            ]);
        } catch (\RuntimeException $e) {
            $this->logger->debug('sendAck failed: ' . $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  Inbound message handling
    // ══════════════════════════════════════════════════════════════════

    /**
     * Parse a raw JSON string from the server and route it.
     */
    public function handleMessage(string $raw): void
    {
        $this->logger->debug("<< {$raw}");

        $data = json_decode($raw, true);
        if ($data === null) {
            $this->logger->warn('Received unparseable message');
            return;
        }

        // ── response to a pending request ────────────────────────────
        $id = $data['id'] ?? null;
        if ($id !== null && isset($this->pending[$id])) {
            if (isset($data['error'])) {
                ($this->pending[$id]['reject'])($data['error']);
            } else {
                ($this->pending[$id]['resolve'])($data['result'] ?? []);
            }
            return;
        }

        // ── server-initiated request (event / ping / disconnect) ─────
        $method = $data['method'] ?? null;

        if ($method === 'signalwire.ping') {
            $this->sendAck($id ?? '');
            return;
        }

        if ($method === 'signalwire.disconnect') {
            $this->handleDisconnect($data['params'] ?? []);
            return;
        }

        if ($method === 'signalwire.event') {
            $this->sendAck($id ?? '');
            $outerParams = $data['params'] ?? [];
            $this->handleEvent($outerParams);
            return;
        }

        $this->logger->debug("Unhandled method: {$method}");
    }

    /**
     * Route a signalwire.event payload to the appropriate handler.
     */
    public function handleEvent(array $outerParams): void
    {
        $eventType = $outerParams['event_type'] ?? '';
        $params    = $outerParams['params']     ?? [];

        $event = new Event($eventType, $params);

        // ── authorization state ──────────────────────────────────────
        if ($eventType === 'signalwire.authorization.state') {
            $this->authorizationState = $params['authorization_state'] ?? null;
            $this->logger->info("Authorization state: {$this->authorizationState}");
            return;
        }

        // ── inbound call ─────────────────────────────────────────────
        if ($eventType === 'calling.call.receive') {
            $this->handleInboundCall($event, $params);
            return;
        }

        // ── inbound message ──────────────────────────────────────────
        if ($eventType === 'messaging.receive') {
            // Materialize a Message from the event params (mirrors the
            // Python ``messaging.receive`` flow that hands a Message into
            // the handler, not a bare params dict).
            $msg = new Message(array_merge($params, [
                'direction' => $params['direction'] ?? 'inbound',
                'state'     => $params['message_state'] ?? $params['state'] ?? Constants::MESSAGE_STATE_RECEIVED,
            ]));
            $msgId = $msg->getMessageId();
            if ($msgId !== null) {
                $this->messages[$msgId] = $msg;
            }
            if ($this->onMessageHandler !== null) {
                ($this->onMessageHandler)($msg, $event);
            }
            return;
        }

        // ── message state updates ────────────────────────────────────
        if ($eventType === 'messaging.state') {
            $msgId = $params['message_id'] ?? null;
            if ($msgId !== null && isset($this->messages[$msgId])) {
                $this->messages[$msgId]->handleEvent($event);
                $msgState = $params['state'] ?? $params['message_state'] ?? '';
                if (isset(Constants::MESSAGE_TERMINAL_STATES[$msgState])) {
                    unset($this->messages[$msgId]);
                }
            }
            return;
        }

        // ── call state with a pending dial tag ───────────────────────
        if ($eventType === 'calling.call.state') {
            $tag = $params['tag'] ?? null;

            // If this tag matches a pending dial, create/track the call
            if ($tag !== null && isset($this->pendingDials[$tag])) {
                $callId = $params['call_id'] ?? null;
                if ($callId !== null && !isset($this->calls[$callId])) {
                    $call = new Call($params, $this);
                    $this->calls[$callId] = $call;
                }
            }
        }

        // ── dial completion event ────────────────────────────────────
        if ($eventType === 'calling.call.dial') {
            $this->handleDialEvent($event, $params);
            return;
        }

        // ── default: route to the Call by call_id ────────────────────
        $callId = $params['call_id'] ?? $event->getCallId();
        if ($callId !== null && isset($this->calls[$callId])) {
            $call = $this->calls[$callId];
            $call->dispatchEvent($event);

            // Clean up ended calls
            if ($call->state === Constants::CALL_STATE_ENDED) {
                unset($this->calls[$callId]);
            }
            return;
        }

        // Fire generic event handler if nothing else matched.
        if ($this->onEventHandler !== null) {
            ($this->onEventHandler)($event, $outerParams);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  Public API methods
    // ══════════════════════════════════════════════════════════════════

    /**
     * Originate an outbound call, blocking until ``calling.call.dial``
     * resolves with a winner or fails.
     *
     * @param array<int,array<int,array<string,mixed>>> $devices
     *   Two-dimensional array of devices: each outer entry is one parallel
     *   "leg" of the dial, each inner entry is a serial step within that
     *   leg. Mirrors the Python ``[[device, device], [device]]`` shape.
     * @param array<string,mixed> $opts
     *   - ``tag``: explicit dial tag (UUID4 generated when omitted)
     *   - ``dial_timeout``: seconds to wait for the dial event (default 30.0)
     *   - ``max_duration``: max call lifetime in seconds, passed through
     *     to ``calling.dial``.
     *   - any other key is forwarded as a top-level param on the wire.
     *
     * @throws RelayError on dial failure or timeout.
     */
    public function dial(array $devices, array $opts = []): Call
    {
        $tag = $opts['tag'] ?? $this->generateUuid();
        $dialTimeout = (float) ($opts['dial_timeout'] ?? 30.0);

        // Strip control keys from opts before passing the rest as wire
        // params alongside ``devices`` and ``tag``.
        $extra = $opts;
        unset($extra['tag'], $extra['dial_timeout']);

        $resolved = false;
        $resolvedCall = null;
        $resolvedFailure = null;

        $this->pendingDials[$tag] = [
            'resolve' => function (?Call $c, ?string $failure) use (&$resolved, &$resolvedCall, &$resolvedFailure) {
                if ($resolved) {
                    return;
                }
                $resolved = true;
                $resolvedCall = $c;
                $resolvedFailure = $failure;
            },
            'tag' => $tag,
        ];

        $rpcParams = array_merge($extra, ['tag' => $tag, 'devices' => $devices]);

        try {
            $this->execute('calling.dial', $rpcParams);
        } catch (\Throwable $e) {
            unset($this->pendingDials[$tag]);
            throw $e;
        }

        // After the response lands, we still have to wait for the
        // ``calling.call.dial`` event (which carries the winner's call_id).
        $deadline = microtime(true) + $dialTimeout;
        while (!$resolved && microtime(true) < $deadline) {
            try {
                $this->readOnce();
            } catch (\RuntimeException $e) {
                unset($this->pendingDials[$tag]);
                throw new RelayError('Dial transport error: ' . $e->getMessage(), 0);
            }
        }
        unset($this->pendingDials[$tag]);

        if (!$resolved) {
            throw new RelayError("Dial timed out waiting for calling.call.dial event (tag={$tag})", 408);
        }

        if ($resolvedFailure !== null) {
            throw new RelayError("Dial failed: {$resolvedFailure}", 0);
        }

        if ($resolvedCall === null) {
            throw new RelayError('Dial failed: no winner call_id received', 0);
        }

        return $resolvedCall;
    }

    /**
     * Send an outbound message.
     *
     * @param array<string,mixed> $params Outbound messaging.send params
     *   (``to_number``, ``from_number``, ``body``, ``media``, ``tags``,
     *   ``context``).
     * @return Message  Tracking object for the message lifecycle. The
     *   Message starts in ``queued`` state; subsequent ``messaging.state``
     *   events from the server progress it through ``sent`` /
     *   ``delivered`` / ``undelivered`` / ``failed``.
     */
    public function sendMessage(array $params): Message
    {
        // Production RELAY requires a ``context`` on the wire. The Python
        // SDK defaults it to the negotiated protocol string when the
        // caller doesn't pass one (see signalwire.relay.client.send_message).
        if (!isset($params['context']) || $params['context'] === '') {
            $params['context'] = $this->protocol ?? 'default';
        }

        $result = $this->execute('messaging.send', $params);

        $messageId = $result['message_id'] ?? $this->generateUuid();

        // Materialize the Message with the request params *and* the
        // server-issued message_id, in the ``queued`` initial state. The
        // server dispatches subsequent ``messaging.state`` events keyed
        // by message_id; the Client's event router routes them here.
        $messageParams = array_merge($params, [
            'message_id' => $messageId,
            'direction'  => 'outbound',
            'state'      => Constants::MESSAGE_STATE_QUEUED,
        ]);
        $message = new Message($messageParams);
        $this->messages[$messageId] = $message;

        return $message;
    }

    /**
     * Subscribe to one or more inbound contexts so that events for those
     * contexts are delivered to this client.
     */
    public function receive(array $contexts): void
    {
        foreach ($contexts as $ctx) {
            if (!in_array($ctx, $this->contexts, true)) {
                $this->contexts[] = $ctx;
            }
        }

        $this->execute('signalwire.receive', [
            'contexts' => $contexts,
        ]);

        $this->logger->info('Subscribed to contexts: ' . implode(', ', $contexts));
    }

    /**
     * Unsubscribe from one or more contexts.
     */
    public function unreceive(array $contexts): void
    {
        $this->contexts = array_values(array_filter(
            $this->contexts,
            fn(string $c) => !in_array($c, $contexts, true)
        ));

        $this->execute('signalwire.unreceive', [
            'contexts' => $contexts,
        ]);

        $this->logger->info('Unsubscribed from contexts: ' . implode(', ', $contexts));
    }

    /**
     * Register a handler for inbound calls.
     */
    public function onCall(callable $cb): self
    {
        $this->onCallHandler = $cb;
        return $this;
    }

    /**
     * Register a handler for inbound messages.
     */
    public function onMessage(callable $cb): self
    {
        $this->onMessageHandler = $cb;
        return $this;
    }

    // ── accessors ────────────────────────────────────────────────────

    public function getCall(string $callId): ?Call
    {
        return $this->calls[$callId] ?? null;
    }

    /**
     * @return array<string, Call>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    /**
     * @return array<string, Message>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    // ══════════════════════════════════════════════════════════════════
    //  Private helpers
    // ══════════════════════════════════════════════════════════════════

    /**
     * Generate a UUID v4.
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return sprintf(
            '%08s-%04s-%04s-%04s-%012s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10, 6))
        );
    }

    /**
     * Handle an inbound call event – create the Call and fire the user handler.
     */
    private function handleInboundCall(Event $event, array $params): void
    {
        $callId = $params['call_id'] ?? null;
        if ($callId === null) {
            $this->logger->warn('Inbound call event missing call_id');
            return;
        }

        // Production wire uses ``call_state`` on the receive frame; the
        // Call constructor already accepts both.
        $callParams = $params + ['direction' => 'inbound'];
        $call = new Call($callParams, $this);
        $this->calls[$callId] = $call;

        $this->logger->info("Inbound call {$callId}");

        if ($this->onCallHandler !== null) {
            try {
                ($this->onCallHandler)($call, $event);
            } catch (\Throwable $e) {
                // A raising handler must NOT take down the recv loop —
                // log and continue. (Mirrors Python's
                // ``test_handler_exception_does_not_crash_client``.)
                $this->logger->error('on_call handler raised: ' . $e->getMessage());
            }
        }
    }

    /**
     * Handle the calling.call.dial event that signals a dial outcome.
     *
     * Production wire shape: the dial event carries no top-level call_id;
     * the winner's call info lives inside ``params.call``. ``dial_state``
     * determines outcome (``answered``, ``failed``, ``no_answer``...).
     */
    private function handleDialEvent(Event $event, array $params): void
    {
        $tag       = $params['tag']        ?? null;
        $dialState = $params['dial_state'] ?? $params['state'] ?? null;
        $callBlob  = $params['call']       ?? null;
        $callId    = $params['call_id']    ?? (is_array($callBlob) ? ($callBlob['call_id'] ?? null) : null);

        if ($tag === null) {
            return;
        }

        // Failure path — surface to the pending dial caller.
        if ($dialState === 'failed' || $dialState === 'no_answer' || $dialState === 'busy') {
            if (isset($this->pendingDials[$tag])) {
                ($this->pendingDials[$tag]['resolve'])(null, $dialState);
            }
            return;
        }

        // Build (or look up) the Call object for the winner.
        $call = null;
        if ($callId !== null && isset($this->calls[$callId])) {
            $call = $this->calls[$callId];
        } elseif (is_array($callBlob)) {
            // Production shape: nested params.call has call_id/node_id/tag/device.
            $call = new Call($callBlob, $this);
            if ($call->callId !== null) {
                $this->calls[$call->callId] = $call;
            }
        } elseif ($callId !== null) {
            // Legacy / test fixture: top-level call_id alongside dial params.
            $call = new Call($params, $this);
            $this->calls[$callId] = $call;
        }

        if ($call !== null) {
            $call->dialWinner = true;
            // Persist tag/state/direction so callers can read them.
            if ($call->tag === null || $call->tag === '') {
                $call->tag = $tag;
            }
            $call->state = 'answered';
            // Outbound calls are constructed without a direction — set it.
            $call->direction = 'outbound';
        }

        if (isset($this->pendingDials[$tag])) {
            ($this->pendingDials[$tag]['resolve'])($call, null);
        }
    }

    /**
     * Handle a server-initiated disconnect.
     */
    private function handleDisconnect(array $params): void
    {
        $this->logger->warn('Server sent disconnect');
        $this->connected = false;

        if ($this->running) {
            $this->reconnect();
        }
    }
}
