<?php

declare(strict_types=1);

namespace SignalWire\REST;

/**
 * Exception thrown when a SignalWire REST API request fails with a non-2xx status.
 *
 * Carries the full failure envelope — status code, response body, request URL,
 * and HTTP method — so a caller can branch on it (distinguish 400/404/422 and
 * read the server's error body) rather than parsing a bare message. Mirrors the
 * Python reference's ``SignalWireRestError(status_code, body, url, method)``.
 */
class SignalWireRestError extends \RuntimeException
{
    /**
     * Response header names carrying the platform request id, in preference
     * order (matched case-insensitively). Mirrors the python reference's
     * ``_REQUEST_ID_HEADERS``.
     */
    private const REQUEST_ID_HEADERS = [
        'x-request-id',
        'x-signalwire-request-id',
        'request-id',
        'x-amzn-requestid',
    ];

    private int $statusCode;
    private string $body;
    private string $url;
    private string $method;
    /** @var array<string, string>|null */
    private ?array $headers;
    private ?string $requestId;

    /**
     * @param array<string, string>|null $headers Response header map (null for a
     *                                             transport error that produced no response).
     */
    public function __construct(
        string $message,
        int $statusCode = 0,
        string $body = '',
        string $url = '',
        string $method = '',
        ?array $headers = null
    ) {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->url = $url;
        $this->method = $method;
        $this->headers = $headers;
        $this->requestId = self::extractRequestId($headers);

        // §6.6 error-observability: surface the platform request-id in the message
        // (client-side observability, no wire-contract change).
        if ($this->requestId !== null && $this->requestId !== '') {
            $message .= sprintf(' (request-id: %s)', $this->requestId);
        }

        parent::__construct($message, $statusCode);
    }

    /**
     * Pull the platform request id from a response-header map, matching the known
     * header names case-insensitively. Null when absent or headerless.
     *
     * @param array<string, string>|null $headers
     */
    private static function extractRequestId(?array $headers): ?string
    {
        if ($headers === null || $headers === []) {
            return null;
        }
        $lowered = [];
        foreach ($headers as $name => $value) {
            $lowered[strtolower($name)] = $value;
        }
        foreach (self::REQUEST_ID_HEADERS as $name) {
            if (isset($lowered[$name])) {
                return $lowered[$name];
            }
        }
        return null;
    }

    /** HTTP status code of the failed response (0 for a transport-level failure). */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** Raw response body returned by the server (may be JSON or plain text). */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Alias for {@see getBody()} — retained for backward compatibility.
     *
     * @deprecated use getBody()
     */
    public function getResponseBody(): string
    {
        return $this->body;
    }

    /** Request URL (or path) that produced the failure. */
    public function getUrl(): string
    {
        return $this->url;
    }

    /** HTTP method of the failed request. */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Response header map, or null for a transport-level failure that produced no
     * response. §6.6 client-side observability — no wire-contract change.
     *
     * @return array<string, string>|null
     */
    public function getHeaders(): ?array
    {
        return $this->headers;
    }

    /**
     * Platform request id pulled from the response headers (x-request-id /
     * x-signalwire-request-id / request-id / x-amzn-requestid), or null when
     * absent. Quote this to SignalWire support to trace a failed request.
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function __toString(): string
    {
        return sprintf(
            'SignalWireRestError: %s (HTTP %d): %s',
            $this->getMessage(),
            $this->statusCode,
            $this->body
        );
    }
}
