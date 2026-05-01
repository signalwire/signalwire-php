<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Video conference token management — get + reset.
 */
class VideoConferenceTokens
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
    public function get(string $tokenId): array
    {
        return $this->http->get($this->basePath . '/' . $tokenId);
    }

    /** @return array<string,mixed> */
    public function reset(string $tokenId): array
    {
        // No-body POST.
        return $this->http->post($this->basePath . '/' . $tokenId . '/reset');
    }
}
