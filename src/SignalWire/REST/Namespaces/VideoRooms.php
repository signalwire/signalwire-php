<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;

/**
 * Video room management with stream sub-resources.
 *
 * Update method is PUT (not PATCH) per the Python ``VideoRooms`` resource.
 */
class VideoRooms extends CrudResource
{
    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listStreams(string $roomId, array $params = []): array
    {
        return $this->client->get($this->basePath . '/' . $roomId . '/streams', $params);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function createStream(string $roomId, array $body): array
    {
        return $this->client->post($this->basePath . '/' . $roomId . '/streams', $body);
    }
}
