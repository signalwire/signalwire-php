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
 * Summary generation failed.
 *
 * ``summarize`` returns EXACTLY ONE of ``{summary}`` (success) or ``{error}``
 * (generation failed), and the failure rides the JSON-RPC *success* envelope —
 * not an ``error`` object — so it never reaches the error-code mapping. Surfaced
 * here so a failed summary can't masquerade as an empty string. ``getErrorCode()``
 * is ``null`` (no JSON-RPC code).
 *
 * Mirrors the python reference ``signalwire.ai_chat.SummaryError``.
 */
class SummaryError extends AIChatError
{
}
