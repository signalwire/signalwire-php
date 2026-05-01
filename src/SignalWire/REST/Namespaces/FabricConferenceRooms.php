<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;

/**
 * Conference rooms — uses singular ``conference_room`` for sub-resource
 * paths to mirror the Python SDK.
 */
class FabricConferenceRooms extends CrudResource
{
    private function singularBase(): string
    {
        return str_replace('/conference_rooms', '/conference_room', $this->basePath);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listAddresses(string $resourceId, array $params = []): array
    {
        return $this->client->get(
            $this->singularBase() . '/' . $resourceId . '/addresses',
            $params
        );
    }
}
