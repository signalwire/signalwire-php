<?php

declare(strict_types=1);

namespace SignalWire\Utils;

/**
 * An HTTP fetcher for user-supplied URLs.
 *
 * Checking a URL with {@see UrlValidator::validateUrl()} before fetching it is
 * not enough on its own: the server can redirect to an internal address, and
 * the hostname can resolve differently when the connection is made. This
 * session checks the URL of every request it sends, redirects included, and
 * refuses a response whose connected peer is a blocked address.
 * `SWML_ALLOW_PRIVATE_URLS` turns both checks off, as it does for
 * `validateUrl()`.
 *
 * It ignores HTTP_PROXY / HTTPS_PROXY, because through a proxy the connection
 * check cannot apply. Set `SWML_URL_FETCH_USE_PROXY` to use them, with a proxy
 * that restricts destinations itself.
 *
 * @internal held by the skills that fetch user-supplied URLs (Spider::$session).
 */
final class PublicHttpSession
{
    /** Redirects followed in one fetch before giving up. */
    private const MAX_REDIRECTS = 10;

    /**
     * Headers sent on every request.
     *
     * @var array<string, string>
     */
    public array $headers = [];

    /** @param bool $allowPrivate When true, private and internal addresses are allowed. */
    public function __construct(private readonly bool $allowPrivate = false)
    {
    }

    /**
     * GET a URL. Returns `[status, body, parsedJsonOrNull]`.
     *
     * @param array<string, string> $headers Extra headers for this request.
     * @return array{int, string, mixed}
     * @throws \RuntimeException for a private, internal or invalid URL (any
     *   hop), a blocked peer, too many redirects, or a transport failure.
     */
    public function get(string $url, int $timeout = 10, bool $allowRedirects = true, array $headers = []): array
    {
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$status, $body, $location] = $this->send($current, $timeout, $headers + $this->headers);
            if (!$allowRedirects || $location === null || $status < 300 || $status >= 400) {
                $parsed = json_decode($body, true);
                return [$status, $body, json_last_error() === JSON_ERROR_NONE ? $parsed : null];
            }
            $current = self::resolveLocation($current, $location);
        }
        throw new \RuntimeException('Too many redirects fetching ' . self::redact($url));
    }

    /**
     * One request, no redirect following. Returns `[status, body, location]`.
     *
     * @param array<string, string> $headers
     * @return array{int, string, ?string}
     */
    private function send(string $url, int $timeout, array $headers): array
    {
        if ($url === '' || !UrlValidator::validateUrl($url, $this->allowPrivate)) {
            throw new \RuntimeException('URL rejected: ' . self::redact($url) . ' is private, internal or invalid');
        }
        $ch = \curl_init();
        if ($ch === false) {
            throw new \RuntimeException('curl_init() failed');
        }
        $raw = [];
        foreach ($headers as $name => $value) {
            $raw[] = "{$name}: {$value}";
        }
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(2, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $raw,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (!self::envTruthy('SWML_URL_FETCH_USE_PROXY')) {
            // Connect directly so the peer-address check applies.
            $opts[CURLOPT_PROXY] = '';
        }
        \curl_setopt_array($ch, $opts);
        $response = \curl_exec($ch);
        if (!is_string($response)) {
            $err = \curl_error($ch);
            \curl_close($ch);
            throw new \RuntimeException('Request failed: ' . $err);
        }
        $status = (int) \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) \curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $peer = \curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        \curl_close($ch);

        // The hostname can resolve differently at connect time than when it
        // was checked: refuse a response from a blocked peer.
        if ($peer !== ''
            && !UrlValidator::validateUrl('http://' . (str_contains($peer, ':') ? "[{$peer}]" : $peer) . '/', $this->allowPrivate)) {
            throw new \RuntimeException('Connection refused: ' . self::redact($url) . ' reached a blocked address');
        }

        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $location = null;
        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            if (stripos($line, 'location:') === 0) {
                $location = trim(substr($line, 9));
            }
        }
        return [$status, $body, $location];
    }

    private static function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$host}{$port}{$location}";
        }
        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
        return "{$scheme}://{$host}{$port}{$dir}{$location}";
    }

    /** The URL with any query string and credentials dropped, for messages. */
    private static function redact(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '<invalid url>';
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . $port . ($parts['path'] ?? '');
    }

    private static function envTruthy(string $name): bool
    {
        $v = getenv($name);
        return is_string($v) && in_array(strtolower($v), ['1', 'true', 'yes'], true);
    }
}
