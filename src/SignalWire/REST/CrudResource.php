<?php

declare(strict_types=1);

namespace SignalWire\REST;

/**
 * Common base class for namespace and resource classes.
 *
 * Mirrors Python's signalwire.rest._base.BaseResource — minimal wrapper
 * around an HttpClient and a base path. ReadResource extends this to add
 * list/get; CrudResource extends ReadResource for the full CRUD surface;
 * namespace classes that want a shared base path (without inheriting any
 * collection methods) can subclass BaseResource directly.
 */
class BaseResource
{
    protected HttpClient $http;
    protected string $basePath;

    public function __construct(HttpClient $http, string $base_path)
    {
        $this->http = $http;
        $this->basePath = $base_path;
    }

    /**
     * Build a full path by appending segments to the base path.
     */
    protected function path(string ...$parts): string
    {
        if (empty($parts)) {
            return $this->basePath;
        }
        return $this->basePath . '/' . implode('/', $parts);
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getHttp(): HttpClient
    {
        return $this->http;
    }
}

/**
 * Read-only collection wrapper: list + get.
 *
 * Mirrors Python's signalwire.rest._base.ReadResource — a BaseResource that
 * exposes the two read verbs (list over the collection, get by id). Resources
 * that only read (logs, sessions, sip_profile-style reads) subclass this; the
 * full-CRUD CrudResource composes it so list/get are defined exactly once.
 */
class ReadResource extends BaseResource
{
    protected HttpClient $client;

    public function __construct(HttpClient $client, string $basePath)
    {
        parent::__construct($client, $basePath);
        $this->client = $client;
    }

    public function getClient(): HttpClient
    {
        return $this->client;
    }

    /**
     * List resources (GET basePath).
     *
     * @param array<string,mixed> $params Query-string parameters.
     * @return array<string,mixed>
     */
    public function list(array $params = []): array
    {
        return $this->client->get($this->basePath, $params);
    }

    /**
     * Iterate every item across all pages of this resource's list endpoint.
     *
     * ``list()`` returns a single raw page (the server's first response). For
     * endpoints that paginate on the wire (a ``links.next`` / ``page_token`` in
     * the response), ``paginate()`` follows those links and yields each item:
     *
     *   foreach ($client->fabric->addresses->paginate() as $address) {
     *       // ...
     *   }
     *
     * Wires the resource layer to the tested {@see PaginatedIterator} (which
     * walks ``resp["data"]`` and follows ``resp["links"]["next"]``), so callers
     * no longer hand-construct the path + token loop. Mirrors Python's
     * ``ReadResource.paginate(**params) -> PaginatedIterator``.
     *
     * @param array<string,mixed> $params Initial query-string parameters.
     */
    public function paginate(array $params = []): PaginatedIterator
    {
        return new PaginatedIterator(
            $this->client,
            $this->basePath,
            $params === [] ? null : $params,
            'data'
        );
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
}

/**
 * Generic CRUD wrapper around an HttpClient and a base API path.
 *
 * Mirrors Python's signalwire.rest._base.CrudResource — composes ReadResource
 * (list/get) and adds create / update / delete for any REST resource that
 * follows the standard SignalWire collection+item URL pattern.
 */
class CrudResource extends ReadResource
{
    /**
     * HTTP verb used by update(). Mirrors Python's
     * ``CrudResource._update_method`` class attribute: the base default is
     * PATCH, and PUT-update resources override it (either via a subclass that
     * sets this property, or by passing ``$updateMethod`` to the constructor).
     */
    protected string $updateMethod = 'PATCH';

    public function __construct(HttpClient $client, string $basePath, string $updateMethod = 'PATCH')
    {
        parent::__construct($client, $basePath);
        $this->updateMethod = strtoupper($updateMethod);
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
     * Update a resource by ID (PATCH basePath/{id} by default; PUT for
     * resources whose canonical route uses PUT — see $updateMethod).
     *
     * @param array<string,mixed> $data JSON body.
     * @return array<string,mixed>
     */
    public function update(string $id, array $data): array
    {
        if ($this->updateMethod === 'PUT') {
            return $this->client->put($this->path($id), $data);
        }
        return $this->client->patch($this->path($id), $data);
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

/**
 * CRUD resource that also supports listing addresses for an item.
 *
 * Mirrors Python's signalwire.rest._base.CrudWithAddresses — adds
 * list_addresses(resource_id, **params) on top of the standard CRUD set.
 */
class CrudWithAddresses extends CrudResource
{
    /**
     * List the addresses associated with a resource (GET basePath/{id}/addresses).
     *
     * @param array<string,mixed> $params Query-string parameters.
     * @return array<string,mixed>
     */
    public function listAddresses(string $resource_id, array $params = []): array
    {
        return $this->client->get($this->path($resource_id, 'addresses'), $params);
    }
}
