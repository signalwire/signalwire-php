<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;
use SignalWire\REST\HttpClient;

/**
 * Queues API namespace.
 *
 * Wraps the ``/api/relay/rest/queues`` resource (CRUD with PUT-update) and
 * adds the member sub-resource operations (list_members, get_next_member,
 * get_member).
 */
class Queues extends CrudResource
{
    public function __construct(HttpClient $http)
    {
        parent::__construct($http, '/api/relay/rest/queues');
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listMembers(string $queueId, array $params = []): array
    {
        return $this->client->get(
            $this->basePath . '/' . $queueId . '/members',
            $params
        );
    }

    /** @return array<string,mixed> */
    public function getNextMember(string $queueId): array
    {
        return $this->client->get(
            $this->basePath . '/' . $queueId . '/members/next'
        );
    }

    /** @return array<string,mixed> */
    public function getMember(string $queueId, string $memberId): array
    {
        return $this->client->get(
            $this->basePath . '/' . $queueId . '/members/' . $memberId
        );
    }
}
