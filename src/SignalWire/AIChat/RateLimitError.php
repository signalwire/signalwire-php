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
 * Project or conversation rate limit hit (JSON-RPC ``-32005`` / ``-32006``).
 */
class RateLimitError extends AIChatError
{
}
