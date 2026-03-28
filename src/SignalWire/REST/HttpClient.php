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
    private string $baseUrl;
    private string $authHeader;

    /** @var string User-Agent header sent with every request. */
    private string $userAgent = 'signalwire-agents-php-rest/1.0';

    /** @var int Request timeout in seconds. */
    private int $timeout = 30;

    public function __construct(string $projectId, string $token, string $baseUrl)
    {
        $this->projectId = $projectId;
        $this->token = $token;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->authHeader = 'Basic ' . base64_encode($projectId . ':' . $token);
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
     * @param array<string,string> $params Query-string parameters.
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
     * @param array<string,string> $params Initial query-string parameters.
     * @return \Generator<int,array<string,mixed>>
     */
    public function listAll(string $path, array $params = []): \Generator
    {
        $currentPath = $path;
        $currentParams = $params;

        while ($currentPath !== null) {
            $response = $this->get($currentPath, $currentParams);

            // Yield the items from the current page.
            $data = $response['data'] ?? $response;
            if (is_array($data)) {
                yield $data;
            }

            // Determine if there is a next page.
            $nextUrl = $response['links']['next'] ?? null;
            if ($nextUrl === null) {
                break;
            }

            // The next URL may be absolute or path-only. Strip the base if absolute.
            if (str_starts_with($nextUrl, 'http')) {
                $parsed = parse_url($nextUrl);
                $currentPath = $parsed['path'] ?? '';
                parse_str($parsed['query'] ?? '', $currentParams);
            } else {
                $parts = explode('?', $nextUrl, 2);
                $currentPath = $parts[0];
                parse_str($parts[1] ?? '', $currentParams);
            }
        }
    }

    // -----------------------------------------------------------------
    // Internal request engine
    // -----------------------------------------------------------------

    /**
     * @param array<string,string> $params  Query-string parameters.
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
        ]);

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
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

        return $decoded;
    }
}
