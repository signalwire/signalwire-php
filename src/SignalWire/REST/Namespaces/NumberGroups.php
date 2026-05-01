<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;
use SignalWire\REST\HttpClient;

/**
 * Number Groups namespace — CRUD (PUT-update) + membership management.
 *
 * Memberships hang off the group via ``list_memberships`` /
 * ``add_membership``, but ``get_membership`` / ``delete_membership`` use
 * a sibling top-level path: ``/api/relay/rest/number_group_memberships/{id}``.
 */
class NumberGroups extends CrudResource
{
    private const MEMBERSHIPS_BASE = '/api/relay/rest/number_group_memberships';

    public function __construct(HttpClient $http)
    {
        parent::__construct($http, '/api/relay/rest/number_groups');
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listMemberships(string $groupId, array $params = []): array
    {
        return $this->client->get(
            $this->basePath . '/' . $groupId . '/number_group_memberships',
            $params
        );
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function addMembership(string $groupId, array $body): array
    {
        return $this->client->post(
            $this->basePath . '/' . $groupId . '/number_group_memberships',
            $body
        );
    }

    /** @return array<string,mixed> */
    public function getMembership(string $membershipId): array
    {
        return $this->client->get(self::MEMBERSHIPS_BASE . '/' . $membershipId);
    }

    /** @return array<string,mixed> */
    public function deleteMembership(string $membershipId): array
    {
        return $this->client->delete(self::MEMBERSHIPS_BASE . '/' . $membershipId);
    }
}
