<?php

declare(strict_types=1);

/*
 * Copyright (c) 2026 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

namespace SignalWire\AIChat;

/**
 * Client for the SignalWire AI Chat service.
 *
 * Speaks the standard SignalWire front-door protocol: HTTP Basic
 * ``project:api_token`` with the space in the hostname —
 * ``POST https://{space}.signalwire.com/api/ai/chat`` — carrying a JSON-RPC 2.0
 * body whose params are pure payload (identity NEVER appears in the body; it
 * rides the Basic-auth header only).
 *
 * A {@see chat()} call awaits a full LLM round trip (seconds, not milliseconds).
 * The service streams keepalive whitespace ahead of a slow response body (proxy
 * read-timeout protection), so liveness is byte-driven rather than wall-clock:
 * there is no total-request timeout an idle-but-live turn could trip — only a
 * per-read idle timeout, mirroring the python reference's
 * ``aiohttp.ClientTimeout(total=None, connect=10, sock_read=60)``. cURL expresses
 * that as ``CURLOPT_LOW_SPEED_LIMIT`` + ``CURLOPT_LOW_SPEED_TIME`` (abort only
 * after N seconds below 1 byte/s), NOT ``CURLOPT_TIMEOUT`` (a wall-clock cap a
 * slow turn would trip). Leading keepalive whitespace is valid JSON, so the
 * buffered ``json_decode`` parse is unaffected.
 *
 * Mirrors the python reference ``signalwire.ai_chat.AIChatClient``.
 *
 * ```php
 * use SignalWire\AIChat\AIChatClient;
 *
 * $client = new AIChatClient(space: 'myspace'); // env supplies creds
 * $client->createConversation('conv-1', configUrl: $configUrl);
 * $reply = $client->chat('conv-1', 'hello');
 * echo $reply->text;
 * ```
 */
class AIChatClient
{
    /** Default endpoint path appended to a ``space``-derived base URL. */
    private const DEFAULT_PATH = '/api/ai/chat';

    /** Bounded connect timeout (seconds); mirrors the reference's ``connect=10``. */
    private const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * Idle read timeout (seconds): abort only after this many seconds of true
     * byte-silence (< 1 byte/s), NOT a total-turn cap. Mirrors the reference's
     * ``sock_read=60``. The service heartbeats keepalive whitespace roughly every
     * 10s, so a live-but-slow turn never trips it; a dead connection is severed.
     */
    private const DEFAULT_READ_IDLE_TIMEOUT_SECONDS = 60;

    /** JSON-RPC error code -> the typed error class it maps to. */
    private const ERROR_BY_CODE = [
        -32001 => ConversationNotFoundError::class,
        -32005 => RateLimitError::class,
        -32006 => RateLimitError::class,
        -32007 => ChatInProgressError::class,
        -32009 => AuthenticationError::class,
    ];

    /**
     * Fully-qualified endpoint URL every request is POSTed to.
     *
     * @var non-empty-string
     */
    public readonly string $url;

    private readonly string $projectId;
    private readonly string $token;
    private readonly string $authHeader;
    private readonly string $userAgent;
    private readonly int $readIdleTimeoutSeconds;
    private int $requestCounter = 0;

    /**
     * @param string|null $project                Project id (Basic-auth username). Falls back to ``SIGNALWIRE_PROJECT_ID``.
     * @param string|null $token                  API token (Basic-auth password). Falls back to ``SIGNALWIRE_API_TOKEN``.
     * @param string|null $space                  Space name; builds ``https://{space}.signalwire.com/api/ai/chat``. Falls back to ``SIGNALWIRE_SPACE``.
     * @param string|null $url                    Fully-qualified endpoint URL, used verbatim (highest precedence).
     * @param int|null    $readIdleTimeoutSeconds Idle read timeout in seconds (byte-silence, NOT total turn length). Default 60; ``0`` disables.
     *
     * @throws \InvalidArgumentException When no project is available, or no URL can be resolved.
     */
    public function __construct(
        ?string $project = null,
        ?string $token = null,
        ?string $space = null,
        ?string $url = null,
        ?int $readIdleTimeoutSeconds = null,
    ) {
        $this->projectId = $project ?? self::envString('SIGNALWIRE_PROJECT_ID');
        $this->token = $token ?? self::envString('SIGNALWIRE_API_TOKEN');
        $space = $space ?? self::envString('SIGNALWIRE_SPACE');

        if ($this->projectId === '') {
            throw new \InvalidArgumentException(
                'project is required. Provide it as an argument or set the '
                . 'SIGNALWIRE_PROJECT_ID environment variable.'
            );
        }

        $this->url = self::resolveUrl($url, $space);
        $this->authHeader = 'Basic ' . base64_encode($this->projectId . ':' . $this->token);
        $this->userAgent = 'signalwire-agents-php-ai-chat/' . \SignalWire\SignalWire::VERSION;
        $this->readIdleTimeoutSeconds = $readIdleTimeoutSeconds ?? self::DEFAULT_READ_IDLE_TIMEOUT_SECONDS;
    }

    /**
     * Release any client-held transport resources.
     *
     * Mirrors the python reference's ``AIChatClient.close()`` lifecycle member.
     * This client opens and closes a fresh cURL handle per request (there is no
     * pooled/persistent session to tear down), so ``close()`` is a well-defined
     * no-op that completes the lifecycle contract — calling it is always safe
     * and the client remains usable afterward. (Python closes its persistent
     * ``aiohttp.ClientSession`` here; PHP has nothing to release.)
     */
    public function close(): void
    {
    }

    /**
     * Resolve the endpoint URL, in order:
     *   1. an explicit ``url`` — used verbatim;
     *   2. ``RAILS_DEV_MODE`` env var when it holds a real URL (it doubles as the
     *      service's persona switch, so a plain boolean means "on" and does NOT
     *      carry a URL — only a URL-looking value overrides here);
     *   3. ``https://{space}.signalwire.com/api/ai/chat`` built from ``space``.
     *
     * @return non-empty-string
     *
     * @throws \InvalidArgumentException When none of the three resolves a target.
     */
    private static function resolveUrl(?string $url, string $space): string
    {
        if ($url !== null && $url !== '') {
            return $url;
        }
        $devUrl = trim(self::envString('RAILS_DEV_MODE'));
        $booleans = ['false', '0', 'no', 'off', 'true', '1', 'yes', 'on'];
        if ($devUrl !== '' && !in_array(strtolower($devUrl), $booleans, true)) {
            return $devUrl;
        }
        if ($space !== '') {
            return sprintf('https://%s.signalwire.com%s', $space, self::DEFAULT_PATH);
        }
        throw new \InvalidArgumentException(
            'No service URL: provide url=, set RAILS_DEV_MODE to a full URL, '
            . 'or provide space= / SIGNALWIRE_SPACE.'
        );
    }

    private static function envString(string $name): string
    {
        $val = getenv($name);
        return is_string($val) ? $val : '';
    }

    // -----------------------------------------------------------------
    // API methods
    // -----------------------------------------------------------------

    /**
     * Create a conversation (or, with ``reinit``, reinitialize an existing one)
     * and optionally send its opening user message.
     *
     * @param string                    $conversationId The conversation id to create.
     * @param string                    $configUrl      Config URL locating the agent config (required).
     * @param string|null               $userMessage    The opening user message to send with the create (wire ``user_message``).
     * @param int|null                  $timeout        Conversation inactivity timeout in seconds (wire ``conversation_timeout``).
     * @param array<string, mixed>|null $userMetadata   Arbitrary caller metadata (wire ``user_meta_data``).
     * @param bool                      $reinit         Reinitialize an existing conversation.
     *
     * @throws AIChatError On a service failure.
     */
    public function createConversation(
        string $conversationId,
        string $configUrl,
        ?string $userMessage = null,
        ?int $timeout = null,
        ?array $userMetadata = null,
        bool $reinit = false,
    ): ConversationInfo {
        $params = ['id' => $conversationId, 'config_url' => $configUrl];
        if ($userMessage !== null && $userMessage !== '') {
            $params['user_message'] = $userMessage;
        }
        if ($timeout !== null && $timeout !== 0) {
            $params['conversation_timeout'] = $timeout;
        }
        if ($userMetadata !== null && $userMetadata !== []) {
            $params['user_meta_data'] = $userMetadata;
        }
        if ($reinit) {
            $params['reinit'] = true;
        }

        $result = $this->request('create_conversation', $params);
        $status = $result['status'] ?? null;
        $initial = $result['initial_message'] ?? null;

        return new ConversationInfo(
            id: $conversationId,
            status: is_string($status) ? $status : 'created',
            initialMessage: is_string($initial) ? $initial : null,
        );
    }

    /**
     * Send a message and await a full LLM round trip.
     *
     * Passing ``configUrl`` auto-creates the conversation if it doesn't exist
     * yet; ``timeout`` and ``reinit`` apply to that auto-create, with the same
     * meaning as on {@see createConversation()}. Expect seconds — the turn awaits
     * the model.
     *
     * @param string                    $conversationId The conversation to send into.
     * @param string                    $message        The user (or system) message text.
     * @param string                    $role           Message role (``"user"`` or ``"system"``). Default ``"user"``.
     * @param string|null               $configUrl      Config URL that auto-creates the conversation if absent.
     * @param array<string, mixed>|null $userMetadata   Arbitrary caller metadata (wire ``user_meta_data``).
     * @param int|null                  $timeout        Conversation inactivity timeout in seconds (wire ``conversation_timeout``).
     * @param bool                      $reinit         Reinitialize an existing conversation.
     *
     * @throws AIChatError On a service failure.
     */
    public function chat(
        string $conversationId,
        string $message,
        string $role = 'user',
        ?string $configUrl = null,
        ?array $userMetadata = null,
        ?int $timeout = null,
        bool $reinit = false,
    ): ChatResponse {
        $params = ['id' => $conversationId, 'message' => $message, 'role' => $role];
        if ($configUrl !== null && $configUrl !== '') {
            $params['config_url'] = $configUrl;
        }
        if ($userMetadata !== null && $userMetadata !== []) {
            $params['user_meta_data'] = $userMetadata;
        }
        if ($timeout !== null && $timeout !== 0) {
            $params['conversation_timeout'] = $timeout;
        }
        if ($reinit) {
            $params['reinit'] = true;
        }

        $result = $this->request('chat', $params);
        $text = $result['response'] ?? null;
        $userEvent = $result['user_event'] ?? null;

        return new ChatResponse(
            text: is_string($text) ? $text : '',
            conversationId: $conversationId,
            userEvent: is_array($userEvent) ? self::asStringKeyed($userEvent) : null,
        );
    }

    /**
     * End a conversation (triggers server-side post-processing / archival).
     *
     * @param string $conversationId The conversation to end.
     *
     * @return bool ``true`` when the service reported the conversation ended.
     *
     * @throws AIChatError On a service failure.
     */
    public function end(string $conversationId): bool
    {
        $result = $this->request('end_conversation', ['id' => $conversationId]);
        return ($result['status'] ?? null) === 'ended';
    }

    /**
     * Permanently delete a conversation and its data. Idempotent.
     *
     * @param string $conversationId The conversation to delete.
     *
     * @return bool ``true`` when the service reported the conversation deleted.
     *
     * @throws AIChatError On a service failure.
     */
    public function delete(string $conversationId): bool
    {
        $result = $this->request('delete', ['id' => $conversationId]);
        return ($result['status'] ?? null) === 'deleted';
    }

    /**
     * Return the full message history plus the call timeline.
     *
     * @param string $conversationId The conversation to read.
     *
     * @throws AIChatError On a service failure.
     */
    public function log(string $conversationId): ChatLog
    {
        $result = $this->request('chat_log', ['id' => $conversationId]);
        $messages = $result['chat_log'] ?? [];
        $timeline = $result['call_timeline'] ?? [];

        return new ChatLog(
            messages: self::asRowList($messages),
            callTimeline: self::asRowList($timeline),
        );
    }

    /**
     * Return an AI summary of the conversation (rate limited server-side).
     *
     * The service returns EXACTLY ONE of ``{summary}`` or ``{error}`` — BOTH on
     * the success envelope — so a failed generation surfaces as a thrown
     * {@see SummaryError}, never as an empty string.
     *
     * @param string                    $conversationId The conversation to summarize.
     * @param string|null               $summaryPrompt  Custom prompt steering the summary (wire ``summary_prompt``).
     * @param array<string, mixed>      $sampling       Optional sampling params (``temperature``, ``top_p``, ``frequency_penalty``, ``presence_penalty``, ``max_tokens``); null values are dropped.
     *
     * @throws SummaryError When the service reports summary generation failed.
     * @throws AIChatError  On any other service failure.
     */
    public function summarize(
        string $conversationId,
        ?string $summaryPrompt = null,
        array $sampling = [],
    ): string {
        $params = ['id' => $conversationId];
        if ($summaryPrompt !== null && $summaryPrompt !== '') {
            $params['summary_prompt'] = $summaryPrompt;
        }
        foreach ($sampling as $key => $value) {
            if ($value !== null) {
                $params[$key] = $value;
            }
        }

        $result = $this->request('summarize', $params);
        if (array_key_exists('error', $result) && !array_key_exists('summary', $result)) {
            $err = $result['error'];
            throw new SummaryError(null, is_string($err) ? $err : self::stringify($err));
        }
        $summary = $result['summary'] ?? '';
        return is_string($summary) ? $summary : self::stringify($summary);
    }

    // -----------------------------------------------------------------
    // Wire
    // -----------------------------------------------------------------

    /**
     * POST one JSON-RPC call and return its decoded ``result`` object.
     *
     * Success/failure is decided by the JSON-RPC BODY, not the HTTP status: the
     * service's keepalive heartbeat commits ``200`` before the turn's outcome is
     * known, so a slow error can arrive as ``200 + {"error": …}``. This never
     * gates on the HTTP status (mirrors the python reference).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws AIChatError When the body carries ``error`` (typed subclass by code),
     *                     or the response is not JSON.
     */
    private function request(string $method, array $params): array
    {
        $this->requestCounter++;
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => (object) $params,
            'id' => 'req-' . $this->requestCounter,
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new AIChatError(null, 'failed to encode JSON-RPC request');
        }

        [$body, $status] = $this->post($encoded);

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new AIChatError($status, sprintf('non-JSON response (HTTP %d)', $status));
        }

        if (array_key_exists('error', $decoded) && $decoded['error'] !== null) {
            $error = is_array($decoded['error']) ? $decoded['error'] : [];
            $code = $error['code'] ?? null;
            $code = is_int($code) ? $code : null;
            $message = $error['message'] ?? '';
            $message = is_string($message) ? $message : self::stringify($message);
            $class = $code !== null ? (self::ERROR_BY_CODE[$code] ?? AIChatError::class) : AIChatError::class;
            throw new $class($code, $message);
        }

        $result = $decoded['result'] ?? null;
        return is_array($result) ? $result : [];
    }

    /**
     * Execute the POST and return ``[bodyText, httpStatus]``. Buffers the whole
     * body (leading keepalive whitespace is valid JSON); applies the byte-idle
     * read timeout, not a wall-clock total.
     *
     * @return array{0: string, 1: int}
     *
     * @throws AIChatError On a transport-level failure.
     */
    private function post(string $encoded): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new AIChatError(null, 'failed to initialize HTTP client');
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $this->authHeader,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: ' . $this->userAgent,
            ],
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            // Byte-idle read timeout (mirrors sock_read=60): abort only after
            // this many seconds under 1 byte/s. Deliberately NO CURLOPT_TIMEOUT
            // (a wall-clock cap a slow-but-live turn would trip). 0 disables.
            CURLOPT_LOW_SPEED_LIMIT => $this->readIdleTimeoutSeconds > 0 ? 1 : 0,
            CURLOPT_LOW_SPEED_TIME => $this->readIdleTimeoutSeconds > 0 ? $this->readIdleTimeoutSeconds : 0,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new AIChatError(null, sprintf('HTTP transport error: %s', $err));
        }
        /** @var int $status */
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [is_string($body) ? $body : '', $status];
    }

    /**
     * Normalize a decoded object to a string-keyed map. JSON object keys are
     * always strings, but ``json_decode(..., true)`` types them as ``array-key``;
     * this carries the true invariant forward for the typed response models.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function asStringKeyed(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    /**
     * Coerce a decoded value that should be a list of row-objects into
     * ``list<array<string, mixed>>``; non-array entries are dropped.
     *
     * @param mixed $value
     *
     * @return list<array<string, mixed>>
     */
    private static function asRowList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $out[] = self::asStringKeyed($row);
            }
        }
        return $out;
    }

    /**
     * Render an arbitrary decoded value as a string (for a non-string error /
     * summary payload), mirroring python's ``str(...)`` coercion.
     *
     * @param mixed $value
     */
    private static function stringify($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        $encoded = json_encode($value);
        return $encoded === false ? '' : $encoded;
    }
}
