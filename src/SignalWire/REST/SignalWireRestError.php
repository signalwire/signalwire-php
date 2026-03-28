<?php

declare(strict_types=1);

namespace SignalWire\REST;

/**
 * Exception thrown when a SignalWire REST API request fails with a non-2xx status.
 */
class SignalWireRestError extends \RuntimeException
{
    private int $statusCode;
    private string $responseBody;

    public function __construct(string $message, int $statusCode = 0, string $responseBody = '')
    {
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;

        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    public function __toString(): string
    {
        return sprintf(
            'SignalWireRestError: %s (HTTP %d): %s',
            $this->getMessage(),
            $this->statusCode,
            $this->responseBody
        );
    }
}
