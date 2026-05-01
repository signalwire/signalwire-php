<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Video room recording management — top-level recordings collection
 * (distinct from session-scoped recordings).
 */
class VideoRoomRecordings
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

    /** @return array<string,mixed> */
    public function get(string $recordingId): array
    {
        return $this->http->get($this->basePath . '/' . $recordingId);
    }

    /** @return array<string,mixed> */
    public function delete(string $recordingId): array
    {
        return $this->http->delete($this->basePath . '/' . $recordingId);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listEvents(string $recordingId, array $params = []): array
    {
        return $this->http->get($this->basePath . '/' . $recordingId . '/events', $params);
    }
}
