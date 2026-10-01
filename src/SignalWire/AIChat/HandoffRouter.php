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
 * The three routes a browser client needs beside a {@see ChatGateway}: moving
 * one conversation between voice and text, and typing into a live call.
 *
 * The SignalWire address widget calls `{gateway-url}/handoff`,
 * `{gateway-url}/escalate` and `{gateway-url}/say` against the same URL that
 * points at a `ChatGateway`, and sends `handoff_nonce` and `chat_handle` as
 * user variables.
 *
 * ## Mechanism vs policy
 *
 * This class owns the wire contract only: the routes, the nonce, the ordering
 * guarantee and the spend guards. Where a leg's transcript gets written, what
 * a resumed greeting says, how much history to carry -- all of that is the
 * application's, injected as callbacks.
 *
 * ## The nonce
 *
 * A browser cannot be trusted to name a call: a page-supplied call id would
 * let anyone who learned or guessed one inject speech into a stranger's live
 * call. So the application puts a random `handoff_nonce` in the user variables
 * of one dial, registers it here against that call's ids, and the browser
 * presents it later. Knowing the nonce is proof of having placed the call.
 *
 * A nonce is registered once: the per-call config callback that registers it
 * runs on every SWML request for the call, so registering a nonce already in
 * the table changes nothing. Redemption for a handle is single use; a redeemed
 * nonce stays in the table, marked, until its TTL passes. Typing is repeatable,
 * bounded by `maxMessagesPerCall`, until the nonce is redeemed (which ends the
 * call) or `nonceTtl` seconds pass after it was registered -- so set the TTL to
 * cover the longest call. An unknown nonce is answered exactly like an expired
 * or redeemed one, so these routes cannot be used to probe whether a call is
 * live.
 *
 * ## The ordering guarantee
 *
 * A medium never starts until the one it replaces has finished and its record
 * is durable. `/handoff` ends the call and waits for the application to
 * confirm capture before minting a handle; `/escalate` captures the chat leg
 * before returning.
 *
 * ## Deployment
 *
 * The nonce table lives in this object, like `ChatGateway`'s rate-limit
 * counters. A redemption must reach the process that served the dial: run one
 * long-lived process, use sticky routing, or supply a shared `registry`. When
 * several routers share a registry, each step is a read followed by a write
 * another router can interleave, so a nonce can be redeemed once by each and
 * the typing cap can be passed; use one router per nonce table, or route each
 * call's requests to one process.
 *
 * ```php
 * use SignalWire\AIChat\HandoffRouter;
 *
 * $handoff = new HandoffRouter(
 *     gateway: $gateway,
 *     captureLeg: fn (string $conversationId, string $medium): bool => $records->flush($conversationId),
 *     endCall: fn (string $callId) => $calls->hangUp($callId),
 *     sendMessage: fn (string $callId, string $text): bool => $calls->inject($callId, $text),
 * );
 * $agent->mount($handoff->router(), prefix: '/chat');
 * ```
 *
 * Here `$records` and `$calls` stand for the application's own storage and
 * call-control code.
 * ```
 */
class HandoffRouter
{
    /** Seconds a nonce stays usable after its first registration, by default. */
    public const DEFAULT_NONCE_TTL = 3600;

    /** Ceiling on typed messages for one call, by default. */
    public const DEFAULT_MAX_MESSAGES_PER_CALL = 200;

    /** Seconds to wait for `captureLeg`, by default. */
    public const DEFAULT_CAPTURE_TIMEOUT = 8.0;

    /** The gateway that owns the conversations: mints handles and checks origins. */
    public readonly ChatGateway $gateway;

    /**
     * Called as `captureLeg(conversationId, medium)` to end a leg and write its
     * record; truthy only once that record is durable. Null skips the wait.
     *
     * @var (\Closure(string, string): bool)|null
     */
    public readonly ?\Closure $captureLeg;

    /**
     * Called as `endCall(callId)` to hang the call up server-side.
     *
     * @var (\Closure(string): void)|null
     */
    public readonly ?\Closure $endCall;

    /**
     * Called as `sendMessage(callId, text)` for `/say`; null disables typing.
     *
     * @var (\Closure(string, string): bool)|null
     */
    public readonly ?\Closure $sendMessage;

    /**
     * Called as `nextConversationId(conversationId)` to produce the id for the
     * new leg.
     *
     * @var \Closure(string): string
     */
    public readonly \Closure $nextConversationId;

    /** Seconds a nonce stays usable after its first registration. */
    public readonly int $nonceTtl;

    /** Ceiling on typed messages for one call. */
    public readonly int $maxMessagesPerCall;

    /** Seconds to wait for `captureLeg`. */
    public readonly float $captureTimeout;

    /** @var \ArrayAccess<string, NonceEntry>&\Traversable<string, NonceEntry> */
    private \ArrayAccess&\Traversable $nonces;

    private readonly Logger $logger;

    /**
     * Configure the handoff routes.
     *
     * @param ChatGateway                                     $gateway            The gateway that owns the conversations. Used
     *                                                                            to mint handles and to check origins, so both
     *                                                                            halves of the URL enforce the same origin policy.
     * @param (callable(string, string): mixed)|null          $captureLeg         Called as `captureLeg($conversationId, $medium)`
     *                                                                            to end a leg and write its record. Must return
     *                                                                            truthy only once that record is durable. When
     *                                                                            omitted, no wait happens and the ordering
     *                                                                            guarantee is not provided.
     * @param (callable(string): mixed)|null                  $endCall            Called as `endCall($callId)` to hang up server-side.
     * @param (callable(string, string): mixed)|null          $sendMessage        Called as `sendMessage($callId, $text)` for
     *                                                                            `/say`. Omit to leave typing disabled (the route
     *                                                                            then answers 404).
     * @param (callable(string): string)|null                 $nextConversationId Called as `nextConversationId($conversationId)`
     *                                                                            to produce the id for the NEW leg. Defaults to
     *                                                                            appending `.N`. A fresh id is required because an
     *                                                                            ended conversation cannot be reopened, and the
     *                                                                            separator must be `.`: the chat service strips
     *                                                                            `~`, `_` and `-` already occur inside generated
     *                                                                            ids, and `:` is the handle delimiter.
     * @param int                                             $nonceTtl           Seconds a nonce stays usable after its first
     *                                                                            registration, for `/handoff` and `/say` alike. A
     *                                                                            redeemed nonce is kept for the same time.
     * @param int                                             $maxMessagesPerCall Ceiling on typed messages for one call. Each is a
     *                                                                            billable turn, so this is a spend guard as much
     *                                                                            as an abuse one.
     * @param float                                           $captureTimeout     Seconds to wait for `captureLeg`: a ceiling, not a
     *                                                                            budget. The callback runs synchronously, so one
     *                                                                            that overruns is not interrupted; its result is
     *                                                                            discarded and the switch proceeds.
     * @param (\ArrayAccess<string, NonceEntry>&\Traversable<string, NonceEntry>)|null $registry Optional shared store for the
     *                                                                            nonce table. Supply one backed by shared storage
     *                                                                            to run more than one process. Every change is
     *                                                                            stored by assigning the entry back to its key.
     */
    public function __construct(
        ChatGateway $gateway,
        ?callable $captureLeg = null,
        ?callable $endCall = null,
        ?callable $sendMessage = null,
        ?callable $nextConversationId = null,
        int $nonceTtl = self::DEFAULT_NONCE_TTL,
        int $maxMessagesPerCall = self::DEFAULT_MAX_MESSAGES_PER_CALL,
        float $captureTimeout = self::DEFAULT_CAPTURE_TIMEOUT,
        (\ArrayAccess&\Traversable)|null $registry = null,
    ) {
        $this->gateway = $gateway;
        $this->captureLeg = $captureLeg === null
            ? null
            : static fn (string $conversationId, string $medium): bool => (bool) $captureLeg($conversationId, $medium);
        $this->endCall = $endCall === null
            ? null
            : static function (string $callId) use ($endCall): void {
                $endCall($callId);
            };
        $this->sendMessage = $sendMessage === null
            ? null
            : static fn (string $callId, string $text): bool => (bool) $sendMessage($callId, $text);
        $this->nextConversationId = $nextConversationId === null
            ? \Closure::fromCallable([self::class, 'defaultNextId'])
            : static fn (string $conversationId): string => $nextConversationId($conversationId);
        $this->nonceTtl = $nonceTtl;
        $this->maxMessagesPerCall = $maxMessagesPerCall;
        $this->captureTimeout = $captureTimeout;
        /** @var \ArrayAccess<string, NonceEntry>&\Traversable<string, NonceEntry> $table */
        $table = $registry ?? new \ArrayObject();
        $this->nonces = $table;
        $this->logger = Logger::getLogger('signalwire.ai_chat.handoff');
    }

    // ── Nonce lifecycle ──────────────────────────────────────────────

    /**
     * Record what a nonce is a capability for.
     *
     * Call this from the per-call config callback of the dial that carried the
     * nonce, reading `$callId` from the request the platform sent -- never
     * from anything the browser supplied.
     *
     * The first registration stands. That callback runs on every SWML request
     * for the call, and the browser chooses the nonce, so registering a nonce
     * already in the table changes nothing: its call, its registration time and
     * its typed-message count are kept, and a redeemed nonce stays redeemed. A
     * registration that names a different conversation or call, or a redeemed
     * nonce, is logged as a warning. Once the entry's `nonceTtl` has passed, the
     * nonce can be registered again.
     *
     * @param string      $nonce          The `handoff_nonce` from the dial's user variables.
     * @param string      $conversationId The conversation the call belongs to.
     * @param string|null $callId         The call's id, from the platform's request.
     */
    public function register(string $nonce, string $conversationId, ?string $callId = null): void
    {
        if ($nonce === '') {
            return;
        }
        $this->prune();
        $existing = $this->fetch($nonce);
        if ($existing === null) {
            // Re-checked at the write: the table may have gained this nonce
            // between the read and now (a shared registry, or a callback that
            // re-entered), and the first registration must stand.
            if (!isset($this->nonces[$nonce])) {
                $this->nonces[$nonce] = new NonceEntry($conversationId, $callId);
                $this->logger->info('handoff_nonce_registered conversation_id=' . $conversationId
                    . ' call_id=' . ($callId ?? 'null'));
                return;
            }
            $existing = $this->fetch($nonce);
            if ($existing === null) {
                return;
            }
        }
        if ($existing->redeemed || $existing->conversationId !== $conversationId || $existing->callId !== $callId) {
            $this->logger->warn('handoff_nonce_already_registered conversation_id=' . $existing->conversationId
                . ' call_id=' . ($existing->callId ?? 'null')
                . ' redeemed=' . ($existing->redeemed ? 'true' : 'false'));
        }
    }

    // ── Operations ───────────────────────────────────────────────────

    /**
     * Exchange a nonce for a chat handle. Single use.
     *
     * Ends the call, waits for its record, and only then mints a handle for a
     * new leg of the same conversation.
     *
     * @param string $nonce The nonce the browser presented.
     *
     * @return string|null The signed handle, or null for an unknown, expired or
     *                     already redeemed nonce -- deliberately
     *                     indistinguishable from each other.
     */
    public function redeem(string $nonce): ?string
    {
        $entry = $this->lookup($nonce);
        if ($entry === null) {
            return null;
        }
        // Consumed even if what follows fails: a nonce is one attempt. Written
        // back so a shared registry stores the change.
        $entry->redeemed = true;
        $this->nonces[$nonce] = $entry;

        if ($entry->callId !== null && $entry->callId !== '' && $this->endCall !== null) {
            try {
                ($this->endCall)($entry->callId);
            } catch (\Throwable $exc) {
                $this->logger->warn('handoff_end_call_failed error=' . $exc->getMessage());
            }
        }

        $this->capture($entry->conversationId, 'voice');

        try {
            $handle = $this->gateway->mintHandle(($this->nextConversationId)($entry->conversationId));
        } catch (\Throwable $exc) {
            $this->logger->error('handoff_mint_failed error=' . $exc->getMessage());
            return null;
        }

        $this->logger->info('handoff_redeemed conversation_id=' . $entry->conversationId);
        return $handle;
    }

    /**
     * End a chat leg and wait for its record, before a call is placed.
     *
     * The browser calls this and waits, so a voice leg started immediately
     * afterwards is guaranteed to find the text leg already recorded.
     *
     * @param string $handle The chat handle the browser holds.
     *
     * @return bool False when the handle doesn't verify.
     */
    public function escalate(string $handle): bool
    {
        try {
            $conversationId = $this->gateway->readHandle($handle);
        } catch (\Throwable) {
            return false;
        }
        $this->capture($conversationId, 'chat');
        $this->logger->info('handoff_escalated conversation_id=' . $conversationId);
        return true;
    }

    /**
     * Deliver typed text into the live call the nonce names.
     *
     * Does NOT consume the nonce: typing is repeatable until the nonce is
     * redeemed or its `nonceTtl` passes, up to `maxMessagesPerCall` messages.
     * Addressed by nonce rather than by any browser-supplied call id, and no
     * other request field is forwarded: `global_data` in particular is trusted
     * agent state that step logic branches on.
     *
     * Text over {@see ChatGateway::MAX_MESSAGE_BYTES} (UTF-8), the gateway's
     * limit for a chat message, is refused.
     *
     * @param string $nonce The nonce the browser presented.
     * @param string $text  The text to deliver; surrounding whitespace is trimmed.
     *
     * @return bool True once delivered.
     */
    public function say(string $nonce, string $text): bool
    {
        if ($this->sendMessage === null) {
            return false;
        }
        $cleaned = self::strip($text);
        if ($cleaned === '' || strlen($cleaned) > ChatGateway::MAX_MESSAGE_BYTES) {
            return false;
        }
        $entry = $this->lookup($nonce);
        if ($entry === null || $entry->callId === null || $entry->callId === '') {
            return false;
        }
        if ($entry->messages >= $this->maxMessagesPerCall) {
            $this->logger->warn('handoff_say_cap_reached call_id=' . $entry->callId);
            return false;
        }
        // Take the message's slot before delivering, so a request that arrives
        // while this one is delivering can't pass the cap. Written back so a
        // shared registry stores the change.
        $entry->messages++;
        $this->nonces[$nonce] = $entry;
        try {
            ($this->sendMessage)($entry->callId, $cleaned);
        } catch (\Throwable $exc) {
            $this->logger->error('handoff_say_failed error=' . $exc->getMessage());
            // Not delivered: give the slot back, if the table still holds this
            // registration. A shared registry may return a copy, so it's
            // matched by value, and the stored count is the one decremented.
            $current = $this->fetch($nonce);
            if ($current !== null
                && $current->messages > 0
                && $current->conversationId === $entry->conversationId
                && $current->callId === $entry->callId
                && $current->issuedAt === $entry->issuedAt
            ) {
                $current->messages--;
                $this->nonces[$nonce] = $current;
            }
            return false;
        }
        return true;
    }

    // ── Transport ────────────────────────────────────────────────────

    /**
     * A request handler serving `POST /handoff`, `POST /escalate` and `POST /say`.
     *
     * The browser derives all three paths from the one URL it was configured
     * with, so they must be siblings of the gateway's endpoint. The handler is
     * a callable
     * `(string $method, string $path, array $headers, ?string $body): array{int, array<string, string>, string}`.
     *
     * - `/handoff` takes `{"nonce"}` and returns `{"handle"}`.
     * - `/escalate` takes `{"handle"}` and returns `{"ok": true}`.
     * - `/say` takes `{"nonce", "text"}` and returns `{"ok": true}`.
     *
     * An unknown, expired or spent nonce, and a forged handle, are all
     * answered 404 `{"error": "not found"}`; a disallowed `Origin` is 403.
     * Every route answers 413 for a body over
     * {@see ChatGateway::MAX_REQUEST_BODY_BYTES}, and `/say` for text over
     * {@see ChatGateway::MAX_MESSAGE_BYTES}. Both are checked before the nonce
     * is looked up, so the answer says nothing about whether it is live.
     *
     * @return \Closure(string, string, array<string, string>, ?string): array{int, array<string, string>, string}
     */
    public function router(): \Closure
    {
        return function (string $method, string $path, array $headers, ?string $body = null): array {
            $route = GatewayHttp::routePath($path);
            if (!in_array($route, ['/handoff', '/escalate', '/say'], true)) {
                return GatewayHttp::json(404, ['error' => 'not found']);
            }
            if (strtoupper($method) !== 'POST') {
                return GatewayHttp::json(405, ['error' => 'method not allowed'], ['Allow' => 'POST']);
            }
            try {
                $this->gateway->checkOrigin(GatewayHttp::header($headers, 'Origin'));
            } catch (\Throwable) {
                return GatewayHttp::json(403, ['error' => 'origin not allowed']);
            }
            try {
                $data = self::body($body, $headers);
            } catch (GatewayRejection $rej) {
                return GatewayHttp::json($rej->status, ['error' => $rej->reason]);
            }
            return match ($route) {
                '/handoff' => $this->handleHandoff($data),
                '/escalate' => $this->handleEscalate($data),
                default => $this->handleSay($data),
            };
        };
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{int, array<string, string>, string}
     */
    private function handleHandoff(array $data): array
    {
        $nonce = $data['nonce'] ?? null;
        $handle = is_string($nonce) ? $this->redeem($nonce) : null;
        if ($handle === null || $handle === '') {
            // Same answer for unknown, expired and already-redeemed.
            return GatewayHttp::json(404, ['error' => 'not found']);
        }
        return GatewayHttp::json(200, ['handle' => $handle]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{int, array<string, string>, string}
     */
    private function handleEscalate(array $data): array
    {
        $handle = $data['handle'] ?? null;
        if (!is_string($handle) || $handle === '') {
            return GatewayHttp::json(400, ['error' => 'bad request']);
        }
        if (!$this->escalate($handle)) {
            return GatewayHttp::json(404, ['error' => 'not found']);
        }
        return GatewayHttp::json(200, ['ok' => true]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{int, array<string, string>, string}
     */
    private function handleSay(array $data): array
    {
        $nonce = $data['nonce'] ?? null;
        $text = array_key_exists('text', $data) ? $data['text'] : '';
        if (!is_string($nonce) || !is_string($text)) {
            return GatewayHttp::json(404, ['error' => 'not found']);
        }
        if (strlen($text) > ChatGateway::MAX_MESSAGE_BYTES) {
            return GatewayHttp::json(413, ['error' => 'message too large']);
        }
        if (!$this->say($nonce, $text)) {
            return GatewayHttp::json(404, ['error' => 'not found']);
        }
        return GatewayHttp::json(200, ['ok' => true]);
    }

    // ── Internals ────────────────────────────────────────────────────

    /** `root` -> `root.1`; `root.2` -> `root.3`. */
    private static function defaultNextId(string $conversationId): string
    {
        $dot = strrpos($conversationId, '.');
        if ($dot !== false && $dot > 0) {
            $tail = substr($conversationId, $dot + 1);
            if ($tail !== '' && ctype_digit($tail)) {
                return substr($conversationId, 0, $dot) . '.' . ((int) $tail + 1);
            }
        }
        return $conversationId . '.1';
    }

    /** Drop entries, redeemed ones included, whose TTL has passed. */
    private function prune(): void
    {
        $cutoff = hrtime(true) / 1e9 - $this->nonceTtl;
        $expired = [];
        foreach ($this->nonces as $nonce => $entry) {
            if ($entry->issuedAt < $cutoff) {
                $expired[] = $nonce;
            }
        }
        foreach ($expired as $nonce) {
            unset($this->nonces[$nonce]);
        }
    }

    /** The stored entry for `$nonce`, live or not, or null. */
    private function fetch(string $nonce): ?NonceEntry
    {
        if (!isset($this->nonces[$nonce])) {
            return null;
        }
        return $this->nonces[$nonce];
    }

    /** The live entry for `$nonce`: null if unknown, expired or redeemed. */
    private function lookup(string $nonce): ?NonceEntry
    {
        if ($nonce === '') {
            return null;
        }
        $this->prune();
        $entry = $this->fetch($nonce);
        if ($entry === null || $entry->redeemed) {
            return null;
        }
        return $entry;
    }

    /** Run the application's capture, bounded by `captureTimeout`. Never throws. */
    private function capture(string $conversationId, string $medium): bool
    {
        if ($this->captureLeg === null) {
            return false;
        }
        $started = hrtime(true);
        try {
            $captured = ($this->captureLeg)($conversationId, $medium);
        } catch (\Throwable $exc) {
            $this->logger->error('handoff_capture_failed conversation_id=' . $conversationId
                . ' error=' . $exc->getMessage());
            return false;
        }
        if ((hrtime(true) - $started) / 1e9 > $this->captureTimeout) {
            $this->logger->warn('handoff_capture_timeout conversation_id=' . $conversationId
                . ' medium=' . $medium . ' note=starting the next medium without this leg\'s record');
            return false;
        }
        return $captured;
    }

    /**
     * The JSON object sent, or an empty map for anything else.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     *
     * @throws GatewayRejection 413 for a body over the size limit.
     */
    private static function body(?string $body, array $headers): array
    {
        try {
            $data = GatewayHttp::readJsonBody($body, $headers);
        } catch (GatewayRejection $rej) {
            throw $rej;
        } catch (\Throwable) {
            return [];
        }
        return is_array($data) && GatewayHttp::isJsonObject((string) $body) ? GatewayHttp::stringKeyed($data) : [];
    }

    /** Trim surrounding whitespace, Unicode-aware. */
    private static function strip(string $text): string
    {
        $stripped = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $text);
        return is_string($stripped) ? $stripped : trim($text);
    }
}
