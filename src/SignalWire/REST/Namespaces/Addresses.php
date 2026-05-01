<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Addresses namespace — list / create / get / delete (no update).
 */
class Addresses
{
    private HttpClient $http;
    private string $basePath = '/api/relay/rest/addresses';

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

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function create(array $body): array
    {
        return $this->http->post($this->basePath, $body);
    }

    /** @return array<string,mixed> */
    public function get(string $addressId): array
    {
        return $this->http->get($this->basePath . '/' . $addressId);
    }

    /** @return array<string,mixed> */
    public function delete(string $addressId): array
    {
        return $this->http->delete($this->basePath . '/' . $addressId);
    }
}
