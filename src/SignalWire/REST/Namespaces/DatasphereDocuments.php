<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;
use SignalWire\REST\HttpClient;

/**
 * Datasphere document management — full CRUD plus search and chunk operations.
 */
class DatasphereDocuments extends CrudResource
{
    public function __construct(HttpClient $http)
    {
        parent::__construct($http, '/api/datasphere/documents');
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function search(array $body): array
    {
        return $this->client->post($this->basePath . '/search', $body);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listChunks(string $documentId, array $params = []): array
    {
        return $this->client->get(
            $this->basePath . '/' . $documentId . '/chunks',
            $params
        );
    }

    /** @return array<string,mixed> */
    public function getChunk(string $documentId, string $chunkId): array
    {
        return $this->client->get(
            $this->basePath . '/' . $documentId . '/chunks/' . $chunkId
        );
    }

    /** @return array<string,mixed> */
    public function deleteChunk(string $documentId, string $chunkId): array
    {
        return $this->client->delete(
            $this->basePath . '/' . $documentId . '/chunks/' . $chunkId
        );
    }
}
