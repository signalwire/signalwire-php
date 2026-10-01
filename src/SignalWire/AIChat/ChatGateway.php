<?php

declare(strict_types=1);

/*
 * Copyright (c) 2026 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

namespace SignalWire\AIChat;

use SignalWire\Logging\Logger;

/**
 * Server-side proxy that lets a browser chat without holding a token.
 *
 * A chat widget running in a page cannot hold a SignalWire API token: the
 * token carries the whole project, so putting it in JavaScript hands every
 * visitor the ability to run up turns, and every turn bills. So the widget
 * talks to a gateway you mount in your own app, which holds the credential
 * server-side and forwards on the widget's behalf:
 *
 *     browser --(publishable key)--> your app --(project:token)--> chat service
 *
 * The browser learns exactly two things: the gateway's URL and a publishable
 * key. Not the project, not the space, not the token, and not which agent
 * config runs -- the gateway injects `config_url` itself, so a key can only
 * ever reach the one script it was issued for.
 *
 * Mount it on an agent you already serve:
 *
 * ```php
 * use SignalWire\AIChat\ChatGateway;
 *
 * $gateway = new ChatGateway(
 *     configUrl: 'https://my-agent.example.com/swml',
 *     key: 'pk_live_...',                       // what the widget carries
 *     allowedOrigins: ['https://shop.example.com'],
 * );
 * $agent->mount($gateway->router(), prefix: '/chat');
 * ```
 *
 * ## What a stolen key gets you
 *
 * Nothing to read: `chat_log` is not exposed, and a conversation handle is
 * signed by the gateway, so ids cannot be guessed or enumerated. What it gets
 * you is the ability to *talk*, which costs the project money. That makes the
 * caps the primary control: `maxNewConversations` and `maxTurns` bound the bill
 * from both directions -- how many conversations can exist, and how long each
 * can run.
 *
 * The origin allowlist is a second layer: it stops a key pasted into someone
 * else's page, because a browser sends that page's origin and the gateway
 * refuses it. It does not stop anyone using curl. Treat it as leak
 * containment, not access control.
 *
 * ## What the browser may volunteer
 *
 * Exactly one field is forwarded rather than overwritten: `user_meta_data`,
 * the page context a widget collects about itself (url, title, referrer,
 * locale, viewport). It reaches the agent's config request as
 * `params.user_meta_data`, so a chat agent can tailor its greeting the way a
 * voice agent does from dial-time `userVariables`. The service reads it when
 * it *creates* a conversation (the `start` call, or whichever `chat`
 * auto-creates); metadata sent on later turns is accepted and unused.
 *
 * It is browser-authored, so treat it as a visitor's *claim* about themselves
 * and never as authority. The gateway bounds it ({@see self::MAX_USER_METADATA_BYTES})
 * and keeps it nested under its own key, so it cannot collide with the
 * conversation id or `config_url` the gateway owns.
 *
 * ## Size limits
 *
 * Every field above is sized by whoever holds the key, so each is bounded and
 * answered with `413` past its limit: the request body
 * ({@see self::MAX_REQUEST_BODY_BYTES}, 64 KiB, refused before it is parsed), a
 * chat message ({@see self::MAX_MESSAGE_BYTES}, 8 KiB of UTF-8, refused before
 * a conversation is minted or a turn charged) and `user_meta_data`
 * ({@see self::MAX_USER_METADATA_BYTES}, 8 KiB serialized).
 * {@see HandoffRouter} applies the same body and message limits to its routes.
 *
 * Counters live in this object. Behind several processes each holds its own,
 * so the effective cap multiplies by process count -- set them with that in
 * mind, or put a shared limiter in front.
 */
class ChatGateway
{
    /** Seconds a handle stays valid by default: a page refresh, not an overnight tab. */
    public const DEFAULT_HANDLE_TTL = 24 * 60 * 60;

    /**
     * The chat service's own default idle timeout, in seconds. The service owns
     * the behaviour; this is only what the gateway tells a browser to expect.
     */
    public const SERVICE_DEFAULT_CONVERSATION_TIMEOUT = 3600;

    /** New conversations per window, per gateway, by default. */
    public const DEFAULT_MAX_NEW_CONVERSATIONS = 60;

    /** Turns per conversation, ever, by default. */
    public const DEFAULT_MAX_TURNS = 200;

    /** Length of the new-conversation window, in seconds, by default. */
    public const DEFAULT_WINDOW_SECONDS = 60;

    /** The browser methods the gateway accepts. */
    public const ALLOWED_METHODS = ['start', 'chat', 'log', 'end'];

    /** Bound on the browser-volunteered `user_meta_data` bag, serialized. */
    public const MAX_USER_METADATA_BYTES = 8 * 1024;

    /** Bound on one typed message (a chat turn, or a handoff `/say`), UTF-8 encoded. */
    public const MAX_MESSAGE_BYTES = 8 * 1024;

    /** Bound on a whole request body, checked before it is parsed. */
    public const MAX_REQUEST_BODY_BYTES = 64 * 1024;

    /**
     * Roles a browser may see. `chat_log` returns the whole conversation as the
     * service holds it -- the substituted system prompt, tool calls, tool
     * results -- so the transcript is filtered to what the visitor already saw.
     */
    public const VISIBLE_ROLES = ['user', 'assistant'];

    /** Hosts that never need listing, so a local run works unconfigured. */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /** The agent this key may talk to, injected on every upstream call. */
    public readonly string $configUrl;

    /** The publishable key the browser presents. */
    public readonly string $key;

    /**
     * Origins permitted to call in, without trailing slashes.
     *
     * @var list<string>
     */
    public readonly array $allowedOrigins;

    /** Seconds a signed handle stays valid. */
    public readonly int $handleTtl;

    /** Idle seconds before the service ends a conversation, or null for its default. */
    public readonly ?int $conversationTimeout;

    /** Cap on conversations minted per window. */
    public readonly int $maxNewConversations;

    /** Cap on turns per conversation. */
    public readonly int $maxTurns;

    /** Length of the rolling window the new-conversation cap counts over, in seconds. */
    public readonly int $windowSeconds;

    private readonly AIChatClient $client;
    private readonly bool $ownsClient;
    private readonly string $secret;
    private readonly Logger $logger;

    /** @var list<float> Monotonic times of recent mints. */
    private array $mints = [];

    /** @var array<string, array{int, float}> Conversation id => [turns, last turn time]. */
    private array $turns = [];

    /**
     * Build a gateway that fronts one agent for browser traffic.
     *
     * @param string               $configUrl           SWML config the gateway always sends upstream. Required, and never
     *                                                  taken from the request body -- if a browser could name it, whoever
     *                                                  holds a key would pick which agent runs and which project pays.
     * @param string|null          $key                 Publishable key the browser presents. Safe to ship in a page: it
     *                                                  names no credential and the caps bound what it can cost. Falls back
     *                                                  to `SIGNALWIRE_CHAT_GATEWAY_KEY`, else one is generated -- only
     *                                                  useful for a process that also serves the page and can embed it.
     * @param iterable<string>     $allowedOrigins      Origins permitted to call in. localhost is always allowed so a local
     *                                                  run works without shipping open-by-default; every other origin must
     *                                                  be listed.
     * @param AIChatClient|null    $client              A client to reuse. Omit and the gateway builds (and owns) its own
     *                                                  from the ambient credentials.
     * @param string|null          $secret              HMAC key (raw bytes or text) for signing conversation handles.
     *                                                  Falls back to `SIGNALWIRE_CHAT_GATEWAY_SECRET`, else one is generated
     *                                                  per process -- handles then stop verifying across a restart or a
     *                                                  second worker, so set it in production.
     * @param int                  $handleTtl           Seconds a signed handle stays valid.
     * @param int|null             $conversationTimeout Idle seconds before the service ends a conversation, sent on every
     *                                                  create and reported back on `start` and `log` so a widget can warn
     *                                                  before it lapses. Null leaves the service default (3600).
     * @param int                  $maxNewConversations Cap on conversations minted per window -- the control that makes a
     *                                                  leaked key a bill rather than a breach.
     * @param int                  $maxTurns            Cap on turns per conversation.
     * @param int                  $windowSeconds       Length of the rolling window the new-conversation cap counts over.
     *
     * @throws \InvalidArgumentException If `$configUrl` is empty.
     */
    public function __construct(
        string $configUrl,
        ?string $key = null,
        iterable $allowedOrigins = [],
        ?AIChatClient $client = null,
        ?string $secret = null,
        int $handleTtl = self::DEFAULT_HANDLE_TTL,
        ?int $conversationTimeout = null,
        int $maxNewConversations = self::DEFAULT_MAX_NEW_CONVERSATIONS,
        int $maxTurns = self::DEFAULT_MAX_TURNS,
        int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
    ) {
        if ($configUrl === '') {
            throw new \InvalidArgumentException('configUrl is required -- it is what a key is scoped to.');
        }

        $this->configUrl = $configUrl;
        $envKey = getenv('SIGNALWIRE_CHAT_GATEWAY_KEY');
        $this->key = ($key !== null && $key !== '')
            ? $key
            : ((is_string($envKey) && $envKey !== '') ? $envKey : 'pk_' . self::b64(random_bytes(24)));

        $origins = [];
        foreach ($allowedOrigins as $origin) {
            $origins[] = rtrim($origin, '/');
        }
        $this->allowedOrigins = array_values(array_unique($origins));
        $this->handleTtl = $handleTtl;
        $this->conversationTimeout = $conversationTimeout;
        $this->maxNewConversations = $maxNewConversations;
        $this->maxTurns = $maxTurns;
        $this->windowSeconds = $windowSeconds;

        $this->client = $client ?? new AIChatClient();
        $this->ownsClient = $client === null;

        if ($secret === null) {
            $envSecret = getenv('SIGNALWIRE_CHAT_GATEWAY_SECRET');
            $secret = (is_string($envSecret) && $envSecret !== '') ? $envSecret : random_bytes(32);
        }
        $this->secret = $secret;
        $this->logger = Logger::getLogger('signalwire.ai_chat.gateway');
    }

    /**
     * Epoch SECONDS of the newest message, or null if nothing is dated.
     *
     * Bootstraps a browser's idle clock across a reload: without it a widget
     * restarts the clock at zero on load, so a tab closed for 55 minutes of a
     * 60-minute timeout would wait another full hour before warning while the
     * conversation actually dies in five.
     *
     * The service stamps messages in MICROseconds; this converts, because a
     * 1000x unit error here is silent -- it reads as "always fresh". Every role
     * counts, not just the visible ones: the service's idle clock runs off any
     * write.
     *
     * @param list<mixed>|null $messages A `chat_log` transcript.
     */
    public static function lastActivity(?array $messages): ?float
    {
        $newest = null;
        foreach ($messages ?? [] as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $ts = $msg['timestamp'] ?? null;
            if (is_int($ts) && $ts > 0 && ($newest === null || $ts > $newest)) {
                $newest = $ts;
            }
        }
        return $newest !== null ? $newest / 1_000_000 : null;
    }

    /**
     * Idle seconds a conversation actually gets.
     *
     * Falls back to the service's documented default when unset, so the number
     * handed to a browser is never null -- a widget cannot warn about a
     * deadline it was told nothing about.
     */
    public function effectiveTimeout(): int
    {
        return $this->conversationTimeout ?: self::SERVICE_DEFAULT_CONVERSATION_TIMEOUT;
    }

    /**
     * Release the upstream client, if this gateway owns it.
     *
     * A client passed in via `$client` belongs to the caller and is left open;
     * only a client the gateway built for itself is closed here.
     */
    public function close(): void
    {
        if ($this->ownsClient) {
            $this->client->close();
        }
    }

    // ── Handles ──────────────────────────────────────────────────────

    /**
     * Issue a signed handle for a conversation.
     *
     * The browser never names a conversation. If it did, a publishable key plus
     * a guessed id would be enough to continue someone else's chat; signing
     * means a caller can only present handles this gateway issued.
     *
     * @param string|null $conversationId The conversation the handle names; a
     *                                    fresh `chat-...` id when omitted.
     */
    public function mintHandle(?string $conversationId = null): string
    {
        $conversationId = ($conversationId !== null && $conversationId !== '')
            ? $conversationId
            : 'chat-' . self::b64(random_bytes(18));
        $expires = time() + $this->handleTtl;
        $payload = $conversationId . ':' . $expires;
        $sig = hash_hmac('sha256', $payload, $this->secret, true);
        return self::b64($payload) . '.' . self::b64($sig);
    }

    /**
     * Return the conversation id inside a handle, or throw.
     *
     * Signature first, expiry second, both before the id is trusted for
     * anything.
     *
     * @throws GatewayRejection 400 "malformed handle", 403 "invalid handle" or
     *                          403 "expired handle".
     */
    public function readHandle(string $handle): string
    {
        $dot = strpos($handle, '.');
        if ($dot === false) {
            throw new GatewayRejection(400, 'malformed handle');
        }
        $payload = self::unb64(substr($handle, 0, $dot));
        $given = self::unb64(substr($handle, $dot + 1));
        if ($payload === null || $given === null) {
            throw new GatewayRejection(400, 'malformed handle');
        }

        $expected = hash_hmac('sha256', $payload, $this->secret, true);
        if (!hash_equals($expected, $given)) {
            throw new GatewayRejection(403, 'invalid handle');
        }

        $colon = strrpos($payload, ':');
        $expires = $colon === false ? '' : substr($payload, $colon + 1);
        if ($colon === false || preg_match('/^-?\d+$/', $expires) !== 1) {
            throw new GatewayRejection(400, 'malformed handle');
        }
        if (microtime(true) > (int) $expires) {
            throw new GatewayRejection(403, 'expired handle');
        }
        return substr($payload, 0, $colon);
    }

    // ── Guards ───────────────────────────────────────────────────────

    /**
     * Allow localhost always; anything else must be listed.
     *
     * A missing `Origin` is allowed: browsers always send one for the
     * cross-origin POSTs this serves, so absence means a non-browser caller --
     * and refusing those would break server-side use without stopping an
     * attacker, who simply omits the header.
     *
     * @param string|null $origin The request's `Origin` header, or null when absent.
     *
     * @throws GatewayRejection 403 if the origin is neither local nor listed.
     */
    public function checkOrigin(?string $origin): void
    {
        if ($origin === null) {
            return;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';
        if (in_array($host, self::LOCAL_HOSTS, true) || str_ends_with($host, '.localhost')) {
            return;
        }
        if (in_array(rtrim($origin, '/'), $this->allowedOrigins, true)) {
            return;
        }
        throw new GatewayRejection(403, 'origin not allowed');
    }

    /**
     * Verify the publishable key the browser sent.
     *
     * Compared with `hash_equals` rather than `===` so the check does not leak
     * the key a character at a time through timing.
     *
     * @param string|null $presented Key from the request, or null when the header is absent.
     *
     * @throws GatewayRejection 401 if the key is missing or does not match.
     */
    public function checkKey(?string $presented): void
    {
        if ($presented === null || $presented === '' || !hash_equals($this->key, $presented)) {
            throw new GatewayRejection(401, 'bad key');
        }
    }

    /**
     * The transcript a browser may redraw, and nothing else.
     *
     * `chat_log` hands back the conversation as the service holds it: the
     * substituted system prompt first, then tool calls and their results
     * alongside the dialogue. Relaying that verbatim would publish the
     * developer's prompt to anyone holding a handle. Only user and assistant
     * turns with actual text survive, reduced to role, content and when they
     * were said (epoch SECONDS; the service stores microseconds).
     *
     * @param list<mixed>|null $messages A `chat_log` transcript.
     *
     * @return list<array<string, mixed>>
     */
    public static function visibleMessages(?array $messages): array
    {
        $out = [];
        foreach ($messages ?? [] as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role = $msg['role'] ?? null;
            $content = $msg['content'] ?? null;
            if (in_array($role, self::VISIBLE_ROLES, true) && is_string($content) && !self::isBlank($content)) {
                $entry = ['role' => $role, 'content' => $content];
                $ts = $msg['timestamp'] ?? null;
                if (is_int($ts) && $ts > 0) {
                    $entry['timestamp'] = $ts / 1_000_000;
                }
                $out[] = $entry;
            }
        }
        return $out;
    }

    // ── The proxied call ─────────────────────────────────────────────

    /**
     * Validate the page context a browser volunteered, or null.
     *
     * The only field the gateway forwards instead of overwriting, so it is the
     * only one that needs a shape and a size. Absent, null and empty all
     * collapse to null -- an empty bag upstream is noise, not information.
     *
     * @param array<string, mixed> $body The decoded request body.
     *
     * @return array<string, mixed>|null
     *
     * @throws GatewayRejection 400 if it is not a JSON object (or not
     *                          serializable), 413 if it exceeds
     *                          {@see self::MAX_USER_METADATA_BYTES} serialized.
     */
    public function readUserMetadata(array $body): ?array
    {
        $raw = $body['user_meta_data'] ?? null;
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new GatewayRejection(400, 'user_meta_data must be an object');
        }
        if ($raw === []) {
            return null;
        }
        $encoded = json_encode($raw, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new GatewayRejection(400, 'user_meta_data must be JSON-serializable');
        }
        if (strlen($encoded) > self::MAX_USER_METADATA_BYTES) {
            throw new GatewayRejection(413, 'user_meta_data too large');
        }
        return GatewayHttp::stringKeyed($raw);
    }

    /**
     * Validate a browser request and build the upstream JSON-RPC call.
     *
     * Returns `[method, params, mintedHandle]` -- `mintedHandle` is set only
     * on the call that created the conversation, so the caller can hand it
     * back.
     *
     * Everything the browser could use to widen its own access is either
     * rejected or overwritten here: the method must be one of four, the
     * conversation comes from a signed handle, and `config_url` is ours. The
     * single exception is `user_meta_data`, which is forwarded -- see
     * {@see self::readUserMetadata()}.
     *
     * A chat message over {@see self::MAX_MESSAGE_BYTES} (UTF-8) is refused
     * with 413 before a conversation is minted or a turn charged.
     *
     * @param array<string, mixed> $body   The decoded request body.
     * @param string|null          $origin The request's `Origin` header, or null.
     * @param string|null          $key    The presented publishable key, or null.
     *
     * @return array{string, array<string, mixed>, string|null}
     *
     * @throws GatewayRejection When the request is refused.
     */
    public function prepare(array $body, ?string $origin, ?string $key): array
    {
        $this->checkKey($key);
        $this->checkOrigin($origin);

        $method = array_key_exists('method', $body) ? $body['method'] : 'chat';
        if (!is_string($method) || !in_array($method, self::ALLOWED_METHODS, true)) {
            throw new GatewayRejection(400, 'method not allowed');
        }

        // Read before minting so a malformed bag costs the caller nothing.
        $userMetadata = $this->readUserMetadata($body);

        // The message size is checked before minting for the same reason.
        $message = $body['message'] ?? null;
        if ($method === 'chat' && is_string($message) && strlen($message) > self::MAX_MESSAGE_BYTES) {
            throw new GatewayRejection(413, 'message too large');
        }

        $handle = $body['handle'] ?? null;
        $minted = null;
        if (self::truthy($handle)) {
            if (!is_string($handle)) {
                throw new GatewayRejection(400, 'malformed handle');
            }
            $conversationId = $this->readHandle($handle);
        } elseif ($method === 'end' || $method === 'log') {
            throw new GatewayRejection(400, $method . ' requires a handle');
        } else {
            $this->chargeMint();
            $minted = $this->mintHandle();
            $conversationId = $this->readHandle($minted);
        }

        if ($method === 'end') {
            return ['end_conversation', ['id' => $conversationId], null];
        }

        if ($method === 'log') {
            // Scoped to the conversation named INSIDE the signed handle, never
            // to anything the caller sent.
            return ['chat_log', ['id' => $conversationId], null];
        }

        if ($method === 'start') {
            // Opens the conversation with no user message, so the agent speaks first.
            $params = ['id' => $conversationId, 'config_url' => $this->configUrl];
            if ($this->conversationTimeout) {
                $params['conversation_timeout'] = $this->conversationTimeout;
            }
            if ($userMetadata) {
                $params['user_meta_data'] = $userMetadata;
            }
            return ['create_conversation', $params, $minted];
        }

        if (!is_string($message) || self::isBlank($message)) {
            throw new GatewayRejection(400, 'message is required');
        }

        $this->chargeTurn($conversationId);
        // config_url on every chat, so the service auto-creates on the first one
        // and ignores it after; that create takes the same timeout and is the
        // only moment the service reads the metadata bag.
        $chatParams = ['id' => $conversationId, 'message' => $message, 'config_url' => $this->configUrl];
        if ($this->conversationTimeout) {
            $chatParams['conversation_timeout'] = $this->conversationTimeout;
        }
        if ($userMetadata) {
            $chatParams['user_meta_data'] = $userMetadata;
        }
        return ['chat', $chatParams, $minted];
    }

    // ── HTTP surface ─────────────────────────────────────────────────

    /**
     * A request handler serving this gateway at its mount root.
     *
     * Mount it with `$agent->mount($gateway->router(), prefix: '/chat')`. The
     * handler is a callable
     * `(string $method, string $path, array $headers, ?string $body): array{int, array<string, string>, string}`.
     *
     * `POST /` takes `{"method": "start"|"chat"|"log"|"end", "handle"?, "message"?, "user_meta_data"?}`
     * with the key in `Authorization: Bearer`. `start` returns
     * `{greeting, status, timeout}`, `log` returns
     * `{messages, timeout, last_activity}` with only the visible dialogue, and
     * `end` returns `{status: "ended"}`. A chat relays the service's JSON-RPC
     * response body byte for byte, keepalive padding included, and a JSON-RPC
     * error inside it is the browser's to read. A newly minted handle rides
     * back in the `X-Chat-Handle` header. `OPTIONS /` answers CORS preflight.
     *
     * A refusal returns `{"error": reason}` with the rejection's status; a
     * failure reaching the chat service returns 502
     * `{"error": "chat service error"}`; CORS headers ride both. A request body
     * over {@see self::MAX_REQUEST_BODY_BYTES} is answered with 413 without
     * being parsed.
     *
     * @return \Closure(string, string, array<string, string>, ?string): array{int, array<string, string>, string}
     */
    public function router(): \Closure
    {
        return function (string $method, string $path, array $headers, ?string $body = null): array {
            if (GatewayHttp::routePath($path) !== '') {
                return GatewayHttp::json(404, ['error' => 'not found']);
            }
            $origin = GatewayHttp::header($headers, 'Origin');
            $method = strtoupper($method);
            if ($method === 'OPTIONS') {
                return $this->preflight($origin);
            }
            if ($method !== 'POST') {
                return GatewayHttp::json(405, ['error' => 'method not allowed'], ['Allow' => 'POST, OPTIONS']);
            }
            return $this->proxy($origin, $headers, $body);
        };
    }

    /**
     * CORS headers for an allowed origin, and none otherwise.
     *
     * @return array<string, string>
     */
    private function cors(?string $origin): array
    {
        if ($origin === null) {
            return [];
        }
        try {
            $this->checkOrigin($origin);
        } catch (GatewayRejection) {
            return [];
        }
        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Expose-Headers' => 'X-Chat-Handle',
            'Vary' => 'Origin',
        ];
    }

    /**
     * Answer a CORS preflight: 204, with allow headers only for an allowed origin.
     *
     * @return array{int, array<string, string>, string}
     */
    private function preflight(?string $origin): array
    {
        $headers = $this->cors($origin);
        if ($headers !== []) {
            $headers['Access-Control-Allow-Headers'] = 'Authorization, Content-Type';
            $headers['Access-Control-Allow-Methods'] = 'POST, OPTIONS';
            $headers['Access-Control-Max-Age'] = '600';
        }
        return [204, $headers, ''];
    }

    /**
     * Validate a browser request and forward it to the chat service.
     *
     * @param array<string, string> $headers
     *
     * @return array{int, array<string, string>, string}
     */
    private function proxy(?string $origin, array $headers, ?string $body): array
    {
        $auth = GatewayHttp::header($headers, 'Authorization') ?? '';
        $key = str_starts_with(strtolower($auth), 'bearer ') ? substr($auth, 7) : null;
        $cors = $this->cors($origin);

        try {
            $decoded = GatewayHttp::readJsonBody($body, $headers);
            if (!is_array($decoded) || !GatewayHttp::isJsonObject((string) $body)) {
                throw new GatewayRejection(400, 'body must be an object');
            }
            [$method, $params, $minted] = $this->prepare(GatewayHttp::stringKeyed($decoded), origin: $origin, key: $key);
        } catch (GatewayRejection $rej) {
            return GatewayHttp::json($rej->status, ['error' => $rej->reason], $cors);
        } catch (\Throwable) {
            return GatewayHttp::json(400, ['error' => 'bad request'], $cors);
        }

        try {
            return $this->forward($method, $params, $minted, $cors);
        } catch (\Throwable $err) {
            // Only the error's type is logged: the message may echo the request.
            $this->logger->error('chat_gateway_upstream_failed method=' . $method . ' error_type=' . $err::class);
            return GatewayHttp::json(502, ['error' => 'chat service error'], $cors);
        }
    }

    /**
     * Send a prepared call to the chat service and build the browser's answer.
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $cors
     *
     * @return array{int, array<string, string>, string}
     */
    private function forward(string $method, array $params, ?string $minted, array $cors): array
    {
        $id = is_string($params['id'] ?? null) ? $params['id'] : '';
        $headers = $cors;
        if ($minted !== null) {
            $headers['X-Chat-Handle'] = $minted;
        }

        if ($method === 'end_conversation') {
            $this->client->end($id);
            return GatewayHttp::json(200, ['status' => 'ended'], $cors);
        }

        if ($method === 'create_conversation') {
            // prepare() decides whether a timeout applies; it has to reach the
            // service, or the browser is told one number while the service
            // keeps another.
            $timeout = $params['conversation_timeout'] ?? null;
            $metadata = $params['user_meta_data'] ?? null;
            $info = $this->client->createConversation(
                $id,
                configUrl: $this->configUrl,
                timeout: is_int($timeout) ? $timeout : null,
                userMetadata: is_array($metadata) ? GatewayHttp::stringKeyed($metadata) : null,
            );
            return GatewayHttp::json(200, [
                'greeting' => $info->initialMessage,
                'status' => $info->status,
                'timeout' => $this->effectiveTimeout(),
            ], $headers);
        }

        if ($method === 'chat_log') {
            $log = $this->client->log($id);
            return GatewayHttp::json(200, [
                'messages' => self::visibleMessages($log->messages),
                'timeout' => $this->effectiveTimeout(),
                // Computed from the raw transcript: the only place every
                // role's timestamp still exists.
                'last_activity' => self::lastActivity($log->messages),
            ], $cors);
        }

        // A chat relays the service's body as it arrives, keepalive padding
        // and all, rather than decoding and re-encoding it.
        $relayed = '';
        foreach ($this->client->rawPost($method, $params) as $chunk) {
            $relayed .= $chunk;
        }
        $headers['Content-Type'] = 'application/json';
        return [200, $headers, $relayed];
    }

    // ── Internals ────────────────────────────────────────────────────

    /**
     * Count a new conversation against the window limit, or reject with 429.
     *
     * @throws GatewayRejection 429 when the window is full.
     */
    private function chargeMint(): void
    {
        $now = self::monotonic();
        $cutoff = $now - $this->windowSeconds;
        $this->mints = array_values(array_filter($this->mints, static fn (float $t): bool => $t > $cutoff));
        if (count($this->mints) >= $this->maxNewConversations) {
            throw new GatewayRejection(429, 'too many new conversations');
        }
        $this->mints[] = $now;
    }

    /**
     * Count a turn against the conversation's limit, or reject it with 429.
     *
     * @throws GatewayRejection 429 when the conversation is out of turns.
     */
    private function chargeTurn(string $conversationId): void
    {
        $now = self::monotonic();
        // Swept here rather than on a timer: a handle cannot outlive its TTL,
        // so anything older can never be charged against again.
        $cutoff = $now - $this->handleTtl;
        $this->turns = array_filter($this->turns, static fn (array $v): bool => $v[1] > $cutoff);
        $count = $this->turns[$conversationId][0] ?? 0;
        if ($count >= $this->maxTurns) {
            throw new GatewayRejection(429, 'conversation turn limit reached');
        }
        $this->turns[$conversationId] = [$count + 1, $now];
    }

    /** Seconds on a monotonic clock. */
    private static function monotonic(): float
    {
        return hrtime(true) / 1e9;
    }

    /** True when the text is empty or only whitespace (Unicode-aware). */
    private static function isBlank(string $text): bool
    {
        return preg_match('/\A[\s\p{Z}]*\z/u', $text) === 1 || trim($text) === '';
    }

    /** Truthiness of a decoded JSON value: null, false, empty string, zero and an empty array are false. */
    private static function truthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0 || $value === []);
    }

    /** Encode bytes as unpadded URL-safe base64. */
    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Decode unpadded URL-safe base64, or null when it isn't valid. */
    private static function unb64(string $text): ?string
    {
        $padded = $text . str_repeat('=', (4 - strlen($text) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
