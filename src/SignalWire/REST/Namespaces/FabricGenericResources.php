<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Generic operations across all fabric resource types
 * (``/api/fabric/resources``).
 *
 * Mirrors Python ``signalwire.rest.namespaces.fabric.GenericResources``.
 */
class FabricGenericResources
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

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function list(array $params = []): array
    {
        return $this->http->get($this->basePath, $params);
    }

    /** @return array<string,mixed> */
    public function get(string $resourceId): array
    {
        return $this->http->get($this->basePath . '/' . $resourceId);
    }

    /** @return array<string,mixed> */
    public function delete(string $resourceId): array
    {
        return $this->http->delete($this->basePath . '/' . $resourceId);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function listAddresses(string $resourceId, array $params = []): array
    {
        return $this->http->get(
            $this->basePath . '/' . $resourceId . '/addresses',
            $params
        );
    }

    /**
     * Bind a phone route to a fabric resource
     * (``POST /api/fabric/resources/{id}/phone_routes``).
     *
     * Deprecated for the common binding cases — use the
     * ``PhoneNumbersResource::set*`` helpers instead. This endpoint accepts
     * only a narrow set of legacy resource types as the attach target; it does
     * NOT work for ``swml_webhook`` / ``cxml_webhook`` / ``ai_agent`` bindings
     * (those are configured on the phone number and the Fabric resource is
     * auto-materialized). Mirrors Python ``GenericResources.assign_phone_route``.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function assignPhoneRoute(string $resourceId, array $body): array
    {
        return $this->http->post(
            $this->basePath . '/' . $resourceId . '/phone_routes',
            $body
        );
    }

    /**
     * Bind a domain application to a fabric resource.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function assignDomainApplication(string $resourceId, array $body): array
    {
        return $this->http->post(
            $this->basePath . '/' . $resourceId . '/domain_applications',
            $body
        );
    }
}
