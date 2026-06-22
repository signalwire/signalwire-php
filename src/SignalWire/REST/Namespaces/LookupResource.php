<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\BaseResource;
use SignalWire\REST\HttpClient;

/**
 * Phone Number Lookup namespace.
 *
 * Mirrors the Python reference (`signalwire.rest.namespaces.lookup`): a single
 * lookup operation, NOT a CRUD resource. The only canonical route is
 * `GET /api/relay/rest/lookup/phone_number/{e164}` (`relay-rest.lookup_phone_number`).
 */
class LookupResource extends BaseResource
{
    public function __construct(HttpClient $http)
    {
        parent::__construct($http, '/api/relay/rest/lookup');
    }

    /**
     * Look up carrier / CNAM data for an E.164 phone number.
     *
     * @param array<string, mixed> $params Optional query parameters.
     * @return array<string, mixed>
     */
    public function phoneNumber(string $e164, array $params = []): array
    {
        return $this->http->get($this->path('phone_number', $e164), $params);
    }
}
