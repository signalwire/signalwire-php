<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Security;

/**
 * Standalone security hygiene utilities.
 *
 * Mirrors the Python reference
 * signalwire.core.security.security_utils (filter_sensitive_headers,
 * redact_url, is_valid_hostname) and the TypeScript SDK's SecurityUtils
 * (filterSensitiveHeaders, redactUrl, isValidHostname): keep credentials
 * out of user callbacks and logs, plus a reusable hostname sanity check.
 *
 * Python ships these as three module-level free functions. PHP groups the
 * three static methods on this final class for cohesion and IDE/PSR-4
 * discoverability; scripts/enumerate_signatures.py projects each static
 * method onto the canonical Python module-level free function via
 * FREE_FUNCTION_PROJECTIONS, so the cross-language surface treats
 * SecurityUtils::filterSensitiveHeaders as
 * signalwire.core.security.security_utils.filter_sensitive_headers, etc.
 *
 * Public API:
 *
 *     SecurityUtils::filterSensitiveHeaders($headers): array
 *     SecurityUtils::redactUrl($url): string
 *     SecurityUtils::isValidHostname($host): bool
 */
final class SecurityUtils
{
    /**
     * Header names whose values are credentials/secrets and must never be
     * handed to user callbacks or written to logs. Compared case-insensitively.
     *
     * @var list<string>
     */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'x-api-key',
        'proxy-authorization',
        'set-cookie',
    ];

    /**
     * URL userinfo credentials: `://user:secret@host` -> `://user:****@host`.
     * Mirrors the Python regex `://([^:@/]+):([^@/]+)@`.
     */
    private const URL_CREDENTIALS_RE = '#://([^:@/]+):([^@/]+)@#';

    /**
     * Hostnames must not contain whitespace, slashes, backslashes, or control
     * characters. Mirrors the Python char class `[\s/\\\x00-\x1f\x7f]`.
     */
    private const HOSTNAME_REJECT_RE = '#[\s/\\\\\x00-\x1f\x7f]#';

    /**
     * Return a copy of $headers with sensitive (credential-bearing) headers
     * removed, so request headers can be safely passed to user callbacks /
     * logs.
     *
     * The sensitivity check is case-insensitive; non-sensitive keys are
     * preserved exactly as given (original casing). An empty array (or any
     * empty/falsy input) yields an empty array.
     *
     * @param array<string, mixed> $headers Map of header name -> value.
     *
     * @return array<string, mixed> A new map containing only the
     *                               non-sensitive headers.
     */
    public static function filterSensitiveHeaders(array $headers): array
    {
        if ($headers === []) {
            return [];
        }
        $filtered = [];
        foreach ($headers as $key => $value) {
            if (!in_array(strtolower($key), self::SENSITIVE_HEADERS, true)) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    /**
     * Mask the password in a URL's userinfo before logging.
     *
     * `https://user:secret@host/path` -> `https://user:****@host/path`.
     * A URL with no embedded credentials is returned unchanged.
     *
     * @param string $url The URL string.
     *
     * @return string The URL with any `:password@` replaced by `:****@`.
     */
    public static function redactUrl(string $url): string
    {
        $result = preg_replace(self::URL_CREDENTIALS_RE, '://$1:****@', $url);
        // preg_replace returns null only on a regex/engine error; the pattern
        // is a compile-time constant, so fall back to the original input.
        return $result ?? $url;
    }

    /**
     * Standalone hostname sanity check: reject empty hosts and any host
     * containing whitespace, slashes, backslashes, or control characters.
     *
     * This is the reusable character-level check, independent of the fuller
     * {@see \SignalWire\Utils\UrlValidator::validateUrl} (which also does
     * scheme checks, DNS resolution, and private-IP blocking).
     *
     * @param string $host The hostname string.
     *
     * @return bool True if the hostname is non-empty and contains no
     *              whitespace/slashes/control characters; false otherwise.
     */
    public static function isValidHostname(string $host): bool
    {
        if ($host === '') {
            return false;
        }
        return preg_match(self::HOSTNAME_REJECT_RE, $host) === 0;
    }
}
