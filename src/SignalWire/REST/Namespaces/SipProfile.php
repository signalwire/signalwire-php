<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Project SIP profile (singleton resource) — get + update (PUT).
 */
class SipProfile
{
    private HttpClient $http;
    private string $basePath = '/api/relay/rest/sip_profile';

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        return $this->http->get($this->basePath);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(array $body): array
    {
        return $this->http->put($this->basePath, $body);
    }
}
