<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Recordings — read-only list/get plus delete (no create or update).
 */
class Recordings
{
    private HttpClient $http;
    private string $basePath = '/api/relay/rest/recordings';

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
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
}
