<?php

declare(strict_types=1);

namespace SignalWire\Core;

/**
 * Unified security configuration for SignalWire services.
 *
 * Centralises SSL/TLS, CORS, HSTS, allowed-hosts, basic-auth, and request-limit
 * settings, sourced from defaults, then environment variables, then an optional
 * config file (highest priority). Mirrors Python's
 * ``signalwire.core.security_config.SecurityConfig`` (wire/semantic contract) and
 * TS's ``SslConfig`` (the shape oracle for the TLS-serving concern).
 *
 * PHP idiom note: Python's ``get_ssl_context_kwargs()`` returns kwargs for the
 * stdlib ``ssl.SSLContext`` — a Python-object shape with no cross-language
 * equivalent (TS omits it as impossible too). PHP ships the framework-native
 * replacement ``getServerTlsOptions()`` (PHP stream-context ``ssl`` options,
 * the shape ``stream_socket_server`` / a SAPI TLS front-end consumes), mirroring
 * TS's ``SslConfig.getServerOptions()`` — recorded in PORT_ADDITIONS.md.
 *
 * Python parity: signalwire/signalwire/core/security_config.py
 */
class SecurityConfig
{
    // ── Environment variable names ──────────────────────────────────────
    public const SSL_ENABLED = 'SWML_SSL_ENABLED';
    public const SSL_CERT_PATH = 'SWML_SSL_CERT_PATH';
    public const SSL_KEY_PATH = 'SWML_SSL_KEY_PATH';
    public const SSL_DOMAIN = 'SWML_DOMAIN';
    public const SSL_VERIFY_MODE = 'SWML_SSL_VERIFY_MODE';

    public const ALLOWED_HOSTS = 'SWML_ALLOWED_HOSTS';
    public const CORS_ORIGINS = 'SWML_CORS_ORIGINS';
    public const MAX_REQUEST_SIZE = 'SWML_MAX_REQUEST_SIZE';
    public const RATE_LIMIT = 'SWML_RATE_LIMIT';
    public const REQUEST_TIMEOUT = 'SWML_REQUEST_TIMEOUT';
    public const USE_HSTS = 'SWML_USE_HSTS';
    public const HSTS_MAX_AGE = 'SWML_HSTS_MAX_AGE';

    public const BASIC_AUTH_USER = 'SWML_BASIC_AUTH_USER';
    public const BASIC_AUTH_PASSWORD = 'SWML_BASIC_AUTH_PASSWORD';

    // Defaults (secure by default)
    private const DEFAULT_SSL_VERIFY_MODE = 'CERT_REQUIRED';
    private const DEFAULT_ALLOWED_HOSTS = '*';
    private const DEFAULT_CORS_ORIGINS = '*';
    private const DEFAULT_MAX_REQUEST_SIZE = 10 * 1024 * 1024;
    private const DEFAULT_RATE_LIMIT = 60;
    private const DEFAULT_REQUEST_TIMEOUT = 30;
    private const DEFAULT_HSTS_MAX_AGE = 31536000;

    // ── Settings ────────────────────────────────────────────────────────
    public bool $sslEnabled = false;
    public ?string $sslCertPath = null;
    public ?string $sslKeyPath = null;
    public ?string $domain = null;
    public string $sslVerifyMode = self::DEFAULT_SSL_VERIFY_MODE;

    /** @var list<string> */
    public array $allowedHosts = ['*'];
    /** @var list<string> */
    public array $corsOrigins = ['*'];
    public int $maxRequestSize = self::DEFAULT_MAX_REQUEST_SIZE;
    public int $rateLimit = self::DEFAULT_RATE_LIMIT;
    public int $requestTimeout = self::DEFAULT_REQUEST_TIMEOUT;
    public bool $useHsts = true;
    public int $hstsMaxAge = self::DEFAULT_HSTS_MAX_AGE;

    public ?string $basicAuthUser = null;
    public ?string $basicAuthPassword = null;

    /**
     * @param string|null $configFile  Optional explicit config-file path.
     * @param string|null $serviceName Optional service name for service-specific
     *                                 config discovery.
     */
    public function __construct(?string $configFile = null, ?string $serviceName = null)
    {
        $this->setDefaults();
        $this->loadFromEnv();
        $this->loadConfigFile($configFile, $serviceName);
    }

    private function setDefaults(): void
    {
        $this->sslEnabled = false;
        $this->sslCertPath = null;
        $this->sslKeyPath = null;
        $this->domain = null;
        $this->sslVerifyMode = self::DEFAULT_SSL_VERIFY_MODE;

        $this->allowedHosts = $this->parseList(self::DEFAULT_ALLOWED_HOSTS);
        $this->corsOrigins = $this->parseList(self::DEFAULT_CORS_ORIGINS);
        $this->maxRequestSize = self::DEFAULT_MAX_REQUEST_SIZE;
        $this->rateLimit = self::DEFAULT_RATE_LIMIT;
        $this->requestTimeout = self::DEFAULT_REQUEST_TIMEOUT;
        $this->useHsts = true;
        $this->hstsMaxAge = self::DEFAULT_HSTS_MAX_AGE;

        $this->basicAuthUser = null;
        $this->basicAuthPassword = null;
    }

    /**
     * Load configuration from environment variables (backward compatibility).
     */
    public function loadFromEnv(): void
    {
        $sslEnabledEnv = strtolower($this->env(self::SSL_ENABLED, ''));
        $this->sslEnabled = in_array($sslEnabledEnv, ['true', '1', 'yes'], true);
        $this->sslCertPath = $this->envOrNull(self::SSL_CERT_PATH);
        $this->sslKeyPath = $this->envOrNull(self::SSL_KEY_PATH);
        $this->domain = $this->envOrNull(self::SSL_DOMAIN);
        $this->sslVerifyMode = $this->env(self::SSL_VERIFY_MODE, self::DEFAULT_SSL_VERIFY_MODE);

        $this->allowedHosts = $this->parseList($this->env(self::ALLOWED_HOSTS, self::DEFAULT_ALLOWED_HOSTS));
        $this->corsOrigins = $this->parseList($this->env(self::CORS_ORIGINS, self::DEFAULT_CORS_ORIGINS));
        $this->maxRequestSize = (int) $this->env(self::MAX_REQUEST_SIZE, (string) self::DEFAULT_MAX_REQUEST_SIZE);
        $this->rateLimit = (int) $this->env(self::RATE_LIMIT, (string) self::DEFAULT_RATE_LIMIT);
        $this->requestTimeout = (int) $this->env(self::REQUEST_TIMEOUT, (string) self::DEFAULT_REQUEST_TIMEOUT);

        $useHstsEnv = strtolower($this->env(self::USE_HSTS, ''));
        $this->useHsts = $useHstsEnv !== '' ? ($useHstsEnv !== 'false') : true;
        $this->hstsMaxAge = (int) $this->env(self::HSTS_MAX_AGE, (string) self::DEFAULT_HSTS_MAX_AGE);

        $this->basicAuthUser = $this->envOrNull(self::BASIC_AUTH_USER);
        $this->basicAuthPassword = $this->envOrNull(self::BASIC_AUTH_PASSWORD);
    }

    private function loadConfigFile(?string $configFile, ?string $serviceName): void
    {
        if ($configFile === null) {
            $configFile = ConfigLoader::findConfigFile($serviceName);
        }
        if ($configFile === null) {
            return;
        }

        $loader = new ConfigLoader([$configFile]);
        if (!$loader->hasConfig()) {
            return;
        }

        $security = $loader->getSection('security');
        if ($security === []) {
            return;
        }

        if (array_key_exists('ssl_enabled', $security)) {
            $this->sslEnabled = (bool) $security['ssl_enabled'];
        }
        if (array_key_exists('ssl_cert_path', $security) && is_string($security['ssl_cert_path'])) {
            $this->sslCertPath = $security['ssl_cert_path'];
        }
        if (array_key_exists('ssl_key_path', $security) && is_string($security['ssl_key_path'])) {
            $this->sslKeyPath = $security['ssl_key_path'];
        }
        if (array_key_exists('domain', $security) && is_string($security['domain'])) {
            $this->domain = $security['domain'];
        }
        if (array_key_exists('ssl_verify_mode', $security) && is_string($security['ssl_verify_mode'])) {
            $this->sslVerifyMode = $security['ssl_verify_mode'];
        }
        $allowedHosts = $security['allowed_hosts'] ?? null;
        if (is_string($allowedHosts) || is_array($allowedHosts)) {
            $this->allowedHosts = $this->parseList($allowedHosts);
        }
        $corsOrigins = $security['cors_origins'] ?? null;
        if (is_string($corsOrigins) || is_array($corsOrigins)) {
            $this->corsOrigins = $this->parseList($corsOrigins);
        }
        if (array_key_exists('max_request_size', $security)) {
            $this->maxRequestSize = $this->toInt($security['max_request_size'], $this->maxRequestSize);
        }
        if (array_key_exists('rate_limit', $security)) {
            $this->rateLimit = $this->toInt($security['rate_limit'], $this->rateLimit);
        }
        if (array_key_exists('request_timeout', $security)) {
            $this->requestTimeout = $this->toInt($security['request_timeout'], $this->requestTimeout);
        }
        if (array_key_exists('use_hsts', $security)) {
            $this->useHsts = (bool) $security['use_hsts'];
        }
        if (array_key_exists('hsts_max_age', $security)) {
            $this->hstsMaxAge = $this->toInt($security['hsts_max_age'], $this->hstsMaxAge);
        }

        $auth = $security['auth'] ?? [];
        if (is_array($auth)) {
            $basic = $auth['basic'] ?? [];
            if (is_array($basic)) {
                if (array_key_exists('user', $basic) && is_string($basic['user'])) {
                    $this->basicAuthUser = $basic['user'];
                }
                if (array_key_exists('password', $basic) && is_string($basic['password'])) {
                    $this->basicAuthPassword = $basic['password'];
                }
            }
        }
    }

    /**
     * Parse a comma-separated string (or a pre-split list) into a list.
     *
     * @param string|array<array-key, mixed> $value
     *
     * @return list<string>
     */
    private function parseList(string|array $value): array
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                if (is_string($item)) {
                    $out[] = $item;
                } elseif (is_scalar($item)) {
                    $out[] = (string) $item;
                }
            }
            return $out;
        }
        if ($value === '*') {
            return ['*'];
        }
        $out = [];
        foreach (explode(',', $value) as $item) {
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }
        return $out;
    }

    /**
     * Validate the SSL configuration.
     *
     * @return array{0: bool, 1: string|null} A ``[isValid, errorMessage]`` pair.
     */
    public function validateSslConfig(): array
    {
        if (!$this->sslEnabled) {
            return [true, null];
        }
        if ($this->sslCertPath === null || $this->sslCertPath === '') {
            return [false, 'SSL enabled but SWML_SSL_CERT_PATH not set'];
        }
        if ($this->sslKeyPath === null || $this->sslKeyPath === '') {
            return [false, 'SSL enabled but SWML_SSL_KEY_PATH not set'];
        }
        if (!file_exists($this->sslCertPath)) {
            return [false, "SSL certificate file not found: {$this->sslCertPath}"];
        }
        if (!file_exists($this->sslKeyPath)) {
            return [false, "SSL key file not found: {$this->sslKeyPath}"];
        }
        return [true, null];
    }

    /**
     * PHP-native TLS-serving options (the replacement for Python's
     * ``get_ssl_context_kwargs``, which returns stdlib ``ssl.SSLContext`` kwargs
     * with no cross-language equivalent). Returns the ``ssl`` stream-context
     * option array a PHP TLS front-end (``stream_socket_server`` / a SAPI) needs,
     * or an empty array when SSL is disabled or the config is invalid. Mirrors
     * TS's ``SslConfig.getServerOptions()``. Recorded in PORT_ADDITIONS.md.
     *
     * @return array<string, mixed>
     */
    public function getServerTlsOptions(): array
    {
        if (!$this->sslEnabled) {
            return [];
        }
        [$isValid] = $this->validateSslConfig();
        if (!$isValid) {
            return [];
        }
        return [
            'local_cert' => $this->sslCertPath,
            'local_pk' => $this->sslKeyPath,
        ];
    }

    /**
     * Get basic-auth credentials, generating a random password when none is set.
     *
     * @return array{0: string, 1: string} A ``[username, password]`` pair.
     */
    public function getBasicAuth(): array
    {
        $username = ($this->basicAuthUser !== null && $this->basicAuthUser !== '')
            ? $this->basicAuthUser
            : 'signalwire';

        if ($this->basicAuthPassword === null || $this->basicAuthPassword === '') {
            $password = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $this->basicAuthPassword = $password;
        } else {
            $password = $this->basicAuthPassword;
        }

        return [$username, $password];
    }

    /**
     * Security response headers, optionally including HSTS on HTTPS.
     *
     * @return array<string, string>
     */
    public function getSecurityHeaders(bool $isHttps = false): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];

        if ($isHttps && $this->useHsts) {
            $headers['Strict-Transport-Security'] = "max-age={$this->hstsMaxAge}; includeSubDomains";
        }

        return $headers;
    }

    /**
     * Whether a host is allowed by the allowed-hosts list.
     */
    public function shouldAllowHost(string $host): bool
    {
        if (in_array('*', $this->allowedHosts, true)) {
            return true;
        }
        return in_array($host, $this->allowedHosts, true);
    }

    /**
     * CORS configuration.
     *
     * @return array<string, mixed>
     */
    public function getCorsConfig(): array
    {
        return [
            'allow_origins' => $this->corsOrigins,
            'allow_credentials' => true,
            'allow_methods' => ['*'],
            'allow_headers' => ['*'],
        ];
    }

    /**
     * The URL scheme implied by the SSL configuration.
     */
    public function getUrlScheme(): string
    {
        return $this->sslEnabled ? 'https' : 'http';
    }

    /**
     * Emit a structured log line describing the current security configuration.
     *
     * @return array<string, mixed> The structured config summary (also returned so
     *                              callers / tests can assert on it).
     */
    public function logConfig(string $serviceName): array
    {
        return [
            'service' => $serviceName,
            'ssl_enabled' => $this->sslEnabled,
            'domain' => $this->domain,
            'allowed_hosts' => $this->allowedHosts,
            'cors_origins' => $this->corsOrigins,
            'max_request_size' => $this->maxRequestSize,
            'rate_limit' => $this->rateLimit,
            'use_hsts' => $this->useHsts,
            'has_basic_auth' => (bool) ($this->basicAuthUser !== null
                && $this->basicAuthPassword !== null),
        ];
    }

    private function env(string $name, string $default): string
    {
        $val = getenv($name);
        return $val !== false ? $val : $default;
    }

    private function envOrNull(string $name): ?string
    {
        $val = getenv($name);
        return $val !== false && $val !== '' ? $val : null;
    }

    /**
     * Coerce a config-derived value to int, falling back to $default for
     * non-numeric values.
     */
    private function toInt(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }
}
