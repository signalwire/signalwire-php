<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Subscriber, guest, invite, and embed token creation under
 * ``/api/fabric/...``.
 *
 * Note: token endpoints sit under different sub-paths inside the Fabric
 * mount point (``subscribers/tokens``, ``subscriber/invites``, ``guests``,
 * ``embeds``) — singular vs plural is API-specific so each helper has the
 * exact path the API expects.
 */
class FabricTokens
{
    private HttpClient $http;
    private string $basePath;

    public function __construct(HttpClient $http, string $basePath)
    {
        $this->http = $http;
        $this->basePath = $basePath;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function createSubscriberToken(array $body): array
    {
        return $this->http->post($this->basePath . '/subscribers/tokens', $body);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function refreshSubscriberToken(array $body): array
    {
        return $this->http->post($this->basePath . '/subscribers/tokens/refresh', $body);
    }

    /**
     * Note the singular ``subscriber`` here — the invite endpoint uses
     * ``/api/fabric/subscriber/invites``, not ``subscribers``.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function createInviteToken(array $body): array
    {
        return $this->http->post($this->basePath . '/subscriber/invites', $body);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function createGuestToken(array $body): array
    {
        return $this->http->post($this->basePath . '/guests/tokens', $body);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function createEmbedToken(array $body): array
    {
        return $this->http->post($this->basePath . '/embeds/tokens', $body);
    }
}
