<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;

/**
 * Compat message management with media sub-resources.
 */
class CompatMessages extends CrudResource
{
    /**
     * Update a message (cancel, edit body). POST basePath/{sid}.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(string $sid, array $body): array
    {
        return $this->client->post($this->basePath . '/' . $sid, $body);
    }

    /**
     * List media attachments for the named message. GET .../Media.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function listMedia(string $messageSid, array $params = []): array
    {
        return $this->client->get($this->basePath . '/' . $messageSid . '/Media', $params);
    }

    /**
     * Get a single media attachment by sid. GET .../Media/{mediaSid}.
     *
     * @return array<string,mixed>
     */
    public function getMedia(string $messageSid, string $mediaSid): array
    {
        return $this->client->get(
            $this->basePath . '/' . $messageSid . '/Media/' . $mediaSid
        );
    }

    /**
     * Delete a media attachment. DELETE .../Media/{mediaSid}.
     *
     * @return array<string,mixed>
     */
    public function deleteMedia(string $messageSid, string $mediaSid): array
    {
        return $this->client->delete(
            $this->basePath . '/' . $messageSid . '/Media/' . $mediaSid
        );
    }
}
