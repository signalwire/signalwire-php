<?php

declare(strict_types=1);

namespace SignalWire\REST;

/**
 * Low-level HTTP client for SignalWire REST APIs.
 *
 * Uses cURL for HTTP requests, Basic Auth with project_id:token,
 * and returns parsed JSON responses as associative arrays.
 */
class HttpClient
{
    private string $projectId;
    private string $token;
    /** @var non-empty-string */
    private string $baseUrl;
    private string $authHeader;

    /** @var string User-Agent header sent with every request. */
    private string $userAgent = 'signalwire-agents-php-rest/1.0';

    /** @var int Request timeout in seconds. */
    private int $timeout = 30;

    /**
     * Path to a CA bundle (PEM) used to verify the server certificate over
     * HTTPS, or null to use cURL's default trust store. TLS peer verification
     * is always on (cURL's default); this only selects which CA to trust.
     *
     * @var non-empty-string|null
     */
    private ?string $caBundle;

    /**
     * @param non-empty-string $baseUrl Fully-qualified API base URL (the caller,
     *   RestClient, guarantees this is non-empty at its source).
     * @param string|null $caBundle Optional CA bundle (PEM) for HTTPS peer
     *   verification. Falls back to the SIGNALWIRE_CA_FILE / SSL_CERT_FILE
     *   env vars (PHP's cURL does not honor those env vars on its own).
     */
    public function __construct(string $projectId, string $token, string $baseUrl, ?string $caBundle = null)
    {
        $this->projectId = $projectId;
        $this->token = $token;
        $trimmedBaseUrl = rtrim($baseUrl, '/');
        // rtrim only strips trailing '/', and $baseUrl is non-empty by the
        // caller's contract; PHPStan cannot infer non-empty through rtrim, so
        // carry the true invariant forward.
        assert($trimmedBaseUrl !== '');
        $this->baseUrl = $trimmedBaseUrl;
        $this->authHeader = 'Basic ' . base64_encode($projectId . ':' . $token);
        $this->caBundle = self::resolveCaBundle($caBundle);
    }

    /**
     * Resolve the effective CA bundle: explicit arg wins, otherwise the
     * SIGNALWIRE_CA_FILE then SSL_CERT_FILE env vars (which cURL itself does
     * not consult), otherwise null (cURL's built-in default store).
     */
    /**
     * @return non-empty-string|null
     */
    private static function resolveCaBundle(?string $explicit): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }
        foreach (['SIGNALWIRE_CA_FILE', 'SSL_CERT_FILE'] as $envVar) {
            $val = getenv($envVar);
            if (is_string($val) && $val !== '') {
                return $val;
            }
        }
        return null;
    }

    public function getProjectId(): string
    {
        return $this->projectId;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getAuthHeader(): string
    {
        return $this->authHeader;
    }

    // -----------------------------------------------------------------
    // Public HTTP methods
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $params Query-string parameters (scalars are
     *   url-encoded; nested arrays use PHP's bracketed query syntax).
     * @return array<string,mixed>
     */
    public function get(string $path, array $params = []): array
    {
        return $this->request('GET', $path, $params);
    }

    /**
     * @param array<string,mixed> $data JSON body payload.
     * @return array<string,mixed>
     */
    public function post(string $path, array $data = []): array
    {
        return $this->request('POST', $path, [], $data);
    }

    /**
     * @param array<string,mixed> $data JSON body payload.
     * @return array<string,mixed>
     */
    public function put(string $path, array $data = []): array
    {
        return $this->request('PUT', $path, [], $data);
    }

    /**
     * @param array<string,mixed> $data JSON body payload.
     * @return array<string,mixed>
     */
    public function patch(string $path, array $data = []): array
    {
        return $this->request('PATCH', $path, [], $data);
    }

    /**
     * @return array<string,mixed>
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    // -----------------------------------------------------------------
    // Paginated list support
    // -----------------------------------------------------------------

    /**
     * Return a generator that follows `next` links automatically.
     *
     * Expects the API to return `{ "data": [...], "links": { "next": "..." } }`
     * or similar paginated envelope.  Each yield is one page (array of items).
     *
     * @param array<string,mixed> $params Initial query-string parameters.
     * @return \Generator<int,array<string,mixed>>
     */
    public function listAll(string $path, array $params = []): \Generator
    {
        $currentPath = $path;
        $currentParams = $params;

        // Exits via the `break` below when there is no `links.next` page.
        while (true) {
            $response = $this->get($currentPath, $currentParams);

            // Yield the items from the current page.
            $data = $response['data'] ?? $response;
            if (is_array($data)) {
                $page = [];
                foreach ($data as $key => $value) {
                    $page[(string) $key] = $value;
                }
                yield $page;
            }

            // Determine if there is a next page.
            $links = $response['links'] ?? null;
            $nextUrl = is_array($links) ? ($links['next'] ?? null) : null;
            if (!is_string($nextUrl)) {
                break;
            }

            // The next URL may be absolute or path-only. Strip the base if absolute.
            if (str_starts_with($nextUrl, 'http')) {
                $parsed = parse_url($nextUrl);
                $currentPath = is_array($parsed) ? ($parsed['path'] ?? '') : '';
                $currentParams = self::parseQuery(is_array($parsed) ? ($parsed['query'] ?? '') : '');
            } else {
                $parts = explode('?', $nextUrl, 2);
                $currentPath = $parts[0];
                $currentParams = self::parseQuery($parts[1] ?? '');
            }
        }
    }

    /**
     * Parse a query string into a string-keyed parameter map. parse_str() can
     * yield integer top-level keys (e.g. "0=x"); normalise them to strings so
     * the result matches the query-param contract.
     *
     * @return array<string, mixed>
     */
    private static function parseQuery(string $query): array
    {
        $parsed = [];
        parse_str($query, $parsed);
        $out = [];
        foreach ($parsed as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Internal request engine
    // -----------------------------------------------------------------

    /**
     * @param non-empty-string $method HTTP verb (GET/POST/PUT/PATCH/DELETE).
     * @param array<string,mixed> $params  Query-string parameters.
     * @param array<string,mixed>|null $body JSON body (for POST/PUT/PATCH).
     * @return array<string,mixed>
     * @throws SignalWireRestError on non-2xx responses.
     */
    private function request(string $method, string $path, array $params = [], ?array $body = null): array
    {
        $url = $this->baseUrl . $path;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: ' . $this->authHeader,
            'User-Agent: ' . $this->userAgent,
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            // Real TLS verification is always on (these are cURL's defaults,
            // set explicitly for clarity). Never disabled.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        // Trust a specific CA bundle when configured (PHP's cURL ignores the
        // SSL_CERT_FILE / CURL_CA_BUNDLE env vars, so we wire CAINFO directly).
        if ($this->caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
        }

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $encodedBody = json_encode($body);
            if ($encodedBody === false) {
                throw new \RuntimeException('json_encode failed for request body');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedBody);
        }

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // cURL-level failure (network error, DNS, etc.)
        if ($responseBody === false) {
            throw new SignalWireRestError(
                sprintf('%s %s failed: %s', $method, $path, $curlError),
                0,
                ''
            );
        }

        // Non-2xx HTTP status
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new SignalWireRestError(
                sprintf('%s %s returned %d', $method, $path, $httpCode),
                $httpCode,
                (string) $responseBody
            );
        }

        // 204 No Content or empty body
        if ($httpCode === 204 || $responseBody === '') {
            return [];
        }

        $decoded = json_decode((string) $responseBody, true);

        if (!is_array($decoded)) {
            return ['raw' => (string) $responseBody];
        }

        // A decoded JSON object has string keys; a top-level JSON array has
        // integer keys. Normalise to string keys to honour the contract.
        $result = [];
        foreach ($decoded as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
