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
            'version' => Constants::PROTOCOL_VERSION,
            'agent' => $this->agent,
            'event_acks' => true,
            // Top-level project/token for fixtures / older RELAY versions.
            'project' => $this->project,
            'token'   => $this->token,
            'authentication' => [
                'project' => $this->project,
                'token'   => $this->token,
            ],
        ];
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
            throw new \RuntimeException($message, $code);
        }

        if ($result === null) {
            throw new \RuntimeException(
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
            if ($this->onMessageHandler !== null) {
                ($this->onMessageHandler)($event, $params);
            }
            return;
        }

        // ── message state updates ────────────────────────────────────
        if ($eventType === 'messaging.state') {
            $msgId = $params['message_id'] ?? null;
            if ($msgId !== null && isset($this->messages[$msgId])) {
                $this->messages[$msgId]->handleEvent($event);
                $msgState = $params['state'] ?? '';
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
     * Originate an outbound call, blocking until the dial resolves.
     *
     * @return Call  The established call object.
     * @throws \RuntimeException on dial failure
     */
    public function dial(array $params): Call
    {
        $tag = $this->generateUuid();

        $call = null;

        $this->pendingDials[$tag] = [
            'resolve' => function (Call $c) use (&$call) { $call = $c; },
            'tag'     => $tag,
        ];

        $rpcParams = array_merge($params, ['tag' => $tag]);
        $this->execute('calling.dial', $rpcParams);

        // In sync mode the execute() loop will have already dispatched
        // the dial event, so $call should be populated.
        unset($this->pendingDials[$tag]);

        if ($call === null) {
            // Fallback: look up by tag in our calls map.
            foreach ($this->calls as $c) {
                if ($c->tag === $tag) {
                    $call = $c;
                    break;
                }
            }
        }

        if ($call === null) {
            throw new \RuntimeException('Dial failed: no call object received');
        }

        return $call;
    }

    /**
     * Send an outbound message.
     *
     * @return Message  Tracking object for the message lifecycle.
     */
    public function sendMessage(array $params): Message
    {
        $result = $this->execute('messaging.send', $params);

        $messageId = $result['message_id'] ?? $this->generateUuid();

        $message = new Message($messageId, $params, $result);
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

        $call = new Call($params, $this);
        $this->calls[$callId] = $call;

        $this->logger->info("Inbound call {$callId}");

        if ($this->onCallHandler !== null) {
            ($this->onCallHandler)($call, $event);
        }
    }

    /**
     * Handle the calling.call.dial event that signals a dial outcome.
     */
    private function handleDialEvent(Event $event, array $params): void
    {
        $tag    = $params['tag']     ?? null;
        $callId = $params['call_id'] ?? null;
        $state  = $params['state']   ?? null;

        if ($tag === null) {
            return;
        }

        // Ensure we have a Call object.
        $call = null;
        if ($callId !== null && isset($this->calls[$callId])) {
            $call = $this->calls[$callId];
        } elseif ($callId !== null) {
            $call = new Call($params, $this);
            $this->calls[$callId] = $call;
        }

        // Resolve the pending dial promise.
        if (isset($this->pendingDials[$tag]) && $call !== null) {
            $call->dialWinner = true;
            ($this->pendingDials[$tag]['resolve'])($call);
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
