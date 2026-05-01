<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * 10DLC campaign management — get, update (PUT), list_numbers,
 * list_orders, create_order.
 */
class RegistryCampaigns
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
    public function get(string $campaignId): array
    {
        return $this->http->get($this->basePath . '/' . $campaignId);
    }

    /**
     * Update uses PUT (not PATCH) to mirror the Python ``RegistryCampaigns``.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(string $campaignId, array $body): array
    {
        return $this->http->put($this->basePath . '/' . $campaignId, $body);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listNumbers(string $campaignId, array $params = []): array
    {
        return $this->http->get($this->basePath . '/' . $campaignId . '/numbers', $params);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listOrders(string $campaignId, array $params = []): array
    {
        return $this->http->get($this->basePath . '/' . $campaignId . '/orders', $params);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function createOrder(string $campaignId, array $body): array
    {
        return $this->http->post($this->basePath . '/' . $campaignId . '/orders', $body);
    }
}
