<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;

/**
 * Video conference management with conference_tokens and streams sub-resources.
 *
 * Update method is PUT (not PATCH) per the Python ``VideoConferences`` resource.
 */
class VideoConferences extends CrudResource
{
    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listConferenceTokens(string $conferenceId, array $params = []): array
    {
        return $this->client->get(
            $this->basePath . '/' . $conferenceId . '/conference_tokens',
            $params
        );
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listStreams(string $conferenceId, array $params = []): array
    {
        return $this->client->get(
            $this->basePath . '/' . $conferenceId . '/streams',
            $params
        );
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function createStream(string $conferenceId, array $body): array
    {
        return $this->client->post(
            $this->basePath . '/' . $conferenceId . '/streams',
            $body
        );
    }
}
