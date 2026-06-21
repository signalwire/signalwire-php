<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\REST\RestClient;
use SignalWire\REST\SignalWireRestError;

/**
 * Full success + error coverage matrix for the fabric.* route group.
 *
 * Each coverable canonical route gets a SUCCESS test (hits the route on the
 * mock and asserts journal method/path/matchedRoute) and an ERROR test (arms a
 * scenario, asserts SignalWireRestError::getStatusCode() and journal
 * responseStatus/matchedRoute).
 *
 * Accepted gaps (NOT covered, allowlisted): list/get/update/delete_dialogflow_agent,
 * list_dialogflow_agent_addresses, list_sip_gateway_addresses,
 * assign_resource_sip_endpoint.
 *
 * fabric.assign_resource_phone_route is covered via
 * FabricGenericResources::assignPhoneRoute (added for python parity — mirrors
 * GenericResources.assign_phone_route, POST /api/fabric/resources/{id}/phone_routes).
 */
class FabricCoverageMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        [$this->client, $this->mock] = MockTest::scopedClient();
    }

    /** @return array<string,mixed> */
    private function assertSuccessJournal(string $method, string $path, string $route): array
    {
        $j = $this->mock->journal()->last();
        $this->assertSame($method, $j->method);
        $this->assertSame($path, $j->path);
        $this->assertSame($route, $j->matchedRoute);
        return ['method' => $j->method, 'path' => $j->path, 'route' => $j->matchedRoute];
    }

    private function assertErrorJournal(int $status, string $route): void
    {
        $j = $this->mock->journal()->last();
        $this->assertSame($status, $j->responseStatus);
        $this->assertSame($route, $j->matchedRoute);
    }

    // =================================================================
    // ai_agents (PATCH update)
    // =================================================================

    #[Test]
    public function aiAgentsListSuccess(): void
    {
        $body = $this->client->fabric()->aiAgents()->list();
        $this->assertIsArray($body);
        $j = $this->assertSuccessJournal('GET', '/api/fabric/resources/ai_agents', 'fabric.list_ai_agents');
        $this->assertSame('GET', $j['method']);
    }

    #[Test]
    public function aiAgentsListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_ai_agents', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->aiAgents()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_ai_agents');
    }

    #[Test]
    public function aiAgentsCreateSuccess(): void
    {
        $body = $this->client->fabric()->aiAgents()->create(['name' => 'a']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/ai_agents', 'fabric.create_ai_agent');
    }

    #[Test]
    public function aiAgentsCreateError(): void
    {
        $this->mock->scenarios()->set('fabric.create_ai_agent', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->aiAgents()->create(['name' => 'a']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_ai_agent');
    }

    #[Test]
    public function aiAgentsGetSuccess(): void
    {
        $body = $this->client->fabric()->aiAgents()->get('id-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/ai_agents/id-1', 'fabric.get_ai_agent');
    }

    #[Test]
    public function aiAgentsGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_ai_agent', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->aiAgents()->get('id-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_ai_agent');
    }

    #[Test]
    public function aiAgentsUpdateSuccess(): void
    {
        $body = $this->client->fabric()->aiAgents()->update('id-1', ['name' => 'b']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('PATCH', '/api/fabric/resources/ai_agents/id-1', 'fabric.update_ai_agent');
    }

    #[Test]
    public function aiAgentsUpdateError(): void
    {
        $this->mock->scenarios()->set('fabric.update_ai_agent', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->aiAgents()->update('id-1', ['name' => 'b']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.update_ai_agent');
    }

    #[Test]
    public function aiAgentsDeleteSuccess(): void
    {
        $body = $this->client->fabric()->aiAgents()->delete('id-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/ai_agents/id-1', 'fabric.delete_ai_agent');
    }

    #[Test]
    public function aiAgentsDeleteError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_ai_agent', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->aiAgents()->delete('id-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_ai_agent');
    }

    #[Test]
    public function aiAgentsListAddressesSuccess(): void
    {
        $body = $this->client->fabric()->aiAgents()->listAddresses('id-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/ai_agents/id-1/addresses', 'fabric.list_ai_agent_addresses');
    }

    #[Test]
    public function aiAgentsListAddressesError(): void
    {
        $this->mock->scenarios()->set('fabric.list_ai_agent_addresses', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->aiAgents()->listAddresses('id-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_ai_agent_addresses');
    }

    // =================================================================
    // call_flows (PUT update) + version/address singular sub-paths
    // =================================================================

    #[Test]
    public function callFlowsListSuccess(): void
    {
        $body = $this->client->fabric()->callFlows()->list();
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/call_flows', 'fabric.list_call_flows');
    }

    #[Test]
    public function callFlowsListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_call_flows', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->callFlows()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_call_flows');
    }

    #[Test]
    public function callFlowsCreateSuccess(): void
    {
        $body = $this->client->fabric()->callFlows()->create(['name' => 'cf']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/call_flows', 'fabric.create_call_flow');
    }

    #[Test]
    public function callFlowsCreateError(): void
    {
        $this->mock->scenarios()->set('fabric.create_call_flow', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->callFlows()->create(['name' => 'cf']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_call_flow');
    }

    #[Test]
    public function callFlowsGetSuccess(): void
    {
        $body = $this->client->fabric()->callFlows()->get('cf-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/call_flows/cf-1', 'fabric.get_call_flow');
    }

    #[Test]
    public function callFlowsGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_call_flow', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->callFlows()->get('cf-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_call_flow');
    }

    #[Test]
    public function callFlowsUpdateSuccess(): void
    {
        $body = $this->client->fabric()->callFlows()->update('cf-1', ['name' => 'x']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('PUT', '/api/fabric/resources/call_flows/cf-1', 'fabric.update_call_flow');
    }

    #[Test]
    public function callFlowsUpdateError(): void
    {
        $this->mock->scenarios()->set('fabric.update_call_flow', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->callFlows()->update('cf-1', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.update_call_flow');
    }

    #[Test]
    public function callFlowsDeleteSuccess(): void
    {
        $body = $this->client->fabric()->callFlows()->delete('cf-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/call_flows/cf-1', 'fabric.delete_call_flow');
    }

    #[Test]
    public function callFlowsDeleteError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_call_flow', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->callFlows()->delete('cf-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_call_flow');
    }

    #[Test]
    public function callFlowsListAddressesSuccess(): void
    {
        $body = $this->client->fabric()->callFlows()->listAddresses('cf-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/call_flow/cf-1/addresses', 'fabric.list_call_flow_addresses');
    }

    #[Test]
    public function callFlowsListAddressesError(): void
    {
        $this->mock->scenarios()->set('fabric.list_call_flow_addresses', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->callFlows()->listAddresses('cf-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_call_flow_addresses');
    }

    #[Test]
    public function callFlowsListVersionsSuccess(): void
    {
        $body = $this->client->fabric()->callFlows()->listVersions('cf-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/call_flow/cf-1/versions', 'fabric.list_call_flow_versions');
    }

    #[Test]
    public function callFlowsListVersionsError(): void
    {
        $this->mock->scenarios()->set('fabric.list_call_flow_versions', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->callFlows()->listVersions('cf-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_call_flow_versions');
    }

    #[Test]
    public function callFlowsDeployVersionSuccess(): void
    {
        $body = $this->client->fabric()->callFlows()->deployVersion('cf-1', ['version' => 'v2']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/call_flow/cf-1/versions', 'fabric.deploy_call_flow_version');
    }

    #[Test]
    public function callFlowsDeployVersionError(): void
    {
        $this->mock->scenarios()->set('fabric.deploy_call_flow_version', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->callFlows()->deployVersion('cf-1', ['version' => 'v2']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.deploy_call_flow_version');
    }

    // =================================================================
    // conference_rooms (PUT update) + singular address sub-path
    // =================================================================

    #[Test]
    public function conferenceRoomsListSuccess(): void
    {
        $body = $this->client->fabric()->conferenceRooms()->list();
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/conference_rooms', 'fabric.list_conference_rooms');
    }

    #[Test]
    public function conferenceRoomsListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_conference_rooms', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->conferenceRooms()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_conference_rooms');
    }

    #[Test]
    public function conferenceRoomsCreateSuccess(): void
    {
        $body = $this->client->fabric()->conferenceRooms()->create(['name' => 'r']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/conference_rooms', 'fabric.create_conference_room');
    }

    #[Test]
    public function conferenceRoomsCreateError(): void
    {
        $this->mock->scenarios()->set('fabric.create_conference_room', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->conferenceRooms()->create(['name' => 'r']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_conference_room');
    }

    #[Test]
    public function conferenceRoomsGetSuccess(): void
    {
        $body = $this->client->fabric()->conferenceRooms()->get('cr-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/conference_rooms/cr-1', 'fabric.get_conference_room');
    }

    #[Test]
    public function conferenceRoomsGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_conference_room', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->conferenceRooms()->get('cr-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_conference_room');
    }

    #[Test]
    public function conferenceRoomsUpdateSuccess(): void
    {
        $body = $this->client->fabric()->conferenceRooms()->update('cr-1', ['name' => 'x']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('PUT', '/api/fabric/resources/conference_rooms/cr-1', 'fabric.update_conference_room');
    }

    #[Test]
    public function conferenceRoomsUpdateError(): void
    {
        $this->mock->scenarios()->set('fabric.update_conference_room', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->conferenceRooms()->update('cr-1', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.update_conference_room');
    }

    #[Test]
    public function conferenceRoomsDeleteSuccess(): void
    {
        $body = $this->client->fabric()->conferenceRooms()->delete('cr-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/conference_rooms/cr-1', 'fabric.delete_conference_room');
    }

    #[Test]
    public function conferenceRoomsDeleteError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_conference_room', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->conferenceRooms()->delete('cr-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_conference_room');
    }

    #[Test]
    public function conferenceRoomsListAddressesSuccess(): void
    {
        $body = $this->client->fabric()->conferenceRooms()->listAddresses('cr-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/conference_room/cr-1/addresses', 'fabric.list_conference_room_addresses');
    }

    #[Test]
    public function conferenceRoomsListAddressesError(): void
    {
        $this->mock->scenarios()->set('fabric.list_conference_room_addresses', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->conferenceRooms()->listAddresses('cr-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_conference_room_addresses');
    }

    // =================================================================
    // cxml_applications (NO create; PUT update)
    // =================================================================

    #[Test]
    public function cxmlApplicationsListSuccess(): void
    {
        $body = $this->client->fabric()->cxmlApplications()->list();
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/cxml_applications', 'fabric.list_cxml_applications');
    }

    #[Test]
    public function cxmlApplicationsListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_cxml_applications', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->cxmlApplications()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_cxml_applications');
    }

    #[Test]
    public function cxmlApplicationsGetSuccess(): void
    {
        $body = $this->client->fabric()->cxmlApplications()->get('ca-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/cxml_applications/ca-1', 'fabric.get_cxml_application');
    }

    #[Test]
    public function cxmlApplicationsGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_cxml_application', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->cxmlApplications()->get('ca-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_cxml_application');
    }

    #[Test]
    public function cxmlApplicationsUpdateSuccess(): void
    {
        $body = $this->client->fabric()->cxmlApplications()->update('ca-1', ['name' => 'x']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('PUT', '/api/fabric/resources/cxml_applications/ca-1', 'fabric.update_cxml_application');
    }

    #[Test]
    public function cxmlApplicationsUpdateError(): void
    {
        $this->mock->scenarios()->set('fabric.update_cxml_application', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->cxmlApplications()->update('ca-1', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.update_cxml_application');
    }

    #[Test]
    public function cxmlApplicationsDeleteSuccess(): void
    {
        $body = $this->client->fabric()->cxmlApplications()->delete('ca-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/cxml_applications/ca-1', 'fabric.delete_cxml_application');
    }

    #[Test]
    public function cxmlApplicationsDeleteError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_cxml_application', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->cxmlApplications()->delete('ca-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_cxml_application');
    }

    #[Test]
    public function cxmlApplicationsListAddressesSuccess(): void
    {
        $body = $this->client->fabric()->cxmlApplications()->listAddresses('ca-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/cxml_applications/ca-1/addresses', 'fabric.list_cxml_application_addresses');
    }

    #[Test]
    public function cxmlApplicationsListAddressesError(): void
    {
        $this->mock->scenarios()->set('fabric.list_cxml_application_addresses', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->cxmlApplications()->listAddresses('ca-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_cxml_application_addresses');
    }

    // =================================================================
    // cxml_scripts (PUT update)
    // =================================================================

    #[Test]
    public function cxmlScriptsListSuccess(): void
    {
        $body = $this->client->fabric()->cxmlScripts()->list();
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/cxml_scripts', 'fabric.list_cxml_scripts');
    }

    #[Test]
    public function cxmlScriptsListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_cxml_scripts', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->cxmlScripts()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_cxml_scripts');
    }

    #[Test]
    public function cxmlScriptsCreateSuccess(): void
    {
        $body = $this->client->fabric()->cxmlScripts()->create(['name' => 's']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/cxml_scripts', 'fabric.create_cxml_script');
    }

    #[Test]
    public function cxmlScriptsCreateError(): void
    {
        $this->mock->scenarios()->set('fabric.create_cxml_script', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->cxmlScripts()->create(['name' => 's']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_cxml_script');
    }

    #[Test]
    public function cxmlScriptsGetSuccess(): void
    {
        $body = $this->client->fabric()->cxmlScripts()->get('cs-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/cxml_scripts/cs-1', 'fabric.get_cxml_script');
    }

    #[Test]
    public function cxmlScriptsGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_cxml_script', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->cxmlScripts()->get('cs-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_cxml_script');
    }

    #[Test]
    public function cxmlScriptsUpdateSuccess(): void
    {
        $body = $this->client->fabric()->cxmlScripts()->update('cs-1', ['name' => 'x']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('PUT', '/api/fabric/resources/cxml_scripts/cs-1', 'fabric.update_cxml_script');
    }

    #[Test]
    public function cxmlScriptsUpdateError(): void
    {
        $this->mock->scenarios()->set('fabric.update_cxml_script', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->cxmlScripts()->update('cs-1', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.update_cxml_script');
    }

    #[Test]
    public function cxmlScriptsDeleteSuccess(): void
    {
        $body = $this->client->fabric()->cxmlScripts()->delete('cs-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/cxml_scripts/cs-1', 'fabric.delete_cxml_script');
    }

    #[Test]
    public function cxmlScriptsDeleteError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_cxml_script', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->cxmlScripts()->delete('cs-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_cxml_script');
    }

    #[Test]
    public function cxmlScriptsListAddressesSuccess(): void
    {
        $body = $this->client->fabric()->cxmlScripts()->listAddresses('cs-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/cxml_scripts/cs-1/addresses', 'fabric.list_cxml_script_addresses');
    }

    #[Test]
    public function cxmlScriptsListAddressesError(): void
    {
        $this->mock->scenarios()->set('fabric.list_cxml_script_addresses', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->cxmlScripts()->listAddresses('cs-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_cxml_script_addresses');
    }

    // =================================================================
    // cxml_webhooks (PATCH update); listAddresses uses plural path
    // =================================================================

    #[Test]
    public function cxmlWebhooksListSuccess(): void
    {
        $body = $this->client->fabric()->cxmlWebhooks()->list();
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/cxml_webhooks', 'fabric.list_cxml_webhooks');
    }

    #[Test]
    public function cxmlWebhooksListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_cxml_webhooks', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->cxmlWebhooks()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_cxml_webhooks');
    }

    #[Test]
    public function cxmlWebhooksCreateSuccess(): void
    {
        $body = $this->client->fabric()->cxmlWebhooks()->create(['name' => 'w']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/cxml_webhooks', 'fabric.create_cxml_webhook');
    }

    #[Test]
    public function cxmlWebhooksCreateError(): void
    {
        $this->mock->scenarios()->set('fabric.create_cxml_webhook', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->cxmlWebhooks()->create(['name' => 'w']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_cxml_webhook');
    }

    #[Test]
    public function cxmlWebhooksGetSuccess(): void
    {
        $body = $this->client->fabric()->cxmlWebhooks()->get('cw-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/cxml_webhooks/cw-1', 'fabric.get_cxml_webhook');
    }

    #[Test]
    public function cxmlWebhooksGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_cxml_webhook', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->cxmlWebhooks()->get('cw-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_cxml_webhook');
    }

    #[Test]
    public function cxmlWebhooksUpdateSuccess(): void
    {
        $body = $this->client->fabric()->cxmlWebhooks()->update('cw-1', ['name' => 'x']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('PATCH', '/api/fabric/resources/cxml_webhooks/cw-1', 'fabric.update_cxml_webhook');
    }

    #[Test]
    public function cxmlWebhooksUpdateError(): void
    {
        $this->mock->scenarios()->set('fabric.update_cxml_webhook', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->cxmlWebhooks()->update('cw-1', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.update_cxml_webhook');
    }

    #[Test]
    public function cxmlWebhooksDeleteSuccess(): void
    {
        $body = $this->client->fabric()->cxmlWebhooks()->delete('cw-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/cxml_webhooks/cw-1', 'fabric.delete_cxml_webhook');
    }

    #[Test]
    public function cxmlWebhooksDeleteError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_cxml_webhook', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->cxmlWebhooks()->delete('cw-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_cxml_webhook');
    }

    #[Test]
    public function cxmlWebhooksListAddressesSuccess(): void
    {
        $body = $this->client->fabric()->cxmlWebhooks()->listAddresses('cw-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/cxml_webhooks/cw-1/addresses', 'fabric.list_cxml_webhook_addresses');
    }

    #[Test]
    public function cxmlWebhooksListAddressesError(): void
    {
        $this->mock->scenarios()->set('fabric.list_cxml_webhook_addresses', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->cxmlWebhooks()->listAddresses('cw-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_cxml_webhook_addresses');
    }

    // =================================================================
    // freeswitch_connectors (PUT update)
    // =================================================================

    #[Test]
    public function freeswitchConnectorsListSuccess(): void
    {
        $body = $this->client->fabric()->freeswitchConnectors()->list();
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/freeswitch_connectors', 'fabric.list_freeswitch_connectors');
    }

    #[Test]
    public function freeswitchConnectorsListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_freeswitch_connectors', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->freeswitchConnectors()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_freeswitch_connectors');
    }

    #[Test]
    public function freeswitchConnectorsCreateSuccess(): void
    {
        $body = $this->client->fabric()->freeswitchConnectors()->create(['name' => 'f']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/freeswitch_connectors', 'fabric.create_freeswitch_connector');
    }

    #[Test]
    public function freeswitchConnectorsCreateError(): void
    {
        $this->mock->scenarios()->set('fabric.create_freeswitch_connector', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->freeswitchConnectors()->create(['name' => 'f']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_freeswitch_connector');
    }

    #[Test]
    public function freeswitchConnectorsGetSuccess(): void
    {
        $body = $this->client->fabric()->freeswitchConnectors()->get('fc-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/freeswitch_connectors/fc-1', 'fabric.get_freeswitch_connector');
    }

    #[Test]
    public function freeswitchConnectorsGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_freeswitch_connector', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->freeswitchConnectors()->get('fc-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_freeswitch_connector');
    }

    #[Test]
    public function freeswitchConnectorsUpdateSuccess(): void
    {
        $body = $this->client->fabric()->freeswitchConnectors()->update('fc-1', ['name' => 'x']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('PUT', '/api/fabric/resources/freeswitch_connectors/fc-1', 'fabric.update_freeswitch_connector');
    }

    #[Test]
    public function freeswitchConnectorsUpdateError(): void
    {
        $this->mock->scenarios()->set('fabric.update_freeswitch_connector', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->freeswitchConnectors()->update('fc-1', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.update_freeswitch_connector');
    }

    #[Test]
    public function freeswitchConnectorsDeleteSuccess(): void
    {
        $body = $this->client->fabric()->freeswitchConnectors()->delete('fc-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/freeswitch_connectors/fc-1', 'fabric.delete_freeswitch_connector');
    }

    #[Test]
    public function freeswitchConnectorsDeleteError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_freeswitch_connector', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->freeswitchConnectors()->delete('fc-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_freeswitch_connector');
    }

    #[Test]
    public function freeswitchConnectorsListAddressesSuccess(): void
    {
        $body = $this->client->fabric()->freeswitchConnectors()->listAddresses('fc-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/freeswitch_connectors/fc-1/addresses', 'fabric.list_freeswitch_connector_addresses');
    }

    #[Test]
    public function freeswitchConnectorsListAddressesError(): void
    {
        $this->mock->scenarios()->set('fabric.list_freeswitch_connector_addresses', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->freeswitchConnectors()->listAddresses('fc-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_freeswitch_connector_addresses');
    }

    // =================================================================
    // relay_applications (PUT update)
    // =================================================================

    #[Test]
    public function relayApplicationsListSuccess(): void
    {
        $body = $this->client->fabric()->relayApplications()->list();
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/relay_applications', 'fabric.list_relay_applications');
    }

    #[Test]
    public function relayApplicationsListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_relay_applications', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->relayApplications()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_relay_applications');
    }

    #[Test]
    public function relayApplicationsCreateSuccess(): void
    {
        $body = $this->client->fabric()->relayApplications()->create(['name' => 'r']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/relay_applications', 'fabric.create_relay_application');
    }

    #[Test]
    public function relayApplicationsCreateError(): void
    {
        $this->mock->scenarios()->set('fabric.create_relay_application', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->relayApplications()->create(['name' => 'r']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_relay_application');
    }

    #[Test]
    public function relayApplicationsGetSuccess(): void
    {
        $body = $this->client->fabric()->relayApplications()->get('ra-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/relay_applications/ra-1', 'fabric.get_relay_application');
    }

    #[Test]
    public function relayApplicationsGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_relay_application', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->relayApplications()->get('ra-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_relay_application');
    }

    #[Test]
    public function relayApplicationsUpdateSuccess(): void
    {
        $body = $this->client->fabric()->relayApplications()->update('ra-1', ['name' => 'x']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('PUT', '/api/fabric/resources/relay_applications/ra-1', 'fabric.update_relay_application');
    }

    #[Test]
    public function relayApplicationsUpdateError(): void
    {
        $this->mock->scenarios()->set('fabric.update_relay_application', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->relayApplications()->update('ra-1', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.update_relay_application');
    }

    #[Test]
    public function relayApplicationsDeleteSuccess(): void
    {
        $body = $this->client->fabric()->relayApplications()->delete('ra-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/relay_applications/ra-1', 'fabric.delete_relay_application');
    }

    #[Test]
    public function relayApplicationsDeleteError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_relay_application', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->relayApplications()->delete('ra-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_relay_application');
    }

    #[Test]
    public function relayApplicationsListAddressesSuccess(): void
    {
        $body = $this->client->fabric()->relayApplications()->listAddresses('ra-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/relay_applications/ra-1/addresses', 'fabric.list_relay_application_addresses');
    }

    #[Test]
    public function relayApplicationsListAddressesError(): void
    {
        $this->mock->scenarios()->set('fabric.list_relay_application_addresses', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->relayApplications()->listAddresses('ra-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_relay_application_addresses');
    }

    // =================================================================
    // sip_endpoints (PUT update)
    // =================================================================

    #[Test]
    public function sipEndpointsListSuccess(): void
    {
        $body = $this->client->fabric()->sipEndpoints()->list();
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/sip_endpoints', 'fabric.list_sip_endpoints');
    }

    #[Test]
    public function sipEndpointsListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_sip_endpoints', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->sipEndpoints()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_sip_endpoints');
    }

    #[Test]
    public function sipEndpointsCreateSuccess(): void
    {
        $body = $this->client->fabric()->sipEndpoints()->create(['username' => 'u']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/sip_endpoints', 'fabric.create_sip_endpoint');
    }

    #[Test]
    public function sipEndpointsCreateError(): void
    {
        $this->mock->scenarios()->set('fabric.create_sip_endpoint', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->sipEndpoints()->create(['username' => 'u']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_sip_endpoint');
    }

    #[Test]
    public function sipEndpointsGetSuccess(): void
    {
        $body = $this->client->fabric()->sipEndpoints()->get('se-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/sip_endpoints/se-1', 'fabric.get_sip_endpoint');
    }

    #[Test]
    public function sipEndpointsGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_sip_endpoint', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->sipEndpoints()->get('se-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_sip_endpoint');
    }

    #[Test]
    public function sipEndpointsUpdateSuccess(): void
    {
        $body = $this->client->fabric()->sipEndpoints()->update('se-1', ['username' => 'x']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('PUT', '/api/fabric/resources/sip_endpoints/se-1', 'fabric.update_sip_endpoint');
    }

    #[Test]
    public function sipEndpointsUpdateError(): void
    {
        $this->mock->scenarios()->set('fabric.update_sip_endpoint', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->sipEndpoints()->update('se-1', ['username' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.update_sip_endpoint');
    }

    #[Test]
    public function sipEndpointsDeleteSuccess(): void
    {
        $body = $this->client->fabric()->sipEndpoints()->delete('se-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/sip_endpoints/se-1', 'fabric.delete_sip_endpoint');
    }

    #[Test]
    public function sipEndpointsDeleteError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_sip_endpoint', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->sipEndpoints()->delete('se-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_sip_endpoint');
    }

    #[Test]
    public function sipEndpointsListAddressesSuccess(): void
    {
        $body = $this->client->fabric()->sipEndpoints()->listAddresses('se-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/sip_endpoints/se-1/addresses', 'fabric.list_sip_endpoint_addresses');
    }

    #[Test]
    public function sipEndpointsListAddressesError(): void
    {
        $this->mock->scenarios()->set('fabric.list_sip_endpoint_addresses', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->sipEndpoints()->listAddresses('se-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_sip_endpoint_addresses');
    }

    // =================================================================
    // sip_gateways (PATCH update); note: list_sip_gateway_addresses is
    // an accepted gap (doubled-path artifact) and is NOT covered.
    // =================================================================

    #[Test]
    public function sipGatewaysListSuccess(): void
    {
        $body = $this->client->fabric()->sipGateways()->list();
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/sip_gateways', 'fabric.list_sip_gateways');
    }

    #[Test]
    public function sipGatewaysListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_sip_gateways', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->sipGateways()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_sip_gateways');
    }

    #[Test]
    public function sipGatewaysCreateSuccess(): void
    {
        $body = $this->client->fabric()->sipGateways()->create(['name' => 'g']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/sip_gateways', 'fabric.create_sip_gateway');
    }

    #[Test]
    public function sipGatewaysCreateError(): void
    {
        $this->mock->scenarios()->set('fabric.create_sip_gateway', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->sipGateways()->create(['name' => 'g']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_sip_gateway');
    }

    #[Test]
    public function sipGatewaysGetSuccess(): void
    {
        $body = $this->client->fabric()->sipGateways()->get('sg-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/sip_gateways/sg-1', 'fabric.get_sip_gateway');
    }

    #[Test]
    public function sipGatewaysGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_sip_gateway', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->sipGateways()->get('sg-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_sip_gateway');
    }

    #[Test]
    public function sipGatewaysUpdateSuccess(): void
    {
        $body = $this->client->fabric()->sipGateways()->update('sg-1', ['name' => 'x']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('PATCH', '/api/fabric/resources/sip_gateways/sg-1', 'fabric.update_sip_gateway');
    }

    #[Test]
    public function sipGatewaysUpdateError(): void
    {
        $this->mock->scenarios()->set('fabric.update_sip_gateway', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->sipGateways()->update('sg-1', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.update_sip_gateway');
    }

    #[Test]
    public function sipGatewaysDeleteSuccess(): void
    {
        $body = $this->client->fabric()->sipGateways()->delete('sg-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/sip_gateways/sg-1', 'fabric.delete_sip_gateway');
    }

    #[Test]
    public function sipGatewaysDeleteError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_sip_gateway', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->sipGateways()->delete('sg-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_sip_gateway');
    }

    // =================================================================
    // subscribers (PUT update) + SIP endpoint sub-resources
    // =================================================================

    #[Test]
    public function subscribersListSuccess(): void
    {
        $body = $this->client->fabric()->subscribers()->list();
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/subscribers', 'fabric.list_subscribers');
    }

    #[Test]
    public function subscribersListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_subscribers', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->subscribers()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_subscribers');
    }

    #[Test]
    public function subscribersCreateSuccess(): void
    {
        $body = $this->client->fabric()->subscribers()->create(['email' => 's@e.com']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/subscribers', 'fabric.create_subscriber');
    }

    #[Test]
    public function subscribersCreateError(): void
    {
        $this->mock->scenarios()->set('fabric.create_subscriber', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->subscribers()->create(['email' => 's@e.com']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_subscriber');
    }

    #[Test]
    public function subscribersGetSuccess(): void
    {
        $body = $this->client->fabric()->subscribers()->get('sub-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/subscribers/sub-1', 'fabric.get_subscriber');
    }

    #[Test]
    public function subscribersGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_subscriber', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->subscribers()->get('sub-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_subscriber');
    }

    #[Test]
    public function subscribersUpdateSuccess(): void
    {
        $body = $this->client->fabric()->subscribers()->update('sub-1', ['email' => 'x@e.com']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('PUT', '/api/fabric/resources/subscribers/sub-1', 'fabric.update_subscriber');
    }

    #[Test]
    public function subscribersUpdateError(): void
    {
        $this->mock->scenarios()->set('fabric.update_subscriber', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->subscribers()->update('sub-1', ['email' => 'x@e.com']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.update_subscriber');
    }

    #[Test]
    public function subscribersDeleteSuccess(): void
    {
        $body = $this->client->fabric()->subscribers()->delete('sub-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/subscribers/sub-1', 'fabric.delete_subscriber');
    }

    #[Test]
    public function subscribersDeleteError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_subscriber', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->subscribers()->delete('sub-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_subscriber');
    }

    #[Test]
    public function subscribersListAddressesSuccess(): void
    {
        $body = $this->client->fabric()->subscribers()->listAddresses('sub-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/subscribers/sub-1/addresses', 'fabric.list_subscriber_addresses');
    }

    #[Test]
    public function subscribersListAddressesError(): void
    {
        $this->mock->scenarios()->set('fabric.list_subscriber_addresses', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->subscribers()->listAddresses('sub-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_subscriber_addresses');
    }

    #[Test]
    public function subscribersListSipEndpointsSuccess(): void
    {
        $body = $this->client->fabric()->subscribers()->listSipEndpoints('sub-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/subscribers/sub-1/sip_endpoints', 'fabric.list_subscriber_sip_endpoints');
    }

    #[Test]
    public function subscribersListSipEndpointsError(): void
    {
        $this->mock->scenarios()->set('fabric.list_subscriber_sip_endpoints', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->subscribers()->listSipEndpoints('sub-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_subscriber_sip_endpoints');
    }

    #[Test]
    public function subscribersCreateSipEndpointSuccess(): void
    {
        $body = $this->client->fabric()->subscribers()->createSipEndpoint('sub-1', ['username' => 'u']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/subscribers/sub-1/sip_endpoints', 'fabric.create_subscriber_sip_endpoint');
    }

    #[Test]
    public function subscribersCreateSipEndpointError(): void
    {
        $this->mock->scenarios()->set('fabric.create_subscriber_sip_endpoint', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->subscribers()->createSipEndpoint('sub-1', ['username' => 'u']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_subscriber_sip_endpoint');
    }

    #[Test]
    public function subscribersGetSipEndpointSuccess(): void
    {
        $body = $this->client->fabric()->subscribers()->getSipEndpoint('sub-1', 'ep-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/subscribers/sub-1/sip_endpoints/ep-1', 'fabric.get_subscriber_sip_endpoint');
    }

    #[Test]
    public function subscribersGetSipEndpointError(): void
    {
        $this->mock->scenarios()->set('fabric.get_subscriber_sip_endpoint', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->subscribers()->getSipEndpoint('sub-1', 'ep-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_subscriber_sip_endpoint');
    }

    #[Test]
    public function subscribersUpdateSipEndpointSuccess(): void
    {
        $body = $this->client->fabric()->subscribers()->updateSipEndpoint('sub-1', 'ep-1', ['username' => 'x']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('PATCH', '/api/fabric/resources/subscribers/sub-1/sip_endpoints/ep-1', 'fabric.update_subscriber_sip_endpoint');
    }

    #[Test]
    public function subscribersUpdateSipEndpointError(): void
    {
        $this->mock->scenarios()->set('fabric.update_subscriber_sip_endpoint', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->subscribers()->updateSipEndpoint('sub-1', 'ep-1', ['username' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.update_subscriber_sip_endpoint');
    }

    #[Test]
    public function subscribersDeleteSipEndpointSuccess(): void
    {
        $body = $this->client->fabric()->subscribers()->deleteSipEndpoint('sub-1', 'ep-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/subscribers/sub-1/sip_endpoints/ep-1', 'fabric.delete_subscriber_sip_endpoint');
    }

    #[Test]
    public function subscribersDeleteSipEndpointError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_subscriber_sip_endpoint', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->subscribers()->deleteSipEndpoint('sub-1', 'ep-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_subscriber_sip_endpoint');
    }

    // =================================================================
    // swml_scripts (PUT update)
    // =================================================================

    #[Test]
    public function swmlScriptsListSuccess(): void
    {
        $body = $this->client->fabric()->swmlScripts()->list();
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/swml_scripts', 'fabric.list_swml_scripts');
    }

    #[Test]
    public function swmlScriptsListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_swml_scripts', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->swmlScripts()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_swml_scripts');
    }

    #[Test]
    public function swmlScriptsCreateSuccess(): void
    {
        $body = $this->client->fabric()->swmlScripts()->create(['name' => 's']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/swml_scripts', 'fabric.create_swml_script');
    }

    #[Test]
    public function swmlScriptsCreateError(): void
    {
        $this->mock->scenarios()->set('fabric.create_swml_script', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->swmlScripts()->create(['name' => 's']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_swml_script');
    }

    #[Test]
    public function swmlScriptsGetSuccess(): void
    {
        $body = $this->client->fabric()->swmlScripts()->get('ss-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/swml_scripts/ss-1', 'fabric.get_swml_script');
    }

    #[Test]
    public function swmlScriptsGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_swml_script', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->swmlScripts()->get('ss-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_swml_script');
    }

    #[Test]
    public function swmlScriptsUpdateSuccess(): void
    {
        $body = $this->client->fabric()->swmlScripts()->update('ss-1', ['name' => 'x']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('PUT', '/api/fabric/resources/swml_scripts/ss-1', 'fabric.update_swml_script');
    }

    #[Test]
    public function swmlScriptsUpdateError(): void
    {
        $this->mock->scenarios()->set('fabric.update_swml_script', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->swmlScripts()->update('ss-1', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.update_swml_script');
    }

    #[Test]
    public function swmlScriptsDeleteSuccess(): void
    {
        $body = $this->client->fabric()->swmlScripts()->delete('ss-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/swml_scripts/ss-1', 'fabric.delete_swml_script');
    }

    #[Test]
    public function swmlScriptsDeleteError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_swml_script', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->swmlScripts()->delete('ss-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_swml_script');
    }

    #[Test]
    public function swmlScriptsListAddressesSuccess(): void
    {
        $body = $this->client->fabric()->swmlScripts()->listAddresses('ss-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/swml_scripts/ss-1/addresses', 'fabric.list_swml_script_addresses');
    }

    #[Test]
    public function swmlScriptsListAddressesError(): void
    {
        $this->mock->scenarios()->set('fabric.list_swml_script_addresses', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->swmlScripts()->listAddresses('ss-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_swml_script_addresses');
    }

    // =================================================================
    // swml_webhooks (PATCH update)
    // =================================================================

    #[Test]
    public function swmlWebhooksListSuccess(): void
    {
        $body = $this->client->fabric()->swmlWebhooks()->list();
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/swml_webhooks', 'fabric.list_swml_webhooks');
    }

    #[Test]
    public function swmlWebhooksListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_swml_webhooks', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->swmlWebhooks()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_swml_webhooks');
    }

    #[Test]
    public function swmlWebhooksCreateSuccess(): void
    {
        $body = $this->client->fabric()->swmlWebhooks()->create(['name' => 'w']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/swml_webhooks', 'fabric.create_swml_webhook');
    }

    #[Test]
    public function swmlWebhooksCreateError(): void
    {
        $this->mock->scenarios()->set('fabric.create_swml_webhook', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->swmlWebhooks()->create(['name' => 'w']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_swml_webhook');
    }

    #[Test]
    public function swmlWebhooksGetSuccess(): void
    {
        $body = $this->client->fabric()->swmlWebhooks()->get('sw-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/swml_webhooks/sw-1', 'fabric.get_swml_webhook');
    }

    #[Test]
    public function swmlWebhooksGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_swml_webhook', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->swmlWebhooks()->get('sw-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_swml_webhook');
    }

    #[Test]
    public function swmlWebhooksUpdateSuccess(): void
    {
        $body = $this->client->fabric()->swmlWebhooks()->update('sw-1', ['name' => 'x']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('PATCH', '/api/fabric/resources/swml_webhooks/sw-1', 'fabric.update_swml_webhook');
    }

    #[Test]
    public function swmlWebhooksUpdateError(): void
    {
        $this->mock->scenarios()->set('fabric.update_swml_webhook', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->swmlWebhooks()->update('sw-1', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.update_swml_webhook');
    }

    #[Test]
    public function swmlWebhooksDeleteSuccess(): void
    {
        $body = $this->client->fabric()->swmlWebhooks()->delete('sw-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/swml_webhooks/sw-1', 'fabric.delete_swml_webhook');
    }

    #[Test]
    public function swmlWebhooksDeleteError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_swml_webhook', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->swmlWebhooks()->delete('sw-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_swml_webhook');
    }

    #[Test]
    public function swmlWebhooksListAddressesSuccess(): void
    {
        $body = $this->client->fabric()->swmlWebhooks()->listAddresses('sw-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/swml_webhooks/sw-1/addresses', 'fabric.list_swml_webhook_addresses');
    }

    #[Test]
    public function swmlWebhooksListAddressesError(): void
    {
        $this->mock->scenarios()->set('fabric.list_swml_webhook_addresses', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->swmlWebhooks()->listAddresses('sw-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_swml_webhook_addresses');
    }

    // =================================================================
    // generic resources (list/get/delete/listAddresses/assignDomainApp)
    // =================================================================

    #[Test]
    public function resourcesListSuccess(): void
    {
        $body = $this->client->fabric()->resources()->list();
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources', 'fabric.list_resources');
    }

    #[Test]
    public function resourcesListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_resources', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->resources()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_resources');
    }

    #[Test]
    public function resourcesGetSuccess(): void
    {
        $body = $this->client->fabric()->resources()->get('res-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/res-1', 'fabric.get_resource');
    }

    #[Test]
    public function resourcesGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_resource', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->resources()->get('res-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_resource');
    }

    #[Test]
    public function resourcesDeleteSuccess(): void
    {
        $body = $this->client->fabric()->resources()->delete('res-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('DELETE', '/api/fabric/resources/res-1', 'fabric.delete_resource');
    }

    #[Test]
    public function resourcesDeleteError(): void
    {
        $this->mock->scenarios()->set('fabric.delete_resource', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->resources()->delete('res-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.delete_resource');
    }

    #[Test]
    public function resourcesListAddressesSuccess(): void
    {
        $body = $this->client->fabric()->resources()->listAddresses('res-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/resources/res-1/addresses', 'fabric.list_resource_addresses');
    }

    #[Test]
    public function resourcesListAddressesError(): void
    {
        $this->mock->scenarios()->set('fabric.list_resource_addresses', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->resources()->listAddresses('res-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_resource_addresses');
    }

    #[Test]
    public function resourcesAssignPhoneRouteSuccess(): void
    {
        $body = $this->client->fabric()->resources()->assignPhoneRoute('res-1', ['resource_id' => 'r-2']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/res-1/phone_routes', 'fabric.assign_resource_phone_route');
    }

    #[Test]
    public function resourcesAssignPhoneRouteError(): void
    {
        $this->mock->scenarios()->set('fabric.assign_resource_phone_route', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->resources()->assignPhoneRoute('res-1', ['resource_id' => 'r-2']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.assign_resource_phone_route');
    }

    #[Test]
    public function resourcesAssignDomainApplicationSuccess(): void
    {
        $body = $this->client->fabric()->resources()->assignDomainApplication('res-1', ['domain' => 'd.test']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/resources/res-1/domain_applications', 'fabric.assign_resource_domain_application');
    }

    #[Test]
    public function resourcesAssignDomainApplicationError(): void
    {
        $this->mock->scenarios()->set('fabric.assign_resource_domain_application', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->resources()->assignDomainApplication('res-1', ['domain' => 'd.test']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.assign_resource_domain_application');
    }

    // =================================================================
    // fabric addresses (read-only: list/get)
    // =================================================================

    #[Test]
    public function fabricAddressesListSuccess(): void
    {
        $body = $this->client->fabric()->addresses()->list();
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/addresses', 'fabric.list_fabric_addresses');
    }

    #[Test]
    public function fabricAddressesListError(): void
    {
        $this->mock->scenarios()->set('fabric.list_fabric_addresses', 500, ['error' => 'boom']);
        try {
            $this->client->fabric()->addresses()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertErrorJournal(500, 'fabric.list_fabric_addresses');
    }

    #[Test]
    public function fabricAddressesGetSuccess(): void
    {
        $body = $this->client->fabric()->addresses()->get('addr-1');
        $this->assertIsArray($body);
        $this->assertSuccessJournal('GET', '/api/fabric/addresses/addr-1', 'fabric.get_fabric_address');
    }

    #[Test]
    public function fabricAddressesGetError(): void
    {
        $this->mock->scenarios()->set('fabric.get_fabric_address', 404, ['error' => 'nf']);
        try {
            $this->client->fabric()->addresses()->get('addr-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertErrorJournal(404, 'fabric.get_fabric_address');
    }

    // =================================================================
    // tokens (subscriber / refresh / invite / guest / embed)
    // =================================================================

    #[Test]
    public function tokensCreateSubscriberTokenSuccess(): void
    {
        $body = $this->client->fabric()->tokens()->createSubscriberToken(['reference' => 'r']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/subscribers/tokens', 'fabric.create_subscriber_token');
    }

    #[Test]
    public function tokensCreateSubscriberTokenError(): void
    {
        $this->mock->scenarios()->set('fabric.create_subscriber_token', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->tokens()->createSubscriberToken(['reference' => 'r']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_subscriber_token');
    }

    #[Test]
    public function tokensRefreshSubscriberTokenSuccess(): void
    {
        $body = $this->client->fabric()->tokens()->refreshSubscriberToken(['refresh_token' => 'rt']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/subscribers/tokens/refresh', 'fabric.refresh_subscriber_token');
    }

    #[Test]
    public function tokensRefreshSubscriberTokenError(): void
    {
        $this->mock->scenarios()->set('fabric.refresh_subscriber_token', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->tokens()->refreshSubscriberToken(['refresh_token' => 'rt']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.refresh_subscriber_token');
    }

    #[Test]
    public function tokensCreateInviteTokenSuccess(): void
    {
        $body = $this->client->fabric()->tokens()->createInviteToken(['email' => 'i@e.com']);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/subscriber/invites', 'fabric.create_subscriber_invite_token');
    }

    #[Test]
    public function tokensCreateInviteTokenError(): void
    {
        $this->mock->scenarios()->set('fabric.create_subscriber_invite_token', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->tokens()->createInviteToken(['email' => 'i@e.com']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_subscriber_invite_token');
    }

    #[Test]
    public function tokensCreateGuestTokenSuccess(): void
    {
        $body = $this->client->fabric()->tokens()->createGuestToken(['allowed_addresses' => ['a-1']]);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/guests/tokens', 'fabric.create_subscriber_guest_token');
    }

    #[Test]
    public function tokensCreateGuestTokenError(): void
    {
        $this->mock->scenarios()->set('fabric.create_subscriber_guest_token', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->tokens()->createGuestToken(['allowed_addresses' => ['a-1']]);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_subscriber_guest_token');
    }

    #[Test]
    public function tokensCreateEmbedTokenSuccess(): void
    {
        $body = $this->client->fabric()->tokens()->createEmbedToken(['allowed_addresses' => ['a-1']]);
        $this->assertIsArray($body);
        $this->assertSuccessJournal('POST', '/api/fabric/embeds/tokens', 'fabric.create_embeds_token');
    }

    #[Test]
    public function tokensCreateEmbedTokenError(): void
    {
        $this->mock->scenarios()->set('fabric.create_embeds_token', 422, ['error' => 'bad']);
        try {
            $this->client->fabric()->tokens()->createEmbedToken(['allowed_addresses' => ['a-1']]);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertErrorJournal(422, 'fabric.create_embeds_token');
    }
}
