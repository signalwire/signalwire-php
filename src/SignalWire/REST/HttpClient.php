<?php

declare(strict_types=1);

namespace SignalWire\REST;

/**
 * Low-level HTTP client for SignalWire REST APIs.
 *
 * Uses cURL for HTTP requests, Basic Auth with project_id:token,
 * and returns parsed JSON responses as associative arrays.
 *
 * Transport behavior (per-attempt timeout, opt-in idempotency-aware retry with
 * exponential backoff + Retry-After, cooperative abort) is governed by a
 * {@see RequestOptions} envelope resolved per request: a per-call override
 * shallow-merges over the client default, over the built-in defaults. See
 * {@see RequestOptions} for the pinned contract.
 */
class HttpClient
{
    private string $projectId;
    private string $token;
    /** @var non-empty-string */
    private string $baseUrl;
    private string $authHeader;

    /**
     * @var string User-Agent header sent with every request. Version derived
     *   from the single-source ``SignalWire\SignalWire::VERSION`` constant so
     *   the UA, the ``VERSION`` constant, and ``composer.json`` never drift.
     */
    private string $userAgent = 'signalwire-agents-php-rest/' . \SignalWire\SignalWire::VERSION;

    /**
     * The client-default request options applied to every request (a
     * per-request override shallow-merges over this). Null = built-in defaults
     * for every field. Mirrors Python's ``HttpClient(request_options=...)``.
     */
    private ?RequestOptions $requestOptions;

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
     * @param RequestOptions|null $requestOptions Client-default transport
     *   options applied to every request (per-request overrides merge over it).
     *   Ordered before ``$caBundle`` to match the Python reference's
     *   ``HttpClient(project, token, host, request_options)`` positional shape;
     *   ``$caBundle`` is the php-only trailing extra.
     * @param string|null $caBundle Optional CA bundle (PEM) for HTTPS peer
     *   verification. Falls back to the fleet-standard SIGNALWIRE_REST_CA_FILE
     *   env var (A5 hard-cut; PHP's cURL does not honor it on its own).
     */
    public function __construct(
        string $projectId,
        string $token,
        string $baseUrl,
        ?RequestOptions $requestOptions = null,
        ?string $caBundle = null
    ) {
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
        $this->requestOptions = $requestOptions;
    }

    /**
     * Resolve the effective CA bundle: explicit arg wins, otherwise the
     * fleet-standard SIGNALWIRE_REST_CA_FILE env var (A5 hard-cut, this EXACT
     * name only — matches the python reference rest/_base.py which reads
     * SIGNALWIRE_REST_CA_FILE into ``session.verify``; cURL itself does not
     * consult it), otherwise null (cURL's built-in default store).
     */
    /**
     * @return non-empty-string|null
     */
    private static function resolveCaBundle(?string $explicit): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }
        $val = getenv('SIGNALWIRE_REST_CA_FILE');
        if (is_string($val) && $val !== '') {
            return $val;
        }
        return null;
    }

    /** The project ID. */
    public function getProjectId(): string
    {
        return $this->projectId;
    }

    /** The API token. */
    public function getToken(): string
    {
        return $this->token;
    }

    /** The base URL. */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /** The Basic-auth Authorization header value. */
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
     * @param RequestOptions|null $requestOptions Per-request override.
     * @return array<string,mixed>
     */
    public function get(string $path, array $params = [], ?RequestOptions $requestOptions = null): array
    {
        return $this->request('GET', $path, $params, null, $requestOptions);
    }

    /**
     * @param array<string,mixed> $data JSON body payload.
     * @param RequestOptions|null $requestOptions Per-request override.
     * @return array<string,mixed>
     */
    public function post(string $path, array $data = [], ?RequestOptions $requestOptions = null): array
    {
        return $this->request('POST', $path, [], $data, $requestOptions);
    }

    /**
     * @param array<string,mixed> $data JSON body payload.
     * @param RequestOptions|null $requestOptions Per-request override.
     * @return array<string,mixed>
     */
    public function put(string $path, array $data = [], ?RequestOptions $requestOptions = null): array
    {
        return $this->request('PUT', $path, [], $data, $requestOptions);
    }

    /**
     * @param array<string,mixed> $data JSON body payload.
     * @param RequestOptions|null $requestOptions Per-request override.
     * @return array<string,mixed>
     */
    public function patch(string $path, array $data = [], ?RequestOptions $requestOptions = null): array
    {
        return $this->request('PATCH', $path, [], $data, $requestOptions);
    }

    /**
     * @param RequestOptions|null $requestOptions Per-request override.
     * @return array<string,mixed>
     */
    public function delete(string $path, ?RequestOptions $requestOptions = null): array
    {
        return $this->request('DELETE', $path, [], null, $requestOptions);
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
    // Request-options resolution + retry policy
    // -----------------------------------------------------------------

    /**
     * Report whether the abort signal is "set" (cancellation requested). The
     * signal is either a ``callable(): bool`` or an object exposing
     * ``isSet(): bool``. Any other shape is treated as "not set".
     *
     * @param (callable(): bool)|object|null $signal
     */
    private static function abortRequested($signal): bool
    {
        if ($signal === null) {
            return false;
        }
        if (is_callable($signal)) {
            return (bool) $signal();
        }
        if (method_exists($signal, 'isSet')) {
            /** @var callable(): mixed $fn */
            $fn = [$signal, 'isSet'];
            return (bool) $fn();
        }
        return false;
    }

    /**
     * Backoff sleep between retries. A seam so tests can drive the retry loop
     * without wall-clock delay (the mock proves attempt ORDERING, not real
     * time): with retry_backoff=0 the computed delay is 0 and this is a no-op.
     */
    protected function sleepSeconds(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    /**
     * Parse a ``Retry-After`` header (delta-seconds form) from a raw response
     * header block if present, else null (HTTP-date form falls back to the
     * computed exponential backoff).
     */
    /**
     * Parse a raw HTTP response-header block into a name→value map (last header
     * block wins for redirects; the status line and blank lines are skipped).
     * §6.6: fed to SignalWireRestError so a caller can read the platform
     * request-id and other response headers off a failed request.
     *
     * @return array<string, string>
     */
    private static function parseHeaders(string $rawHeaders): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue; // status line ("HTTP/1.1 429 ...") or blank
            }
            $name = trim(substr($line, 0, $pos));
            if ($name === '') {
                continue;
            }
            $out[$name] = trim(substr($line, $pos + 1));
        }
        return $out;
    }

    private static function retryAfterSeconds(string $rawHeaders): ?float
    {
        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            if (strcasecmp(trim(substr($line, 0, $pos)), 'Retry-After') === 0) {
                $value = trim(substr($line, $pos + 1));
                if ($value !== '' && is_numeric($value)) {
                    return (float) $value;
                }
                return null; // HTTP-date form: use computed backoff
            }
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Internal request engine
    // -----------------------------------------------------------------

    /**
     * @param non-empty-string $method HTTP verb (GET/POST/PUT/PATCH/DELETE).
     * @param array<string,mixed> $params  Query-string parameters.
     * @param array<string,mixed>|null $body JSON body (for POST/PUT/PATCH).
     * @param RequestOptions|null $requestOptions Per-request override.
     * @return array<string,mixed>
     * @throws SignalWireRestError on a non-2xx response; a SignalWireRestTransportError
     *   (a member of that family) on a transport-level failure (connection
     *   refused / DNS / reset / TLS / timeout — the request never reached a
     *   response) or a set abort signal.
     */
    private function request(
        string $method,
        string $path,
        array $params = [],
        ?array $body = null,
        ?RequestOptions $requestOptions = null
    ): array {
        $url = $this->baseUrl . $path;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $opts = RequestOptions::resolve($this->requestOptions, $requestOptions);
        $retries = $opts['retries'];
        $retryBackoff = $opts['retryBackoff'];

        // total attempts = retries + 1; retry on a retryable status
        // (idempotency-aware) or a transport error, honoring Retry-After then
        // exponential backoff. abort_signal is checked cooperatively before
        // every attempt (PHP's cURL is blocking, so between-attempt is the
        // honest portable minimum).
        $attempt = 0;
        while (true) {
            $attempt++;

            if (self::abortRequested($opts['abortSignal'])) {
                // Cancelled before this attempt — surface as the transport-error
                // family (no response was produced), not a bare exception.
                throw new SignalWireRestTransportError(
                    sprintf('%s %s cancelled by abort_signal', $method, $path),
                    $url,
                    $method
                );
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
                // Per-attempt wall-clock timeout from the resolved options.
                // TIMEOUT_MS lets a sub-second timeout still take effect.
                CURLOPT_TIMEOUT_MS     => (int) round($opts['timeout'] * 1000),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CUSTOMREQUEST  => $method,
                // Capture response headers so we can honor Retry-After.
                CURLOPT_HEADER         => true,
                // Real TLS verification is always on (these are cURL's defaults,
                // set explicitly for clarity). Never disabled.
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            // Trust a specific CA bundle when configured (resolved from the
            // SIGNALWIRE_REST_CA_FILE env var or the explicit arg; PHP's cURL
            // does not consult it on its own, so we wire CAINFO directly).
            if ($this->caBundle !== null) {
                curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
            }

            if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                $encodedBody = json_encode($body);
                if ($encodedBody === false) {
                    curl_close($ch);
                    throw new \RuntimeException('json_encode failed for request body');
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedBody);
            }

            $rawResponse = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // Transport-level failure: the request never reached a response
            // (connection refused, DNS failure, connection reset, TLS error,
            // timeout). Retry if attempts remain, else wrap in the typed
            // SignalWireRestError family (SignalWireRestTransportError) so a
            // caller catching SignalWireRestError handles it too, instead of a
            // bare cURL error leaking out.
            if ($rawResponse === false) {
                if ($attempt <= $retries) {
                    $this->sleepSeconds($retryBackoff * (2 ** ($attempt - 1)));
                    continue;
                }
                throw new SignalWireRestTransportError(
                    sprintf('%s %s failed to reach the server: %s', $method, $path, $curlError),
                    $url,
                    $method
                );
            }

            $rawResponse = (string) $rawResponse;
            $rawHeaders = substr($rawResponse, 0, $headerSize);
            $responseBody = substr($rawResponse, $headerSize);

            // Non-2xx HTTP status.
            if ($httpCode < 200 || $httpCode >= 300) {
                if ($attempt <= $retries
                    && RequestOptions::statusIsRetryable($method, $httpCode, $opts)) {
                    $delay = self::retryAfterSeconds($rawHeaders);
                    if ($delay === null) {
                        $delay = $retryBackoff * (2 ** ($attempt - 1));
                    }
                    $this->sleepSeconds($delay);
                    continue;
                }
                // Include the server's response body in the message (matching the
                // python reference's "{method} {url} returned {status}: {body}") so a
                // caller who logs only getMessage() still sees why the request failed.
                throw new SignalWireRestError(
                    sprintf('%s %s returned %d: %s', $method, $url, $httpCode, $responseBody),
                    $httpCode,
                    $responseBody,
                    $url,
                    $method,
                    self::parseHeaders($rawHeaders)
                );
            }

            // 204 No Content or empty body.
            if ($httpCode === 204 || $responseBody === '') {
                return [];
            }

            $decoded = json_decode($responseBody, true);

            if (!is_array($decoded)) {
                return ['raw' => $responseBody];
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
}
