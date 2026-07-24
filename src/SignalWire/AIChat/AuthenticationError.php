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
 * Missing or rejected identity (HTTP 401 / JSON-RPC ``-32009``).
 */
class AuthenticationError extends AIChatError
{
}
