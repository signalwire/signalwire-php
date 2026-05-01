<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_small_namespaces_mock.py.
 *
 * Coverage for small REST namespaces:
 *   - addresses (list / get / create / delete)
 *   - recordings (list / get / delete)
 *   - short_codes (list / get / update)
 *   - imported_numbers (create)
 *   - mfa (call)
 *   - sip_profile (update)
 *   - number_groups (listMemberships / deleteMembership)
 *   - project.tokens (update / delete)
 *   - datasphere.documents (getChunk)
 *   - queues (getMember)
 */
class SmallNamespacesMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = new RestClient('test_proj', 'test_tok', $this->mock->url());
    }

    // ----- addresses --------------------------------------------------

    #[Test]
    public function addressesList(): void
    {
        $body = $this->client->addresses()->list(['page_size' => '10']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/relay/rest/addresses', $j->path);
        $this->assertNotNull($j->matchedRoute);
        $this->assertSame(['10'], $j->queryParams['page_size'] ?? null);
    }

    #[Test]
    public function addressesCreate(): void
    {
        $body = $this->client->addresses()->create([
            'address_type' => 'commercial',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'country' => 'US',
        ]);
        $this->assertIsArray($body);
        // An Address resource carries an 'id' field.
        $this->assertArrayHasKey('id', $body);

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/relay/rest/addresses', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame('commercial', $bm['address_type'] ?? null);
        $this->assertSame('Ada', $bm['first_name'] ?? null);
        $this->assertSame('US', $bm['country'] ?? null);
    }

    #[Test]
    public function addressesGet(): void
    {
        $body = $this->client->addresses()->get('addr-123');
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/relay/rest/addresses/addr-123', $j->path);
        $this->assertNotNull($j->matchedRoute);
    }

    #[Test]
    public function addressesDelete(): void
    {
        $body = $this->client->addresses()->delete('addr-123');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame('/api/relay/rest/addresses/addr-123', $j->path);
        $this->assertContains((int) ($j->responseStatus ?? 0), [200, 202, 204]);
    }

    // ----- recordings -------------------------------------------------

    #[Test]
    public function recordingsList(): void
    {
        $body = $this->client->recordings()->list(['page_size' => '5']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/relay/rest/recordings', $j->path);
        $this->assertSame(['5'], $j->queryParams['page_size'] ?? null);
    }

    #[Test]
    public function recordingsGet(): void
    {
        $body = $this->client->recordings()->get('rec-123');
        $this->assertIsArray($body);
        // The Recording schema has an 'id' field.
        $this->assertArrayHasKey('id', $body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/relay/rest/recordings/rec-123', $j->path);
    }

    #[Test]
    public function recordingsDelete(): void
    {
        $body = $this->client->recordings()->delete('rec-123');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame('/api/relay/rest/recordings/rec-123', $j->path);
        $this->assertContains((int) ($j->responseStatus ?? 0), [200, 202, 204]);
    }

    // ----- short_codes ------------------------------------------------

    #[Test]
    public function shortCodesList(): void
    {
        $body = $this->client->shortCodes()->list(['page_size' => '20']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/relay/rest/short_codes', $j->path);
    }

    #[Test]
    public function shortCodesGet(): void
    {
        $body = $this->client->shortCodes()->get('sc-1');
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/relay/rest/short_codes/sc-1', $j->path);
    }

    #[Test]
    public function shortCodesUpdate(): void
    {
        $body = $this->client->shortCodes()->update('sc-1', ['name' => 'Marketing SMS']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);

        $j = $this->mock->journal()->last();
        // ShortCodes.update uses PUT.
        $this->assertSame('PUT', $j->method);
        $this->assertSame('/api/relay/rest/short_codes/sc-1', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame('Marketing SMS', $bm['name'] ?? null);
    }

    // ----- imported_numbers ------------------------------------------

    #[Test]
    public function importedNumbersCreate(): void
    {
        $body = $this->client->importedNumbers()->create([
            'number' => '+15551234567',
            'sip_username' => 'alice',
            'sip_password' => 'secret',
            'sip_proxy' => 'sip.example.com',
        ]);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/relay/rest/imported_phone_numbers', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame('+15551234567', $bm['number'] ?? null);
        $this->assertSame('alice', $bm['sip_username'] ?? null);
        $this->assertSame('sip.example.com', $bm['sip_proxy'] ?? null);
    }

    // ----- mfa --------------------------------------------------------

    #[Test]
    public function mfaCall(): void
    {
        $body = $this->client->mfa()->call([
            'to' => '+15551234567',
            'from_' => '+15559876543',
            'message' => 'Your code is {code}',
        ]);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/relay/rest/mfa/call', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame('+15551234567', $bm['to'] ?? null);
        $this->assertSame('+15559876543', $bm['from_'] ?? null);
        $this->assertSame('Your code is {code}', $bm['message'] ?? null);
    }

    // ----- sip_profile -----------------------------------------------

    #[Test]
    public function sipProfileUpdate(): void
    {
        $body = $this->client->sipProfile()->update([
            'domain' => 'myco.sip.signalwire.com',
            'default_codecs' => ['PCMU', 'PCMA'],
        ]);
        $this->assertIsArray($body);
        // The SIP profile resource has a 'domain' field.
        $this->assertTrue(
            array_key_exists('domain', $body) || array_key_exists('default_codecs', $body)
        );

        $j = $this->mock->journal()->last();
        $this->assertSame('PUT', $j->method);
        $this->assertSame('/api/relay/rest/sip_profile', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame('myco.sip.signalwire.com', $bm['domain'] ?? null);
        $this->assertSame(['PCMU', 'PCMA'], $bm['default_codecs'] ?? null);
    }

    // ----- number_groups ----------------------------------------------

    #[Test]
    public function numberGroupsListMemberships(): void
    {
        $body = $this->client->numberGroups()->listMemberships('ng-1', ['page_size' => '10']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/relay/rest/number_groups/ng-1/number_group_memberships',
            $j->path
        );
        $this->assertSame(['10'], $j->queryParams['page_size'] ?? null);
    }

    #[Test]
    public function numberGroupsDeleteMembership(): void
    {
        $body = $this->client->numberGroups()->deleteMembership('mem-1');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame('/api/relay/rest/number_group_memberships/mem-1', $j->path);
        $this->assertContains((int) ($j->responseStatus ?? 0), [200, 202, 204]);
    }

    // ----- project.tokens --------------------------------------------

    #[Test]
    public function projectTokensUpdate(): void
    {
        $body = $this->client->project()->tokens()->update('tok-1', ['name' => 'renamed-token']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);

        $j = $this->mock->journal()->last();
        $this->assertSame('PATCH', $j->method);
        $this->assertSame('/api/project/tokens/tok-1', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame('renamed-token', $bm['name'] ?? null);
    }

    #[Test]
    public function projectTokensDelete(): void
    {
        $body = $this->client->project()->tokens()->delete('tok-1');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame('/api/project/tokens/tok-1', $j->path);
        $this->assertContains((int) ($j->responseStatus ?? 0), [200, 202, 204]);
    }

    // ----- datasphere.documents --------------------------------------

    #[Test]
    public function datasphereGetChunk(): void
    {
        $body = $this->client->datasphere()->documents()->getChunk('doc-1', 'chunk-99');
        $this->assertIsArray($body);
        // The DatasphereChunk schema has an 'id'.
        $this->assertArrayHasKey('id', $body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/datasphere/documents/doc-1/chunks/chunk-99', $j->path);
    }

    // ----- queues -----------------------------------------------------

    #[Test]
    public function queuesGetMember(): void
    {
        $body = $this->client->queues()->getMember('q-1', 'mem-7');
        $this->assertIsArray($body);
        // A queue member has 'queue_id' and 'call_id' per the spec example.
        $this->assertTrue(
            array_key_exists('queue_id', $body) || array_key_exists('call_id', $body)
        );

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/relay/rest/queues/q-1/members/mem-7', $j->path);
    }
}
