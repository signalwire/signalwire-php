<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * 10DLC assignment order management — read-only, retrieve by id.
 */
class RegistryOrders
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

    /** @return array<string,mixed> */
    public function get(string $orderId): array
    {
        return $this->http->get($this->basePath . '/' . $orderId);
    }
}
