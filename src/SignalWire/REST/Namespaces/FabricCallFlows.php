<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;

/**
 * Call flows with version management.
 *
 * The API uses the singular path segment ``call_flow`` for sub-collections
 * (addresses, versions) — not ``call_flows`` — so this resource rewrites
 * the collection segment for those sub-paths to mirror the Python SDK.
 */
class FabricCallFlows extends CrudResource
{
    /** @return string Singular sub-resource base derived from the plural basePath. */
    private function singularBase(): string
    {
        return str_replace('/call_flows', '/call_flow', $this->basePath);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listAddresses(string $resourceId, array $params = []): array
    {
        return $this->client->get(
            $this->singularBase() . '/' . $resourceId . '/addresses',
            $params
        );
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listVersions(string $resourceId, array $params = []): array
    {
        return $this->client->get(
            $this->singularBase() . '/' . $resourceId . '/versions',
            $params
        );
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function deployVersion(string $resourceId, array $body = []): array
    {
        return $this->client->post(
            $this->singularBase() . '/' . $resourceId . '/versions',
            $body
        );
    }
}
