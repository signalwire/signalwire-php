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
 * Result of {@see AIChatClient::log()}.
 *
 * Mirrors the python reference ``ChatLog`` dataclass.
 */
final class ChatLog
{
    /**
     * @param list<array<string, mixed>> $messages     Full message history (the wire ``chat_log`` field).
     * @param list<array<string, mixed>> $callTimeline The call timeline (the wire ``call_timeline`` field).
     */
    public function __construct(
        public readonly array $messages = [],
        public readonly array $callTimeline = [],
    ) {
    }
}
