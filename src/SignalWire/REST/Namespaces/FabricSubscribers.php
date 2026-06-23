<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudWithAddresses;

/**
 * Subscribers with SIP endpoint management. Uses PUT for updates
 * (``PUT /api/fabric/resources/subscribers/{id}``) and inherits
 * ``listAddresses`` from CrudWithAddresses.
 */
class FabricSubscribers extends CrudWithAddresses
{
    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function listSipEndpoints(string $subscriberId, array $params = []): array
    {
        return $this->client->get(
            $this->basePath . '/' . $subscriberId . '/sip_endpoints',
            $params
        );
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function createSipEndpoint(string $subscriberId, array $body): array
    {
        return $this->client->post(
            $this->basePath . '/' . $subscriberId . '/sip_endpoints',
            $body
        );
    }

    /** @return array<string,mixed> */
    public function getSipEndpoint(string $subscriberId, string $endpointId): array
    {
        return $this->client->get(
            $this->basePath . '/' . $subscriberId . '/sip_endpoints/' . $endpointId
        );
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function updateSipEndpoint(string $subscriberId, string $endpointId, array $body): array
    {
        return $this->client->patch(
            $this->basePath . '/' . $subscriberId . '/sip_endpoints/' . $endpointId,
            $body
        );
    }

    /** @return array<string,mixed> */
    public function deleteSipEndpoint(string $subscriberId, string $endpointId): array
    {
        return $this->client->delete(
            $this->basePath . '/' . $subscriberId . '/sip_endpoints/' . $endpointId
        );
    }
}
