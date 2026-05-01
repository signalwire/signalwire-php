<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Compat account / sub-project management.
 *
 * Mirrors Python ``CompatAccounts``: the only resource that lives under
 * ``/api/laml/2010-04-01/Accounts`` (rather than ``.../Accounts/{Sid}/...``).
 */
class CompatAccounts
{
    private HttpClient $http;
    private string $basePath = '/api/laml/2010-04-01/Accounts';

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function list(array $params = []): array
    {
        return $this->http->get($this->basePath, $params);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function create(array $body): array
    {
        return $this->http->post($this->basePath, $body);
    }

    /** @return array<string,mixed> */
    public function get(string $sid): array
    {
        return $this->http->get($this->basePath . '/' . $sid);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(string $sid, array $body): array
    {
        return $this->http->post($this->basePath . '/' . $sid, $body);
    }
}
