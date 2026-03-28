<?php

declare(strict_types=1);

namespace SignalWire\REST;

/**
 * Generic CRUD wrapper around an HttpClient and a base API path.
 *
 * Provides list / create / get / update / delete for any REST resource that
 * follows the standard SignalWire collection+item URL pattern.
 */
class CrudResource
{
    protected HttpClient $client;
    protected string $basePath;

    public function __construct(HttpClient $client, string $basePath)
    {
        $this->client = $client;
        $this->basePath = $basePath;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getClient(): HttpClient
    {
        return $this->client;
    }

    /**
     * Build a full path by appending segments to the base path.
     *
     * @param string ...$parts Additional path segments.
     */
    protected function path(string ...$parts): string
    {
        if (empty($parts)) {
            return $this->basePath;
        }
        return $this->basePath . '/' . implode('/', $parts);
    }

    /**
     * List resources (GET basePath).
     *
     * @param array<string,string> $params Query-string parameters.
     * @return array<string,mixed>
     */
    public function list(array $params = []): array
    {
        return $this->client->get($this->basePath, $params);
    }

    /**
     * Create a new resource (POST basePath).
     *
     * @param array<string,mixed> $data JSON body.
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        return $this->client->post($this->basePath, $data);
    }

    /**
     * Retrieve a single resource by ID (GET basePath/{id}).
     *
     * @return array<string,mixed>
     */
    public function get(string $id): array
    {
        return $this->client->get($this->path($id));
    }

    /**
     * Update a resource by ID (PUT basePath/{id}).
     *
     * @param array<string,mixed> $data JSON body.
     * @return array<string,mixed>
     */
    public function update(string $id, array $data): array
    {
        return $this->client->put($this->path($id), $data);
    }

    /**
     * Delete a resource by ID (DELETE basePath/{id}).
     *
     * @return array<string,mixed>
     */
    public function delete(string $id): array
    {
        return $this->client->delete($this->path($id));
    }
}
