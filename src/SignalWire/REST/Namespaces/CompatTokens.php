<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Compat API token management.
 *
 * Surface is create / update (PATCH) / delete only.
 */
class CompatTokens
{
    private HttpClient $http;
    private string $basePath;

    public function __construct(HttpClient $http, string $basePath)
    {
        $this->http = $http;
        $this->basePath = $basePath;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function create(array $body): array
    {
        return $this->http->post($this->basePath, $body);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(string $tokenId, array $body): array
    {
        return $this->http->patch($this->basePath . '/' . $tokenId, $body);
    }

    /** @return array<string,mixed> */
    public function delete(string $tokenId): array
    {
        return $this->http->delete($this->basePath . '/' . $tokenId);
    }
}
