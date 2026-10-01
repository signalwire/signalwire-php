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
 * What a handoff nonce is a capability for.
 *
 * `redeemed` marks a nonce `/handoff` has exchanged for a handle. The entry is
 * kept until its TTL passes, so the nonce can be neither redeemed nor
 * registered again.
 */
final class NonceEntry
{
    /** Seconds on a monotonic clock when the nonce was first registered. */
    public float $issuedAt;

    /**
     * @param string      $conversationId The conversation the call belongs to.
     * @param string|null $callId         The live call the nonce names, from the platform's request.
     * @param float|null  $issuedAt       Monotonic registration time in seconds; now when omitted.
     * @param int         $messages       Typed messages delivered into the call so far.
     * @param bool        $redeemed       Whether the nonce has been exchanged for a handle.
     */
    public function __construct(
        public string $conversationId,
        public ?string $callId = null,
        ?float $issuedAt = null,
        public int $messages = 0,
        public bool $redeemed = false,
    ) {
        $this->issuedAt = $issuedAt ?? hrtime(true) / 1e9;
    }
}
