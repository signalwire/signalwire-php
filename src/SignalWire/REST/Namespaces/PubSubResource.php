<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\BaseResource;
use SignalWire\REST\HttpClient;

/**
 * PubSub API namespace — token creation.
 *
 * Mirrors Python ``signalwire.rest.namespaces.pubsub.PubSubResource``: a thin
 * resource over ``/api/pubsub/tokens`` exposing ``createToken``.
 */
class PubSubResource extends BaseResource
{
    public function __construct(HttpClient $http)
    {
        parent::__construct($http, '/api/pubsub/tokens');
    }

    /**
     * Create a pub/sub token (POST /api/pubsub/tokens).
     *
     * @param array<string,mixed> $data JSON body.
     * @return array<string,mixed>
     */
    public function createToken(array $data = []): array
    {
        return $this->http->post($this->basePath, $data);
    }
}
