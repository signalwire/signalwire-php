<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_fabric_mock.py.
 *
 * Covers fabric.addresses, fabric.resources (generic ops),
 * subscribers SIP-endpoint sub-resources, call_flows /
 * conference_rooms address sub-paths (singular path rewrite), the full
 * FabricTokens surface, and the cxml_applications.create deliberate-failure.
 */
class FabricMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        [$this->client, $this->mock] = MockTest::scopedClient();
    }

    // ----- fabric.addresses (read-only) -------------------------------

    #[Test]
    public function addressesListReturnsDataCollection(): void
    {
        $body = $this->client->fabric()->addresses()->list();
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/fabric/addresses', $j->path);
        $this->assertSame('fabric.list_fabric_addresses', $j->matchedRoute);
    }

    #[Test]
    public function addressesGetUsesAddressId(): void
    {
        $body = $this->client->fabric()->addresses()->get('addr-9001');

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/fabric/addresses/addr-9001', $j->path);
        $this->assertNotNull($j->matchedRoute, 'spec gap: address get');
    }

    // ----- cxml_applications has no create -----------------------------
    //
    // The cXML-applications API has no create endpoint. The spec/oracle puts
    // CxmlApplications on BaseResource with [delete, get, list, list_addresses,
    // update] and NO create, so the generated resource class simply does not
    // expose a create() method (rather than the old hand class's create() that
    // threw BadMethodCallException). Assert the method is absent — the surface
    // is oracle-correct — and that nothing reached the wire.

    #[Test]
    public function cxmlApplicationsHasNoCreate(): void
    {
        $resource = $this->client->fabric()->cxmlApplications();
        $this->assertFalse(
            method_exists($resource, 'create'),
            'CxmlApplications must not expose create() — the cXML-applications API has no create endpoint'
        );
        // Nothing should have hit the wire.
        $this->assertSame([], $this->mock->journal()->all());
    }

    // ----- call_flows.list_addresses uses singular path ---------------

    #[Test]
    public function callFlowsListAddressesUsesSingularPath(): void
    {
        $body = $this->client->fabric()->callFlows()->listAddresses('cf-1');
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        // singular 'call_flow' (NOT 'call_flows').
        $this->assertSame('/api/fabric/resources/call_flow/cf-1/addresses', $j->path);
        $this->assertNotNull($j->matchedRoute, 'spec gap: call-flow addresses sub-path');
    }

    // ----- conference_rooms.list_addresses uses singular path ---------

    #[Test]
    public function conferenceRoomsListAddressesUsesSingularPath(): void
    {
        $body = $this->client->fabric()->conferenceRooms()->listAddresses('cr-1');
        $this->assertArrayHasKey('data', $body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/fabric/resources/conference_room/cr-1/addresses', $j->path);
        $this->assertNotNull($j->matchedRoute);
    }

    // ----- subscribers SIP endpoint per-id ops ------------------------

    #[Test]
    public function subscribersGetSipEndpoint(): void
    {
        $body = $this->client->fabric()->subscribers()->getSipEndpoint('sub-1', 'ep-1');

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/fabric/resources/subscribers/sub-1/sip_endpoints/ep-1',
            $j->path
        );
        $this->assertNotNull($j->matchedRoute);
    }

    #[Test]
    public function subscribersUpdateSipEndpointUsesPatch(): void
    {
        $body = $this->client->fabric()->subscribers()->updateSipEndpoint(
            'sub-1',
            'ep-1',
            username: 'renamed'
        );

        $j = $this->mock->journal()->last();
        $this->assertSame('PATCH', $j->method);
        $this->assertSame(
            '/api/fabric/resources/subscribers/sub-1/sip_endpoints/ep-1',
            $j->path
        );
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame('renamed', $bm['username'] ?? null);
    }

    #[Test]
    public function subscribersDeleteSipEndpoint(): void
    {
        $body = $this->client->fabric()->subscribers()->deleteSipEndpoint('sub-1', 'ep-1');

        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame(
            '/api/fabric/resources/subscribers/sub-1/sip_endpoints/ep-1',
            $j->path
        );
        $this->assertNotNull($j->matchedRoute);
    }

    // ----- FabricTokens — every token-creation endpoint --------------

    #[Test]
    public function tokensCreateInviteToken(): void
    {
        $body = $this->client->fabric()->tokens()->createInviteToken(
            'addr-1',
            extras: ['email' => 'invitee@example.com']
        );

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        // singular 'subscriber' segment.
        $this->assertSame('/api/fabric/subscriber/invites', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame('invitee@example.com', $bm['email'] ?? null);
    }

    #[Test]
    public function tokensCreateEmbedToken(): void
    {
        $body = $this->client->fabric()->tokens()->createEmbedToken(
            'tok-1',
            extras: ['allowed_addresses' => ['addr-1', 'addr-2']]
        );

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/fabric/embeds/tokens', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame(['addr-1', 'addr-2'], $bm['allowed_addresses'] ?? null);
    }

    #[Test]
    public function tokensRefreshSubscriberToken(): void
    {
        $body = $this->client->fabric()->tokens()->refreshSubscriberToken('abc-123');

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/fabric/subscribers/tokens/refresh', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame('abc-123', $bm['refresh_token'] ?? null);
    }

    // ----- Generic resources -----------------------------------------

    #[Test]
    public function resourcesListReturnsDataCollection(): void
    {
        $body = $this->client->fabric()->resources()->list();
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/fabric/resources', $j->path);
        $this->assertNotNull($j->matchedRoute);
    }

    #[Test]
    public function resourcesGetReturnsSingleResource(): void
    {
        $body = $this->client->fabric()->resources()->get('res-1');

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/fabric/resources/res-1', $j->path);
    }

    #[Test]
    public function resourcesDelete(): void
    {
        $body = $this->client->fabric()->resources()->delete('res-2');

        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame('/api/fabric/resources/res-2', $j->path);
        $this->assertNotNull($j->matchedRoute);
    }

    #[Test]
    public function resourcesListAddresses(): void
    {
        $body = $this->client->fabric()->resources()->listAddresses('res-3');
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/fabric/resources/res-3/addresses', $j->path);
    }

    #[Test]
    public function resourcesAssignDomainApplication(): void
    {
        $body = $this->client->fabric()->resources()->assignDomainApplication(
            'res-4',
            'da-7'
        );

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/fabric/resources/res-4/domain_applications', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame('da-7', $bm['domain_application_id'] ?? null);
    }
}
