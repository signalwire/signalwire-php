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
 * Result of {@see AIChatClient::createConversation()}.
 *
 * Mirrors the python reference ``ConversationInfo`` dataclass.
 */
final class ConversationInfo
{
    /**
     * @param string      $id             The conversation id (echoed back — the caller's own input).
     * @param string      $status         Lifecycle status the service reported (e.g. ``"created"``).
     * @param string|null $initialMessage The opening assistant message, if the config produced one.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly ?string $initialMessage = null,
    ) {
    }
}
