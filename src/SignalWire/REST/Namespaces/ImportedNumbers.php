<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Imported phone numbers — create only (registers an externally-hosted
 * number).
 */
class ImportedNumbers
{
    private HttpClient $http;
    private string $basePath = '/api/relay/rest/imported_phone_numbers';

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function create(array $body): array
    {
        return $this->http->post($this->basePath, $body);
    }
}
