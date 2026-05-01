<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Compat transcription management.
 *
 * Account-scoped transcriptions. Surface is list / get / delete only.
 */
class CompatTranscriptions
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
    public function get(string $sid): array
    {
        return $this->http->get($this->basePath . '/' . $sid);
    }

    /** @return array<string,mixed> */
    public function delete(string $sid): array
    {
        return $this->http->delete($this->basePath . '/' . $sid);
    }
}
