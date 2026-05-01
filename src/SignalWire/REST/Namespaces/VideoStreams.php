<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Top-level video stream management — get / update (PUT) / delete.
 */
class VideoStreams
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

    /** @return array<string,mixed> */
    public function get(string $streamId): array
    {
        return $this->http->get($this->basePath . '/' . $streamId);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(string $streamId, array $body): array
    {
        return $this->http->put($this->basePath . '/' . $streamId, $body);
    }

    /** @return array<string,mixed> */
    public function delete(string $streamId): array
    {
        return $this->http->delete($this->basePath . '/' . $streamId);
    }
}
