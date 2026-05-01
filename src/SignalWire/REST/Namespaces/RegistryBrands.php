<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * 10DLC brand management — list, create, get, list_campaigns, create_campaign.
 */
class RegistryBrands
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
    public function get(string $brandId): array
    {
        return $this->http->get($this->basePath . '/' . $brandId);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listCampaigns(string $brandId, array $params = []): array
    {
        return $this->http->get($this->basePath . '/' . $brandId . '/campaigns', $params);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function createCampaign(string $brandId, array $body): array
    {
        return $this->http->post($this->basePath . '/' . $brandId . '/campaigns', $body);
    }
}
