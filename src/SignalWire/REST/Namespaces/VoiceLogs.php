<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Voice log queries — list + get by id + per-id event sub-collection.
 */
class VoiceLogs
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
    public function get(string $logId): array
    {
        return $this->http->get($this->basePath . '/' . $logId);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listEvents(string $logId, array $params = []): array
    {
        return $this->http->get($this->basePath . '/' . $logId . '/events', $params);
    }
}
