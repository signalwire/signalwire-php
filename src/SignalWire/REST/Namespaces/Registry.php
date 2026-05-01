<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * 10DLC Campaign Registry namespace — brands, campaigns, orders, numbers.
 *
 * Mirrors Python ``signalwire.rest.namespaces.registry.RegistryNamespace``.
 * All registry endpoints sit under ``/api/relay/rest/registry/beta``.
 */
class Registry
{
    private const BASE = '/api/relay/rest/registry/beta';

    private HttpClient $http;
    private RegistryBrands $brands;
    private RegistryCampaigns $campaigns;
    private RegistryOrders $orders;
    private RegistryNumbers $numbers;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
        $this->brands = new RegistryBrands($http, self::BASE . '/brands');
        $this->campaigns = new RegistryCampaigns($http, self::BASE . '/campaigns');
        $this->orders = new RegistryOrders($http, self::BASE . '/orders');
        $this->numbers = new RegistryNumbers($http, self::BASE . '/numbers');
    }

    public function brands(): RegistryBrands
    {
        return $this->brands;
    }

    public function campaigns(): RegistryCampaigns
    {
        return $this->campaigns;
    }

    public function orders(): RegistryOrders
    {
        return $this->orders;
    }

    public function numbers(): RegistryNumbers
    {
        return $this->numbers;
    }

    public function getHttp(): HttpClient
    {
        return $this->http;
    }
}
