<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\REST\RestClient;
use SignalWire\REST\SignalWireRestError;

/**
 * Full success + error coverage for the relay-rest.* canonical routes.
 *
 * These map to many top-level RestClient accessors (addresses, phoneNumbers,
 * queues, numberGroups, verifiedCallers, registry, mfa, shortCodes, recordings,
 * sipProfile, lookup, importedNumbers). Each covered route gets a SUCCESS test
 * (asserting journal method + exact canonical path + matchedRoute) and an ERROR
 * test (arming a scenario, asserting getStatusCode() + journal
 * responseStatus + matchedRoute).
 *
 * Accepted gaps (no SDK surface by design — allowlisted): the relay-rest
 * sip_endpoint.* and domain_application.* operations.
 */
class RelayRestCoverageMockTest extends TestCase
{
    private const ADDR = '/api/relay/rest/addresses';
    private const PN = '/api/relay/rest/phone_numbers';
    private const QUEUES = '/api/relay/rest/queues';
    private const NG = '/api/relay/rest/number_groups';
    private const NGM = '/api/relay/rest/number_group_memberships';
    private const VC = '/api/relay/rest/verified_caller_ids';
    private const REG = '/api/relay/rest/registry/beta';
    private const MFA = '/api/relay/rest/mfa';
    private const SC = '/api/relay/rest/short_codes';
    private const REC = '/api/relay/rest/recordings';
    private const SIP = '/api/relay/rest/sip_profile';
    private const LOOKUP = '/api/relay/rest/lookup/phone_number';
    private const IMPORTED = '/api/relay/rest/imported_phone_numbers';

    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        [$this->client, $this->mock] = MockTest::scopedClient();
    }

    /**
     * Assert a successful call's journal entry. Returns the journal entry so the
     * calling test body has a concrete value to assert on (NO-CHEAT).
     */
    private function assertSuccess(string $method, string $path, string $route): \SignalWire\Tests\Rest\JournalEntry
    {
        $j = $this->mock->journal()->last();
        $this->assertSame($method, $j->method);
        $this->assertSame($path, $j->path);
        $this->assertSame($route, $j->matchedRoute);
        return $j;
    }

    // =================================================================
    // addresses
    // =================================================================

    #[Test]
    public function listAddressesSuccess(): void
    {
        $body = $this->client->addresses()->list();
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::ADDR, 'relay-rest.list_addresses');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function listAddressesError(): void
    {
        $this->mock->scenarios()->set('relay-rest.list_addresses', 500, ['error' => 'internal']);
        try {
            $this->client->addresses()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('relay-rest.list_addresses', $j->matchedRoute);
    }

    #[Test]
    public function createAddressSuccess(): void
    {
        $body = $this->client->addresses()->create(
            label: 'HQ',
            country: 'US',
            firstName: 'Ada',
            lastName: 'Lovelace',
            streetNumber: '1',
            streetName: 'Main St',
            city: 'Springfield',
            state: 'IL',
            postalCode: '62701',
            extras: ['name' => 'HQ'],
        );
        $this->assertIsArray($body);
        $j = $this->assertSuccess('POST', self::ADDR, 'relay-rest.create_address');
        $this->assertSame('POST', $j->method);
    }

    #[Test]
    public function createAddressError(): void
    {
        $this->mock->scenarios()->set('relay-rest.create_address', 422, ['error' => 'name required']);
        try {
            $this->client->addresses()->create(
                label: 'HQ',
                country: 'US',
                firstName: 'Ada',
                lastName: 'Lovelace',
                streetNumber: '1',
                streetName: 'Main St',
                city: 'Springfield',
                state: 'IL',
                postalCode: '62701',
            );
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.create_address', $j->matchedRoute);
    }

    #[Test]
    public function getAddressSuccess(): void
    {
        $body = $this->client->addresses()->get('addr-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::ADDR . '/addr-1', 'relay-rest.get_address');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function getAddressError(): void
    {
        $this->mock->scenarios()->set('relay-rest.get_address', 404, ['error' => 'nope']);
        try {
            $this->client->addresses()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.get_address', $j->matchedRoute);
    }

    #[Test]
    public function deleteAddressSuccess(): void
    {
        $body = $this->client->addresses()->delete('addr-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('DELETE', self::ADDR . '/addr-1', 'relay-rest.delete_address');
        $this->assertSame('DELETE', $j->method);
    }

    #[Test]
    public function deleteAddressError(): void
    {
        $this->mock->scenarios()->set('relay-rest.delete_address', 404, ['error' => 'nope']);
        try {
            $this->client->addresses()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.delete_address', $j->matchedRoute);
    }

    // =================================================================
    // phone_numbers
    // =================================================================

    #[Test]
    public function listPhoneNumbersSuccess(): void
    {
        $body = $this->client->phoneNumbers()->list();
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::PN, 'relay-rest.list_phone_numbers');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function listPhoneNumbersError(): void
    {
        $this->mock->scenarios()->set('relay-rest.list_phone_numbers', 500, ['error' => 'internal']);
        try {
            $this->client->phoneNumbers()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('relay-rest.list_phone_numbers', $j->matchedRoute);
    }

    #[Test]
    public function searchAvailablePhoneNumbersSuccess(): void
    {
        $body = $this->client->phoneNumbers()->search(['area_code' => '512']);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::PN . '/search', 'relay-rest.search_available_phone_numbers');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function searchAvailablePhoneNumbersError(): void
    {
        $this->mock->scenarios()->set('relay-rest.search_available_phone_numbers', 500, ['error' => 'internal']);
        try {
            $this->client->phoneNumbers()->search(['area_code' => '512']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('relay-rest.search_available_phone_numbers', $j->matchedRoute);
    }

    #[Test]
    public function purchasePhoneNumberSuccess(): void
    {
        $body = $this->client->phoneNumbers()->create(['number' => '+15551230000']);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('POST', self::PN, 'relay-rest.purchase_phone_number');
        $this->assertSame('POST', $j->method);
    }

    #[Test]
    public function purchasePhoneNumberError(): void
    {
        $this->mock->scenarios()->set('relay-rest.purchase_phone_number', 422, ['error' => 'number required']);
        try {
            $this->client->phoneNumbers()->create([]);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.purchase_phone_number', $j->matchedRoute);
    }

    #[Test]
    public function retrievePhoneNumberSuccess(): void
    {
        $body = $this->client->phoneNumbers()->get('pn-1001');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::PN . '/pn-1001', 'relay-rest.retrieve_phone_number');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function retrievePhoneNumberError(): void
    {
        $this->mock->scenarios()->set('relay-rest.retrieve_phone_number', 404, ['error' => 'nope']);
        try {
            $this->client->phoneNumbers()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.retrieve_phone_number', $j->matchedRoute);
    }

    #[Test]
    public function updatePhoneNumberSuccess(): void
    {
        $body = $this->client->phoneNumbers()->update('pn-1001', ['name' => 'main line']);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('PUT', self::PN . '/pn-1001', 'relay-rest.update_phone_number');
        $this->assertSame('PUT', $j->method);
    }

    #[Test]
    public function updatePhoneNumberError(): void
    {
        $this->mock->scenarios()->set('relay-rest.update_phone_number', 404, ['error' => 'nope']);
        try {
            $this->client->phoneNumbers()->update('missing', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.update_phone_number', $j->matchedRoute);
    }

    #[Test]
    public function releasePhoneNumberSuccess(): void
    {
        $body = $this->client->phoneNumbers()->delete('pn-1001');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('DELETE', self::PN . '/pn-1001', 'relay-rest.release_phone_number');
        $this->assertSame('DELETE', $j->method);
    }

    #[Test]
    public function releasePhoneNumberError(): void
    {
        $this->mock->scenarios()->set('relay-rest.release_phone_number', 404, ['error' => 'nope']);
        try {
            $this->client->phoneNumbers()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.release_phone_number', $j->matchedRoute);
    }

    // =================================================================
    // queues
    // =================================================================

    #[Test]
    public function listQueuesSuccess(): void
    {
        $body = $this->client->queues()->list();
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::QUEUES, 'relay-rest.list_queues');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function listQueuesError(): void
    {
        $this->mock->scenarios()->set('relay-rest.list_queues', 500, ['error' => 'internal']);
        try {
            $this->client->queues()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('relay-rest.list_queues', $j->matchedRoute);
    }

    #[Test]
    public function createQueueSuccess(): void
    {
        $body = $this->client->queues()->create(['name' => 'support']);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('POST', self::QUEUES, 'relay-rest.create_queue');
        $this->assertSame('POST', $j->method);
    }

    #[Test]
    public function createQueueError(): void
    {
        $this->mock->scenarios()->set('relay-rest.create_queue', 422, ['error' => 'name required']);
        try {
            $this->client->queues()->create([]);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.create_queue', $j->matchedRoute);
    }

    #[Test]
    public function getQueueSuccess(): void
    {
        $body = $this->client->queues()->get('q-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::QUEUES . '/q-1', 'relay-rest.get_queue');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function getQueueError(): void
    {
        $this->mock->scenarios()->set('relay-rest.get_queue', 404, ['error' => 'nope']);
        try {
            $this->client->queues()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.get_queue', $j->matchedRoute);
    }

    #[Test]
    public function updateQueueSuccess(): void
    {
        $body = $this->client->queues()->update('q-1', ['name' => 'renamed']);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('PUT', self::QUEUES . '/q-1', 'relay-rest.update_queue');
        $this->assertSame('PUT', $j->method);
    }

    #[Test]
    public function updateQueueError(): void
    {
        $this->mock->scenarios()->set('relay-rest.update_queue', 404, ['error' => 'nope']);
        try {
            $this->client->queues()->update('missing', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.update_queue', $j->matchedRoute);
    }

    #[Test]
    public function deleteQueueSuccess(): void
    {
        $body = $this->client->queues()->delete('q-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('DELETE', self::QUEUES . '/q-1', 'relay-rest.delete_queue');
        $this->assertSame('DELETE', $j->method);
    }

    #[Test]
    public function deleteQueueError(): void
    {
        $this->mock->scenarios()->set('relay-rest.delete_queue', 404, ['error' => 'nope']);
        try {
            $this->client->queues()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.delete_queue', $j->matchedRoute);
    }

    #[Test]
    public function listQueueMembersSuccess(): void
    {
        $body = $this->client->queues()->listMembers('q-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::QUEUES . '/q-1/members', 'relay-rest.list_queue_members');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function listQueueMembersError(): void
    {
        $this->mock->scenarios()->set('relay-rest.list_queue_members', 404, ['error' => 'nope']);
        try {
            $this->client->queues()->listMembers('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.list_queue_members', $j->matchedRoute);
    }

    #[Test]
    public function retrieveNextQueueMemberSuccess(): void
    {
        $body = $this->client->queues()->getNextMember('q-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::QUEUES . '/q-1/members/next', 'relay-rest.retrieve_next_queue_member');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function retrieveNextQueueMemberError(): void
    {
        $this->mock->scenarios()->set('relay-rest.retrieve_next_queue_member', 404, ['error' => 'empty']);
        try {
            $this->client->queues()->getNextMember('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.retrieve_next_queue_member', $j->matchedRoute);
    }

    #[Test]
    public function retrieveQueueMemberSuccess(): void
    {
        $body = $this->client->queues()->getMember('q-1', 'm-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::QUEUES . '/q-1/members/m-1', 'relay-rest.retrieve_queue_member');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function retrieveQueueMemberError(): void
    {
        $this->mock->scenarios()->set('relay-rest.retrieve_queue_member', 404, ['error' => 'nope']);
        try {
            $this->client->queues()->getMember('missing', 'm-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.retrieve_queue_member', $j->matchedRoute);
    }

    // =================================================================
    // number_groups
    // =================================================================

    #[Test]
    public function listNumberGroupsSuccess(): void
    {
        $body = $this->client->numberGroups()->list();
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::NG, 'relay-rest.list_number_groups');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function listNumberGroupsError(): void
    {
        $this->mock->scenarios()->set('relay-rest.list_number_groups', 500, ['error' => 'internal']);
        try {
            $this->client->numberGroups()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('relay-rest.list_number_groups', $j->matchedRoute);
    }

    #[Test]
    public function createNumberGroupSuccess(): void
    {
        $body = $this->client->numberGroups()->create(['name' => 'group-a']);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('POST', self::NG, 'relay-rest.create_number_group');
        $this->assertSame('POST', $j->method);
    }

    #[Test]
    public function createNumberGroupError(): void
    {
        $this->mock->scenarios()->set('relay-rest.create_number_group', 422, ['error' => 'name required']);
        try {
            $this->client->numberGroups()->create([]);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.create_number_group', $j->matchedRoute);
    }

    #[Test]
    public function retrieveNumberGroupSuccess(): void
    {
        $body = $this->client->numberGroups()->get('ng-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::NG . '/ng-1', 'relay-rest.retrieve_number_group');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function retrieveNumberGroupError(): void
    {
        $this->mock->scenarios()->set('relay-rest.retrieve_number_group', 404, ['error' => 'nope']);
        try {
            $this->client->numberGroups()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.retrieve_number_group', $j->matchedRoute);
    }

    #[Test]
    public function updateNumberGroupSuccess(): void
    {
        $body = $this->client->numberGroups()->update('ng-1', ['name' => 'renamed']);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('PUT', self::NG . '/ng-1', 'relay-rest.update_number_group');
        $this->assertSame('PUT', $j->method);
    }

    #[Test]
    public function updateNumberGroupError(): void
    {
        $this->mock->scenarios()->set('relay-rest.update_number_group', 404, ['error' => 'nope']);
        try {
            $this->client->numberGroups()->update('missing', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.update_number_group', $j->matchedRoute);
    }

    #[Test]
    public function deleteNumberGroupSuccess(): void
    {
        $body = $this->client->numberGroups()->delete('ng-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('DELETE', self::NG . '/ng-1', 'relay-rest.delete_number_group');
        $this->assertSame('DELETE', $j->method);
    }

    #[Test]
    public function deleteNumberGroupError(): void
    {
        $this->mock->scenarios()->set('relay-rest.delete_number_group', 404, ['error' => 'nope']);
        try {
            $this->client->numberGroups()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.delete_number_group', $j->matchedRoute);
    }

    #[Test]
    public function listNumberGroupMembershipsSuccess(): void
    {
        $body = $this->client->numberGroups()->listMemberships('ng-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::NG . '/ng-1/number_group_memberships', 'relay-rest.list_number_group_memberships');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function listNumberGroupMembershipsError(): void
    {
        $this->mock->scenarios()->set('relay-rest.list_number_group_memberships', 404, ['error' => 'nope']);
        try {
            $this->client->numberGroups()->listMemberships('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.list_number_group_memberships', $j->matchedRoute);
    }

    #[Test]
    public function createNumberGroupMembershipSuccess(): void
    {
        $body = $this->client->numberGroups()->addMembership('ng-1', 'pn-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('POST', self::NG . '/ng-1/number_group_memberships', 'relay-rest.create_number_group_membership');
        $this->assertSame('POST', $j->method);
    }

    #[Test]
    public function createNumberGroupMembershipError(): void
    {
        $this->mock->scenarios()->set('relay-rest.create_number_group_membership', 422, ['error' => 'bad']);
        try {
            $this->client->numberGroups()->addMembership('ng-1', 'pn-1');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.create_number_group_membership', $j->matchedRoute);
    }

    #[Test]
    public function retrieveNumberGroupMembershipSuccess(): void
    {
        $body = $this->client->numberGroups()->getMembership('ngm-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::NGM . '/ngm-1', 'relay-rest.retrieve_number_group_membership');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function retrieveNumberGroupMembershipError(): void
    {
        $this->mock->scenarios()->set('relay-rest.retrieve_number_group_membership', 404, ['error' => 'nope']);
        try {
            $this->client->numberGroups()->getMembership('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.retrieve_number_group_membership', $j->matchedRoute);
    }

    #[Test]
    public function deleteNumberGroupMembershipSuccess(): void
    {
        $body = $this->client->numberGroups()->deleteMembership('ngm-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('DELETE', self::NGM . '/ngm-1', 'relay-rest.delete_number_group_membership');
        $this->assertSame('DELETE', $j->method);
    }

    #[Test]
    public function deleteNumberGroupMembershipError(): void
    {
        $this->mock->scenarios()->set('relay-rest.delete_number_group_membership', 404, ['error' => 'nope']);
        try {
            $this->client->numberGroups()->deleteMembership('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.delete_number_group_membership', $j->matchedRoute);
    }

    // =================================================================
    // verified_caller_ids
    // =================================================================

    #[Test]
    public function listVerifiedCallerIdsSuccess(): void
    {
        $body = $this->client->verifiedCallers()->list();
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::VC, 'relay-rest.list_verified_caller_ids');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function listVerifiedCallerIdsError(): void
    {
        $this->mock->scenarios()->set('relay-rest.list_verified_caller_ids', 500, ['error' => 'internal']);
        try {
            $this->client->verifiedCallers()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('relay-rest.list_verified_caller_ids', $j->matchedRoute);
    }

    #[Test]
    public function createVerifiedCallerIdSuccess(): void
    {
        $body = $this->client->verifiedCallers()->create(['phone_number' => '+15551112222']);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('POST', self::VC, 'relay-rest.create_verified_caller_id');
        $this->assertSame('POST', $j->method);
    }

    #[Test]
    public function createVerifiedCallerIdError(): void
    {
        $this->mock->scenarios()->set('relay-rest.create_verified_caller_id', 422, ['error' => 'bad']);
        try {
            $this->client->verifiedCallers()->create([]);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.create_verified_caller_id', $j->matchedRoute);
    }

    #[Test]
    public function retrieveVerifiedCallerIdSuccess(): void
    {
        $body = $this->client->verifiedCallers()->get('vc-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::VC . '/vc-1', 'relay-rest.retrieve_verified_caller_id');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function retrieveVerifiedCallerIdError(): void
    {
        $this->mock->scenarios()->set('relay-rest.retrieve_verified_caller_id', 404, ['error' => 'nope']);
        try {
            $this->client->verifiedCallers()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.retrieve_verified_caller_id', $j->matchedRoute);
    }

    #[Test]
    public function updateVerifiedCallerIdSuccess(): void
    {
        $body = $this->client->verifiedCallers()->update('vc-1', ['name' => 'renamed']);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('PUT', self::VC . '/vc-1', 'relay-rest.update_verified_caller_id');
        $this->assertSame('PUT', $j->method);
    }

    #[Test]
    public function updateVerifiedCallerIdError(): void
    {
        $this->mock->scenarios()->set('relay-rest.update_verified_caller_id', 404, ['error' => 'nope']);
        try {
            $this->client->verifiedCallers()->update('missing', ['name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.update_verified_caller_id', $j->matchedRoute);
    }

    #[Test]
    public function deleteVerifiedCallerIdSuccess(): void
    {
        $body = $this->client->verifiedCallers()->delete('vc-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('DELETE', self::VC . '/vc-1', 'relay-rest.delete_verified_caller_id');
        $this->assertSame('DELETE', $j->method);
    }

    #[Test]
    public function deleteVerifiedCallerIdError(): void
    {
        $this->mock->scenarios()->set('relay-rest.delete_verified_caller_id', 404, ['error' => 'nope']);
        try {
            $this->client->verifiedCallers()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.delete_verified_caller_id', $j->matchedRoute);
    }

    #[Test]
    public function redialVerificationCallSuccess(): void
    {
        $body = $this->client->verifiedCallers()->redialVerification('vc-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('POST', self::VC . '/vc-1/verification', 'relay-rest.redial_verification_call');
        $this->assertSame('POST', $j->method);
    }

    #[Test]
    public function redialVerificationCallError(): void
    {
        $this->mock->scenarios()->set('relay-rest.redial_verification_call', 404, ['error' => 'nope']);
        try {
            $this->client->verifiedCallers()->redialVerification('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.redial_verification_call', $j->matchedRoute);
    }

    #[Test]
    public function validateVerificationCodeSuccess(): void
    {
        $body = $this->client->verifiedCallers()->submitVerification('vc-1', '123456');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('PUT', self::VC . '/vc-1/verification', 'relay-rest.validate_verification_code');
        $this->assertSame('PUT', $j->method);
    }

    #[Test]
    public function validateVerificationCodeError(): void
    {
        $this->mock->scenarios()->set('relay-rest.validate_verification_code', 422, ['error' => 'wrong code']);
        try {
            $this->client->verifiedCallers()->submitVerification('vc-1', '000000');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.validate_verification_code', $j->matchedRoute);
    }

    // =================================================================
    // registry (10DLC) — brands
    // =================================================================

    #[Test]
    public function listBrandsSuccess(): void
    {
        $body = $this->client->registry()->brands()->list();
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::REG . '/brands', 'relay-rest.list_brands');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function listBrandsError(): void
    {
        $this->mock->scenarios()->set('relay-rest.list_brands', 500, ['error' => 'boom']);
        try {
            $this->client->registry()->brands()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('relay-rest.list_brands', $j->matchedRoute);
    }

    #[Test]
    public function createBrandSuccess(): void
    {
        $body = $this->client->registry()->brands()->create(['entity_type' => 'PRIVATE_PROFIT']);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('POST', self::REG . '/brands', 'relay-rest.create_brand');
        $this->assertSame('POST', $j->method);
    }

    #[Test]
    public function createBrandError(): void
    {
        $this->mock->scenarios()->set('relay-rest.create_brand', 422, ['error' => 'bad']);
        try {
            $this->client->registry()->brands()->create([]);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.create_brand', $j->matchedRoute);
    }

    #[Test]
    public function retrieveBrandSuccess(): void
    {
        $body = $this->client->registry()->brands()->get('brand-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::REG . '/brands/brand-1', 'relay-rest.retrieve_brand');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function retrieveBrandError(): void
    {
        $this->mock->scenarios()->set('relay-rest.retrieve_brand', 404, ['error' => 'nope']);
        try {
            $this->client->registry()->brands()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.retrieve_brand', $j->matchedRoute);
    }

    #[Test]
    public function listCampaignsSuccess(): void
    {
        $body = $this->client->registry()->brands()->listCampaigns('brand-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::REG . '/brands/brand-1/campaigns', 'relay-rest.list_campaigns');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function listCampaignsError(): void
    {
        $this->mock->scenarios()->set('relay-rest.list_campaigns', 404, ['error' => 'nope']);
        try {
            $this->client->registry()->brands()->listCampaigns('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.list_campaigns', $j->matchedRoute);
    }

    #[Test]
    public function createCampaignSuccess(): void
    {
        $body = $this->client->registry()->brands()->createCampaign('brand-1', ['usecase' => 'LOW_VOLUME']);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('POST', self::REG . '/brands/brand-1/campaigns', 'relay-rest.create_campaign');
        $this->assertSame('POST', $j->method);
    }

    #[Test]
    public function createCampaignError(): void
    {
        $this->mock->scenarios()->set('relay-rest.create_campaign', 422, ['error' => 'bad']);
        try {
            $this->client->registry()->brands()->createCampaign('brand-1', []);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.create_campaign', $j->matchedRoute);
    }

    // =================================================================
    // registry — campaigns
    // =================================================================

    #[Test]
    public function retrieveCampaignSuccess(): void
    {
        $body = $this->client->registry()->campaigns()->get('camp-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::REG . '/campaigns/camp-1', 'relay-rest.retrieve_campaign');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function retrieveCampaignError(): void
    {
        $this->mock->scenarios()->set('relay-rest.retrieve_campaign', 404, ['error' => 'nope']);
        try {
            $this->client->registry()->campaigns()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.retrieve_campaign', $j->matchedRoute);
    }

    #[Test]
    public function updateCampaignSuccess(): void
    {
        $body = $this->client->registry()->campaigns()->update('camp-1', extras: ['description' => 'x']);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('PUT', self::REG . '/campaigns/camp-1', 'relay-rest.update_campaign');
        $this->assertSame('PUT', $j->method);
    }

    #[Test]
    public function updateCampaignError(): void
    {
        $this->mock->scenarios()->set('relay-rest.update_campaign', 404, ['error' => 'nope']);
        try {
            $this->client->registry()->campaigns()->update('missing', extras: ['description' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.update_campaign', $j->matchedRoute);
    }

    #[Test]
    public function listNumberAssignmentsSuccess(): void
    {
        $body = $this->client->registry()->campaigns()->listNumbers('camp-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::REG . '/campaigns/camp-1/numbers', 'relay-rest.list_number_assignments');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function listNumberAssignmentsError(): void
    {
        $this->mock->scenarios()->set('relay-rest.list_number_assignments', 404, ['error' => 'nope']);
        try {
            $this->client->registry()->campaigns()->listNumbers('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.list_number_assignments', $j->matchedRoute);
    }

    #[Test]
    public function listOrdersSuccess(): void
    {
        $body = $this->client->registry()->campaigns()->listOrders('camp-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::REG . '/campaigns/camp-1/orders', 'relay-rest.list_orders');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function listOrdersError(): void
    {
        $this->mock->scenarios()->set('relay-rest.list_orders', 404, ['error' => 'nope']);
        try {
            $this->client->registry()->campaigns()->listOrders('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.list_orders', $j->matchedRoute);
    }

    #[Test]
    public function createOrderSuccess(): void
    {
        $body = $this->client->registry()->campaigns()->createOrder('camp-1', ['numbers' => ['pn-1']]);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('POST', self::REG . '/campaigns/camp-1/orders', 'relay-rest.create_order');
        $this->assertSame('POST', $j->method);
    }

    #[Test]
    public function createOrderError(): void
    {
        $this->mock->scenarios()->set('relay-rest.create_order', 422, ['error' => 'bad']);
        try {
            $this->client->registry()->campaigns()->createOrder('camp-1', []);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.create_order', $j->matchedRoute);
    }

    // =================================================================
    // registry — orders
    // =================================================================

    #[Test]
    public function retrieveOrderSuccess(): void
    {
        $body = $this->client->registry()->orders()->get('order-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::REG . '/orders/order-1', 'relay-rest.retrieve_order');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function retrieveOrderError(): void
    {
        $this->mock->scenarios()->set('relay-rest.retrieve_order', 404, ['error' => 'nope']);
        try {
            $this->client->registry()->orders()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.retrieve_order', $j->matchedRoute);
    }

    // =================================================================
    // registry — numbers
    // =================================================================

    #[Test]
    public function deleteNumberAssignmentSuccess(): void
    {
        $body = $this->client->registry()->numbers()->delete('num-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('DELETE', self::REG . '/numbers/num-1', 'relay-rest.delete_number_assignment');
        $this->assertSame('DELETE', $j->method);
    }

    #[Test]
    public function deleteNumberAssignmentError(): void
    {
        $this->mock->scenarios()->set('relay-rest.delete_number_assignment', 404, ['error' => 'nope']);
        try {
            $this->client->registry()->numbers()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.delete_number_assignment', $j->matchedRoute);
    }

    // =================================================================
    // mfa
    // =================================================================

    #[Test]
    public function requestMfaCallSuccess(): void
    {
        $body = $this->client->mfa()->call('+15551230000');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('POST', self::MFA . '/call', 'relay-rest.request_mfa_call');
        $this->assertSame('POST', $j->method);
    }

    #[Test]
    public function requestMfaCallError(): void
    {
        $this->mock->scenarios()->set('relay-rest.request_mfa_call', 422, ['error' => 'to required']);
        try {
            $this->client->mfa()->call('+15551230000');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.request_mfa_call', $j->matchedRoute);
    }

    #[Test]
    public function requestMfaSmsSuccess(): void
    {
        $body = $this->client->mfa()->sms('+15551230000');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('POST', self::MFA . '/sms', 'relay-rest.request_mfa_sms');
        $this->assertSame('POST', $j->method);
    }

    #[Test]
    public function requestMfaSmsError(): void
    {
        $this->mock->scenarios()->set('relay-rest.request_mfa_sms', 422, ['error' => 'to required']);
        try {
            $this->client->mfa()->sms('+15551230000');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.request_mfa_sms', $j->matchedRoute);
    }

    #[Test]
    public function verifyMfaTokenSuccess(): void
    {
        $body = $this->client->mfa()->verify('mfa-1', '123456');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('POST', self::MFA . '/mfa-1/verify', 'relay-rest.verify_mfa_token');
        $this->assertSame('POST', $j->method);
    }

    #[Test]
    public function verifyMfaTokenError(): void
    {
        $this->mock->scenarios()->set('relay-rest.verify_mfa_token', 422, ['error' => 'bad token']);
        try {
            $this->client->mfa()->verify('mfa-1', '000000');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.verify_mfa_token', $j->matchedRoute);
    }

    // =================================================================
    // short_codes
    // =================================================================

    #[Test]
    public function listShortCodesSuccess(): void
    {
        $body = $this->client->shortCodes()->list();
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::SC, 'relay-rest.list_short_codes');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function listShortCodesError(): void
    {
        $this->mock->scenarios()->set('relay-rest.list_short_codes', 500, ['error' => 'boom']);
        try {
            $this->client->shortCodes()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('relay-rest.list_short_codes', $j->matchedRoute);
    }

    #[Test]
    public function retrieveShortCodeSuccess(): void
    {
        $body = $this->client->shortCodes()->get('sc-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::SC . '/sc-1', 'relay-rest.retrieve_short_code');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function retrieveShortCodeError(): void
    {
        $this->mock->scenarios()->set('relay-rest.retrieve_short_code', 404, ['error' => 'nope']);
        try {
            $this->client->shortCodes()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.retrieve_short_code', $j->matchedRoute);
    }

    #[Test]
    public function updateShortCodeSuccess(): void
    {
        $body = $this->client->shortCodes()->update('sc-1', 'promo', 'handler', extras: ['friendly_name' => 'promo']);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('PUT', self::SC . '/sc-1', 'relay-rest.update_short_code');
        $this->assertSame('PUT', $j->method);
    }

    #[Test]
    public function updateShortCodeError(): void
    {
        $this->mock->scenarios()->set('relay-rest.update_short_code', 404, ['error' => 'nope']);
        try {
            $this->client->shortCodes()->update('missing', 'promo', 'handler', extras: ['friendly_name' => 'x']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.update_short_code', $j->matchedRoute);
    }

    // =================================================================
    // recordings
    // =================================================================

    #[Test]
    public function listRecordingsSuccess(): void
    {
        $body = $this->client->recordings()->list();
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::REC, 'relay-rest.list_recordings');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function listRecordingsError(): void
    {
        $this->mock->scenarios()->set('relay-rest.list_recordings', 500, ['error' => 'boom']);
        try {
            $this->client->recordings()->list();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('relay-rest.list_recordings', $j->matchedRoute);
    }

    #[Test]
    public function getRecordingSuccess(): void
    {
        $body = $this->client->recordings()->get('rec-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::REC . '/rec-1', 'relay-rest.get_recording');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function getRecordingError(): void
    {
        $this->mock->scenarios()->set('relay-rest.get_recording', 404, ['error' => 'nope']);
        try {
            $this->client->recordings()->get('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.get_recording', $j->matchedRoute);
    }

    #[Test]
    public function deleteRecordingSuccess(): void
    {
        $body = $this->client->recordings()->delete('rec-1');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('DELETE', self::REC . '/rec-1', 'relay-rest.delete_recording');
        $this->assertSame('DELETE', $j->method);
    }

    #[Test]
    public function deleteRecordingError(): void
    {
        $this->mock->scenarios()->set('relay-rest.delete_recording', 404, ['error' => 'nope']);
        try {
            $this->client->recordings()->delete('missing');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.delete_recording', $j->matchedRoute);
    }

    // =================================================================
    // sip_profile (singleton)
    // =================================================================

    #[Test]
    public function retrieveSipProfileSuccess(): void
    {
        $body = $this->client->sipProfile()->get();
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::SIP, 'relay-rest.retrieve_sip_profile');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function retrieveSipProfileError(): void
    {
        $this->mock->scenarios()->set('relay-rest.retrieve_sip_profile', 500, ['error' => 'boom']);
        try {
            $this->client->sipProfile()->get();
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(500, $j->responseStatus);
        $this->assertSame('relay-rest.retrieve_sip_profile', $j->matchedRoute);
    }

    #[Test]
    public function updateSipProfileSuccess(): void
    {
        $body = $this->client->sipProfile()->update(extras: ['domain' => 'acme']);
        $this->assertIsArray($body);
        $j = $this->assertSuccess('PUT', self::SIP, 'relay-rest.update_sip_profile');
        $this->assertSame('PUT', $j->method);
    }

    #[Test]
    public function updateSipProfileError(): void
    {
        $this->mock->scenarios()->set('relay-rest.update_sip_profile', 422, ['error' => 'bad']);
        try {
            $this->client->sipProfile()->update(extras: ['domain' => '']);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.update_sip_profile', $j->matchedRoute);
    }

    // =================================================================
    // lookup
    // =================================================================

    #[Test]
    public function lookupPhoneNumberSuccess(): void
    {
        $body = $this->client->lookup()->phoneNumber('+15551230000');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('GET', self::LOOKUP . '/+15551230000', 'relay-rest.lookup_phone_number');
        $this->assertSame('GET', $j->method);
    }

    #[Test]
    public function lookupPhoneNumberError(): void
    {
        $this->mock->scenarios()->set('relay-rest.lookup_phone_number', 404, ['error' => 'nope']);
        try {
            $this->client->lookup()->phoneNumber('+19999999999');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(404, $j->responseStatus);
        $this->assertSame('relay-rest.lookup_phone_number', $j->matchedRoute);
    }

    // =================================================================
    // imported_phone_numbers
    // =================================================================

    #[Test]
    public function createImportedPhoneNumberSuccess(): void
    {
        $body = $this->client->importedNumbers()->create('+15551230000', 'longcode');
        $this->assertIsArray($body);
        $j = $this->assertSuccess('POST', self::IMPORTED, 'relay-rest.create_imported_phone_number');
        $this->assertSame('POST', $j->method);
    }

    #[Test]
    public function createImportedPhoneNumberError(): void
    {
        $this->mock->scenarios()->set('relay-rest.create_imported_phone_number', 422, ['error' => 'bad']);
        try {
            $this->client->importedNumbers()->create('+15551230000', 'longcode');
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $j = $this->mock->journal()->last();
        $this->assertSame(422, $j->responseStatus);
        $this->assertSame('relay-rest.create_imported_phone_number', $j->matchedRoute);
    }
}
