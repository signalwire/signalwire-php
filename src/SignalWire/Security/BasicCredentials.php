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
 * Basic-auth credential carrier — the argument {@see AuthHandler::verifyBasicAuth()}
 * takes.
 *
 * The reference types that parameter as FastAPI's ``HTTPBasicCredentials``
 * (core/auth_handler.py:98), a two-``str`` pydantic model of ``username`` +
 * ``password`` (``fastapi/security/http.py``). Those two fields ARE the contract;
 * the framework class is not part of it, so PHP ships the same pair as a plain
 * readonly record with no framework dependency. C++ carries the identical struct
 * (``include/signalwire/core/auth_handler.hpp``).
 */
final class BasicCredentials
{
    /**
     * @param string $username The username presented in the Basic ``Authorization`` header.
     * @param string $password The password presented in the Basic ``Authorization`` header.
     */
    public function __construct(
        public readonly string $username,
        public readonly string $password,
    ) {
    }
}
