<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;
use SignalWire\REST\HttpClient;

/**
 * Verified Caller IDs namespace — CRUD + verification flow.
 *
 * Mirrors Python ``signalwire.rest.namespaces.verified_callers.VerifiedCallersResource``:
 * the collection lives at ``/api/relay/rest/verified_caller_ids`` (note the
 * ``_ids`` suffix), update uses PUT, and two verification helpers complete the
 * caller-ID verification handshake.
 */
class VerifiedCallersResource extends CrudResource
{
    public function __construct(HttpClient $http)
    {
        parent::__construct($http, '/api/relay/rest/verified_caller_ids', 'PUT');
    }

    /**
     * Re-trigger the verification call (POST {id}/verification).
     *
     * @return array<string,mixed>
     */
    public function redialVerification(string $callerId): array
    {
        return $this->client->post($this->path($callerId, 'verification'));
    }

    /**
     * Submit the verification code (PUT {id}/verification).
     *
     * @param array<string,mixed> $data JSON body.
     * @return array<string,mixed>
     */
    public function submitVerification(string $callerId, array $data = []): array
    {
        return $this->client->put($this->path($callerId, 'verification'), $data);
    }
}
