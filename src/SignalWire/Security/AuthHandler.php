<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Security;

use SignalWire\Logging\Logger;

/**
 * Unified multi-method authentication handler supporting Bearer token, API key,
 * and Basic auth with constant-time (timing-safe) credential comparison.
 *
 * Parity with the Python reference `signalwire.core.auth_handler.AuthHandler`
 * (core/auth_handler.py) — same verify_api_key / verify_basic_auth /
 * verify_bearer_token / get_auth_info capability set — and the TS oracle
 * `AuthHandler` (AuthHandler.ts), whose config-object construction this mirrors
 * (PHP named arguments give the same keyword-style call as TS's options bag).
 *
 * The framework-specific `flask_decorator` / `get_fastapi_dependency` (Python)
 * are NOT ported: PHP ships a framework-agnostic {@see middleware()} analog
 * (recorded in PORT_ADDITIONS.md), the same role TS's `middleware`/
 * `expressMiddleware` play.
 */
class AuthHandler
{
    private ?string $bearerToken;
    private ?string $apiKey;
    private string $apiKeyHeader;
    /** @var array{0: string, 1: string}|null */
    private ?array $basicAuth;

    /**
     * @param string|null $bearerToken Bearer token matched against the Authorization header.
     * @param string|null $apiKey      API key matched against the api-key header.
     * @param array{0: string, 1: string}|null $basicAuth [username, password] tuple.
     * @param string $apiKeyHeader Header name for API-key lookup (default: 'X-Api-Key').
     */
    public function __construct(
        ?string $bearerToken = null,
        ?string $apiKey = null,
        ?array $basicAuth = null,
        string $apiKeyHeader = 'X-Api-Key',
    ) {
        $this->bearerToken = $bearerToken;
        $this->apiKey = $apiKey;
        $this->basicAuth = $basicAuth;
        $this->apiKeyHeader = $apiKeyHeader;
    }

    /**
     * Verify an API key against the configured key. Returns false immediately
     * if API-key auth is not configured. Constant-time comparison.
     */
    public function verifyApiKey(string $key): bool
    {
        if ($this->apiKey === null) {
            return false;
        }
        return hash_equals($this->apiKey, $key);
    }

    /**
     * Verify a Bearer token against the configured token. Returns false
     * immediately if Bearer auth is not configured. Constant-time comparison.
     */
    public function verifyBearerToken(string $token): bool
    {
        if ($this->bearerToken === null) {
            return false;
        }
        return hash_equals($this->bearerToken, $token);
    }

    /**
     * Verify a Basic Auth username/password pair against the configured
     * credentials. Returns false immediately if Basic auth is not configured.
     * Constant-time comparison of both fields.
     */
    public function verifyBasicAuth(string $username, string $password): bool
    {
        if ($this->basicAuth === null) {
            return false;
        }
        [$expectedUser, $expectedPass] = $this->basicAuth;
        return hash_equals($expectedUser, $username) && hash_equals($expectedPass, $password);
    }

    /**
     * Get structured metadata describing each enabled auth method (usernames,
     * header names, usage hints). Mirrors Python's `get_auth_info` and TS's
     * `getAuthInfo`.
     *
     * @return array<string,mixed>
     */
    public function getAuthInfo(): array
    {
        $info = [];

        if ($this->basicAuth !== null) {
            $info['basic'] = ['enabled' => true, 'username' => $this->basicAuth[0]];
        }
        if ($this->bearerToken !== null) {
            $info['bearer'] = ['enabled' => true, 'hint' => 'Use Authorization: Bearer <token>'];
        }
        if ($this->apiKey !== null) {
            $info['api_key'] = [
                'enabled' => true,
                'header' => $this->apiKeyHeader,
                'hint' => "Use {$this->apiKeyHeader}: <key>",
            ];
        }

        return $info;
    }

    /**
     * PHP-native, framework-agnostic middleware analog (the role Python's
     * get_fastapi_dependency / flask_decorator and TS's middleware/
     * expressMiddleware play). Returns a closure that validates the request
     * headers against the configured methods, returning a
     * `[status, headers, body]` triple to reject an unauthenticated request
     * (matching Service::handleRequest's response shape) or `null` to allow it
     * through.
     *
     * Recorded as a PHP addition in PORT_ADDITIONS.md.
     *
     * @return callable(array<string,string>):(array{0:int,1:array<string,string>,2:string}|null)
     */
    public function middleware(bool $optional = false): callable
    {
        return function (array $headers) use ($optional): ?array {
            if ($this->validate($headers) || $optional) {
                return null;
            }
            return [
                401,
                ['Content-Type' => 'application/json'],
                (string) json_encode(['error' => 'Unauthorized']),
            ];
        };
    }

    /**
     * Validate a request's headers against every configured auth method in
     * order (Bearer, API key, Basic). When no method is configured, logs a
     * warning and allows the request through (parity with TS's validate).
     *
     * @param array<string,string> $headers Case-insensitive request headers.
     */
    public function validate(array $headers): bool
    {
        $get = static function (array $h, string $name): ?string {
            foreach ($h as $k => $v) {
                if (is_string($k) && strcasecmp($k, $name) === 0 && is_string($v)) {
                    return $v;
                }
            }
            return null;
        };

        if ($this->bearerToken !== null) {
            $auth = $get($headers, 'Authorization') ?? '';
            if (str_starts_with($auth, 'Bearer ') && $this->verifyBearerToken(substr($auth, 7))) {
                return true;
            }
        }

        if ($this->apiKey !== null) {
            $key = $get($headers, $this->apiKeyHeader) ?? '';
            if ($key !== '' && $this->verifyApiKey($key)) {
                return true;
            }
        }

        if ($this->basicAuth !== null) {
            $auth = $get($headers, 'Authorization') ?? '';
            if (str_starts_with($auth, 'Basic ')) {
                $decoded = base64_decode(substr($auth, 6), true);
                if ($decoded !== false) {
                    $colon = strpos($decoded, ':');
                    if ($colon !== false && $colon > 0) {
                        $user = substr($decoded, 0, $colon);
                        $pass = substr($decoded, $colon + 1);
                        if ($this->verifyBasicAuth($user, $pass)) {
                            return true;
                        }
                    }
                }
            }
        }

        if ($this->bearerToken === null && $this->apiKey === null && $this->basicAuth === null) {
            Logger::getLogger('AuthHandler')->warn(
                'No auth methods configured; allowing unauthenticated access.'
            );
            return true;
        }

        return false;
    }
}
