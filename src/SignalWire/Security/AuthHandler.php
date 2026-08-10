<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Security;

use SignalWire\Core\SecurityConfig;
use SignalWire\Logging\Logger;

/**
 * Unified multi-method authentication handler supporting Bearer token, API key,
 * and Basic auth with constant-time (timing-safe) credential comparison.
 *
 * Mirrors the `signalwire.core.auth_handler.AuthHandler` API
 * (core/auth_handler.py) — same verify_api_key / verify_basic_auth /
 * verify_bearer_token / get_auth_info capability set — and TypeScript
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
     * The SecurityConfig this handler derives its credentials from, when the
     * caller constructed it that way. The reference's ONLY construction route is
     * `AuthHandler(security_config)` (core/auth_handler.py:56-63), and it exposes
     * the config back as a public `self.security_config`.
     */
    private ?SecurityConfig $securityConfig = null;

    /**
     * @param string|null $bearerToken Bearer token matched against the Authorization header.
     * @param string|null $apiKey      API key matched against the api-key header.
     * @param array{0: string, 1: string}|null $basicAuth [username, password] tuple.
     * @param string $apiKeyHeader Header name for API-key lookup (default: 'X-Api-Key').
     * @param SecurityConfig|null $securityConfig Derive the basic-auth credentials
     *   from a SecurityConfig, the reference's construction route. When supplied
     *   and no explicit `$basicAuth` was given, the pair comes from
     *   {@see SecurityConfig::getBasicAuth()} — the same source the reference's
     *   `_setup_auth_methods` reads (auth_handler.py:77). An explicit
     *   `$basicAuth` still wins, so existing flat-argument callers are unchanged.
     */
    public function __construct(
        ?string $bearerToken = null,
        ?string $apiKey = null,
        ?array $basicAuth = null,
        string $apiKeyHeader = 'X-Api-Key',
        ?SecurityConfig $securityConfig = null,
    ) {
        $this->bearerToken = $bearerToken;
        $this->apiKey = $apiKey;
        $this->apiKeyHeader = $apiKeyHeader;
        $this->securityConfig = $securityConfig;

        if ($basicAuth === null && $securityConfig !== null) {
            $basicAuth = $securityConfig->getBasicAuth();
        }
        $this->basicAuth = $basicAuth;
    }

    /**
     * The SecurityConfig this handler was constructed with, or null when the
     * caller supplied credentials directly. Mirrors the reference's public
     * `self.security_config` attribute.
     */
    public function getSecurityConfig(): ?SecurityConfig
    {
        return $this->securityConfig;
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
     * Verify presented Bearer credentials against the configured token. Returns
     * false immediately if Bearer auth is not configured. Constant-time
     * comparison.
     *
     * Mirrors the reference's `verify_bearer_token(credentials)`
     * (core/auth_handler.py:113): only the `credentials` field — the token
     * itself — is compared; `scheme` is carried but not matched.
     */
    public function verifyBearerToken(BearerCredentials $credentials): bool
    {
        if ($this->bearerToken === null) {
            return false;
        }
        return hash_equals($this->bearerToken, $credentials->credentials);
    }

    /**
     * Verify presented Basic Auth credentials against the configured pair.
     * Returns false immediately if Basic auth is not configured. Constant-time
     * comparison of both fields.
     *
     * Mirrors the reference's `verify_basic_auth(credentials)`
     * (core/auth_handler.py:98).
     */
    public function verifyBasicAuth(BasicCredentials $credentials): bool
    {
        if ($this->basicAuth === null) {
            return false;
        }
        [$expectedUser, $expectedPass] = $this->basicAuth;
        return hash_equals($expectedUser, $credentials->username)
            && hash_equals($expectedPass, $credentials->password);
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
     * warning and allows the request through.
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
            $token = self::schemeParam($auth, 'Bearer');
            if ($token !== null) {
                // Carry the scheme through exactly as the client sent it — the
                // reference reports the wire scheme, not a canonicalized one.
                $sentScheme = substr($auth, 0, (int) strpos($auth, ' '));
                $presented = new BearerCredentials($sentScheme, $token);
                if ($this->verifyBearerToken($presented)) {
                    return true;
                }
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
            $param = self::schemeParam($auth, 'Basic');
            if ($param !== null) {
                $decoded = base64_decode($param, true);
                if ($decoded !== false) {
                    $colon = strpos($decoded, ':');
                    if ($colon !== false && $colon > 0) {
                        $user = substr($decoded, 0, $colon);
                        $pass = substr($decoded, $colon + 1);
                        if ($this->verifyBasicAuth(new BasicCredentials($user, $pass))) {
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

    /**
     * Split an ``Authorization`` header into its scheme and credential,
     * mirroring FastAPI's ``get_authorization_scheme_param`` (partition on the
     * FIRST space, strip the credential).
     *
     * Returns ``null`` when the header is empty or its scheme token does not
     * case-insensitively equal ``$expectedScheme``. RFC 7235 makes the
     * auth-scheme token case-insensitive and the reference compares
     * ``scheme.lower() != "bearer"`` / ``!= "basic"``, so ``bearer <token>`` is
     * legal and must not be rejected.
     */
    private static function schemeParam(string $authHeader, string $expectedScheme): ?string
    {
        $sep = strpos($authHeader, ' ');
        if ($sep === false) {
            return null;
        }
        if (strcasecmp(substr($authHeader, 0, $sep), $expectedScheme) !== 0) {
            return null;
        }
        return trim(substr($authHeader, $sep + 1));
    }
}
