<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\BaseResource;
use SignalWire\REST\HttpClient;

/**
 * Chat API namespace — token creation.
 *
 * Mirrors Python ``signalwire.rest.namespaces.chat.ChatResource``: a thin
 * resource over ``/api/chat/tokens`` exposing ``createToken``.
 */
class ChatResource extends BaseResource
{
    public function __construct(HttpClient $http)
    {
        parent::__construct($http, '/api/chat/tokens');
    }

    /**
     * Create a chat token (POST /api/chat/tokens).
     *
     * @param array<string,mixed> $data JSON body.
     * @return array<string,mixed>
     */
    public function createToken(array $data = []): array
    {
        return $this->http->post($this->basePath, $data);
    }
}
