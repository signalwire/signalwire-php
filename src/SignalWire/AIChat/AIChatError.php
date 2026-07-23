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
 * Base error for AI Chat service failures.
 *
 * Every typed subclass carries the JSON-RPC error ``code`` (or ``null`` when the
 * failure rode the success envelope, as with {@see SummaryError}) plus the
 * server-provided ``message``. Callers catch this one family
 * (``catch (AIChatError $e)``) for every AI-Chat failure and branch on
 * ``getErrorCode()`` or the subclass type.
 *
 * Mirrors the python reference ``signalwire.ai_chat.AIChatError``.
 */
class AIChatError extends \RuntimeException
{
    /**
     * The JSON-RPC error code, or ``null`` when the failure rode the success
     * envelope (a {@see SummaryError} has no JSON-RPC code).
     */
    private ?int $errorCode;

    /** The raw server-provided error message (without the ``[code]`` prefix). */
    private string $serverMessage;

    /**
     * @param int|null $code    JSON-RPC error code, or ``null`` (a success-envelope failure).
     * @param string   $message The server-provided error message.
     */
    public function __construct(?int $code, string $message)
    {
        $this->errorCode = $code;
        $this->serverMessage = $message;
        // \Exception::getCode() is typed int, so the exposed JSON-RPC code lives
        // on getErrorCode(); the human-readable string carries the [code] prefix,
        // mirroring the python reference's "[<code>] <message>".
        parent::__construct(sprintf('[%s] %s', $code ?? 'null', $message), $code ?? 0);
    }

    /**
     * The JSON-RPC error code, or ``null`` when the failure rode the success
     * envelope. This is the language-neutral contract the wire-behavioral gate
     * asserts on; prefer it over ``getCode()`` (which is ``0`` for a null code).
     */
    public function getErrorCode(): ?int
    {
        return $this->errorCode;
    }

    /** The raw server error message, without the ``[code]`` prefix. */
    public function getServerMessage(): string
    {
        return $this->serverMessage;
    }
}
