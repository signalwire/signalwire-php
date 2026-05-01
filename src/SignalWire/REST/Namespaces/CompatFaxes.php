<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;

/**
 * Compat fax management with media sub-resources.
 */
class CompatFaxes extends CrudResource
{
    /**
     * Update a fax (cancel etc.). POST basePath/{sid}.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(string $sid, array $body): array
    {
        return $this->client->post($this->basePath . '/' . $sid, $body);
    }

    /**
     * List media attachments. GET .../Media.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function listMedia(string $faxSid, array $params = []): array
    {
        return $this->client->get($this->basePath . '/' . $faxSid . '/Media', $params);
    }

    /**
     * Get a single media attachment. GET .../Media/{mediaSid}.
     *
     * @return array<string,mixed>
     */
    public function getMedia(string $faxSid, string $mediaSid): array
    {
        return $this->client->get(
            $this->basePath . '/' . $faxSid . '/Media/' . $mediaSid
        );
    }

    /**
     * Delete a media attachment. DELETE .../Media/{mediaSid}.
     *
     * @return array<string,mixed>
     */
    public function deleteMedia(string $faxSid, string $mediaSid): array
    {
        return $this->client->delete(
            $this->basePath . '/' . $faxSid . '/Media/' . $mediaSid
        );
    }
}
