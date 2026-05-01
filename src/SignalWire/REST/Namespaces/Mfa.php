<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Multi-factor authentication via SMS or phone call.
 */
class Mfa
{
    private HttpClient $http;
    private string $basePath = '/api/relay/rest/mfa';

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function sms(array $body): array
    {
        return $this->http->post($this->basePath . '/sms', $body);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function call(array $body): array
    {
        return $this->http->post($this->basePath . '/call', $body);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function verify(string $requestId, array $body): array
    {
        return $this->http->post($this->basePath . '/' . $requestId . '/verify', $body);
    }
}
