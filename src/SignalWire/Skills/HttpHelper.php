<?php

declare(strict_types=1);

namespace SignalWire\Skills;

/**
 * Tiny HTTP helper for skill upstream calls.
 *
 * The REST client uses cURL behind a Basic-auth wrapper bound to
 * `https://<space>` — that's the wrong shape for skills, which talk to
 * arbitrary third-party services with their own URL bases, headers,
 * and auth schemes. Each skill could call cURL inline, but they all
 * need the same handful of mechanics (URL building, headers, basic
 * auth, JSON encode/decode, base-URL override for testing). This
 * helper centralises that and gives skills a way to honor the
 * `<SKILL>_BASE_URL` env-var override that audit_skills_dispatch
 * relies on without each skill duplicating the env lookup.
 */
class HttpHelper
{
    /** Default request timeout in seconds. */
    public const DEFAULT_TIMEOUT = 15;

    /**
     * Issue a GET. Returns [status, body, parsed_json_or_null].
     *
     * @param array<string,string> $headers
     * @return array{int, string, mixed}
     */
    public static function get(
        string $url,
        array $headers = [],
        ?array $query = null,
        ?array $basicAuth = null,
        int $timeout = self::DEFAULT_TIMEOUT,
    ): array {
        if ($query !== null && !empty($query)) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $url .= $sep . http_build_query($query);
        }
        return self::request('GET', $url, $headers, null, $basicAuth, $timeout);
    }

    /**
     * Issue a POST with a JSON body. Returns [status, body, parsed].
     *
     * @param array<string,string> $headers
     * @return array{int, string, mixed}
     */
    public static function postJson(
        string $url,
        mixed $body,
        array $headers = [],
        ?array $basicAuth = null,
        int $timeout = self::DEFAULT_TIMEOUT,
    ): array {
        $headers['Content-Type'] = $headers['Content-Type']
            ?? $headers['content-type']
            ?? 'application/json';
        $headers['Accept'] = $headers['Accept']
            ?? $headers['accept']
            ?? 'application/json';
        $encoded = $body === null ? '' : (string) json_encode($body);
        return self::request('POST', $url, $headers, $encoded, $basicAuth, $timeout);
    }

    /**
     * Look up a URL override env var and rewrite the URL host/scheme
     * to point at the audit fixture when set. Skills call this with
     * the documented env name (e.g. `WEB_SEARCH_BASE_URL`) and the
     * production URL; the helper returns either the original URL or
     * an audit-fixture rewrite, preserving path + query.
     */
    public static function applyBaseUrlOverride(string $url, string $envVarName): string
    {
        $override = getenv($envVarName);
        if (!is_string($override) || $override === '') {
            return $url;
        }
        $override = rtrim($override, '/');

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            // Original URL malformed — just append a path-suffix to the
            // override and hope the fixture is a catch-all. Skills will
            // surface the failure if not.
            return $override;
        }
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return $override . $path . $query;
    }

    /**
     * Inner request engine using cURL. Other skills may use this
     * directly when they need PUT/DELETE; the GET / POST helpers
     * above cover the common cases.
     *
     * @param array<string,string> $headers
     * @param array{0:string,1:string}|null $basicAuth [user, password]
     * @return array{int, string, mixed}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?array $basicAuth = null,
        int $timeout = self::DEFAULT_TIMEOUT,
    ): array {
        $ch = \curl_init();
        if ($ch === false) {
            throw new \RuntimeException('curl_init() failed');
        }

        // Format headers for cURL ("Name: value").
        $rawHeaders = [];
        $hasContentType = false;
        $hasAccept = false;
        foreach ($headers as $name => $value) {
            $rawHeaders[] = "{$name}: {$value}";
            $lc = strtolower($name);
            if ($lc === 'content-type') {
                $hasContentType = true;
            } elseif ($lc === 'accept') {
                $hasAccept = true;
            }
        }
        if (!$hasAccept) {
            $rawHeaders[] = 'Accept: application/json';
        }
        if ($body !== null && !$hasContentType) {
            $rawHeaders[] = 'Content-Type: application/json';
        }

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => max(2, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $rawHeaders,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERAGENT => 'signalwire-agents-php/1.0',
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if ($basicAuth !== null && count($basicAuth) === 2) {
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opts[CURLOPT_USERPWD] = $basicAuth[0] . ':' . $basicAuth[1];
        }

        \curl_setopt_array($ch, $opts);
        $rawBody = \curl_exec($ch);
        $errno = \curl_errno($ch);
        $error = \curl_error($ch);
        $status = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);

        if ($rawBody === false) {
            throw new \RuntimeException(
                "HTTP {$method} {$url} failed (curl errno={$errno}): {$error}"
            );
        }

        $bodyStr = (string) $rawBody;
        $parsed = null;
        if ($bodyStr !== '') {
            $decoded = json_decode($bodyStr, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $parsed = $decoded;
            }
        }

        return [$status, $bodyStr, $parsed];
    }
}
