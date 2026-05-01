<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Video room session management. Read-only with several sub-collections
 * (events, members, recordings).
 */
class VideoRoomSessions
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
    public function get(string $sessionId): array
    {
        return $this->http->get($this->basePath . '/' . $sessionId);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listEvents(string $sessionId, array $params = []): array
    {
        return $this->http->get($this->basePath . '/' . $sessionId . '/events', $params);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listMembers(string $sessionId, array $params = []): array
    {
        return $this->http->get($this->basePath . '/' . $sessionId . '/members', $params);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listRecordings(string $sessionId, array $params = []): array
    {
        return $this->http->get($this->basePath . '/' . $sessionId . '/recordings', $params);
    }
}
