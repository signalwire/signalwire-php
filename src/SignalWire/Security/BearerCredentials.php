<?php

declare(strict_types=1);

/*
 * Copyright (c) 2026 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

namespace SignalWire\Security;

/**
 * Bearer-token credential carrier — the argument
 * {@see AuthHandler::verifyBearerToken()} takes.
 *
 * The reference types that parameter as FastAPI's
 * ``HTTPAuthorizationCredentials`` (core/auth_handler.py:113), a two-``str``
 * pydantic model of ``scheme`` + ``credentials`` (``fastapi/security/http.py``):
 * the ``Authorization`` header split at its first space, so ``Bearer abc123``
 * yields ``scheme='Bearer'`` and ``credentials='abc123'``. Only ``credentials``
 * is compared (the reference reads ``credentials.credentials``), but ``scheme``
 * is part of what the caller is handed, so the carrier records both.
 */
final class BearerCredentials
{
    /**
     * @param string $scheme      The authorization scheme, e.g. ``Bearer``.
     * @param string $credentials The token itself — the part after the scheme.
     */
    public function __construct(
        public readonly string $scheme,
        public readonly string $credentials,
    ) {
    }
}
