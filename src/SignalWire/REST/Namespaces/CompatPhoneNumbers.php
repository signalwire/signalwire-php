<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Compat IncomingPhoneNumbers + AvailablePhoneNumbers + ImportedPhoneNumbers
 * management.
 *
 * The base path is the IncomingPhoneNumbers collection; the available /
 * imported variants live at sibling paths derived from the base.
 */
class CompatPhoneNumbers
{
    private HttpClient $http;
    private string $basePath;
    private string $availableBase;
    private string $importedBase;

    public function __construct(HttpClient $http, string $basePath)
    {
        $this->http = $http;
        $this->basePath = $basePath;
        $this->availableBase = str_replace(
            '/IncomingPhoneNumbers',
            '/AvailablePhoneNumbers',
            $basePath
        );
        $this->importedBase = str_replace(
            '/IncomingPhoneNumbers',
            '/ImportedPhoneNumbers',
            $basePath
        );
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getAvailableBase(): string
    {
        return $this->availableBase;
    }

    public function getImportedBase(): string
    {
        return $this->importedBase;
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

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(string $sid, array $body): array
    {
        return $this->http->post($this->basePath . '/' . $sid, $body);
    }

    /** @return array<string,mixed> */
    public function delete(string $sid): array
    {
        return $this->http->delete($this->basePath . '/' . $sid);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function purchase(array $body): array
    {
        return $this->http->post($this->basePath, $body);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function importNumber(array $body): array
    {
        return $this->http->post($this->importedBase, $body);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listAvailableCountries(array $params = []): array
    {
        return $this->http->get($this->availableBase, $params);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function searchLocal(string $country, array $params = []): array
    {
        return $this->http->get($this->availableBase . '/' . $country . '/Local', $params);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function searchTollFree(string $country, array $params = []): array
    {
        return $this->http->get($this->availableBase . '/' . $country . '/TollFree', $params);
    }
}
