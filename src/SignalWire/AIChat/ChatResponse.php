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
 * Result of {@see AIChatClient::chat()}.
 *
 * Mirrors the python reference ``ChatResponse`` dataclass.
 */
final class ChatResponse
{
    /**
     * @param string                    $text           The assistant's reply text (the wire ``response`` field).
     * @param string                    $conversationId The conversation id this reply belongs to.
     * @param array<string, mixed>|null $userEvent      An optional structured event the turn emitted, else ``null``.
     */
    public function __construct(
        public readonly string $text,
        public readonly string $conversationId,
        public readonly ?array $userEvent = null,
    ) {
    }
}
