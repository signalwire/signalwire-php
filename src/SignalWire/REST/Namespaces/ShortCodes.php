<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Short codes management — read + update (PUT) only.
 */
class ShortCodes
{
    private HttpClient $http;
    private string $basePath = '/api/relay/rest/short_codes';

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function list(array $params = []): array
    {
        return $this->http->get($this->basePath, $params);
    }

    /** @return array<string,mixed> */
    public function get(string $shortCodeId): array
    {
        return $this->http->get($this->basePath . '/' . $shortCodeId);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(string $shortCodeId, array $body): array
    {
        return $this->http->put($this->basePath . '/' . $shortCodeId, $body);
    }
}
