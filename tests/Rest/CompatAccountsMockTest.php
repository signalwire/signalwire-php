<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_compat_accounts.py.
 *
 * Drives ``client.compat.accounts.*`` against the live mock server.  Each
 * test asserts on the SDK return value AND the recorded request journal so
 * neither half is allowed to drift.
 */
class CompatAccountsMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = new RestClient('test_proj', 'test_tok', $this->mock->url());
    }

    // ----- create --------------------------------------------------------

    #[Test]
    public function createReturnsAccountResource(): void
    {
        $result = $this->client->compat()->accounts()->create(['FriendlyName' => 'Sub-A']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('friendly_name', $result);
    }

    #[Test]
    public function createJournalRecordsPostToAccounts(): void
    {
        $this->client->compat()->accounts()->create(['FriendlyName' => 'Sub-B']);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        // Accounts.create lives at the top-level Accounts collection — no
        // AccountSid prefix.
        $this->assertSame('/api/laml/2010-04-01/Accounts', $j->path);
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('Sub-B', $body['FriendlyName'] ?? null);
        $status = (int) ($j->responseStatus ?? 0);
        $this->assertGreaterThanOrEqual(200, $status);
        $this->assertLessThan(400, $status);
    }

    // ----- get -----------------------------------------------------------

    #[Test]
    public function getReturnsAccountForSid(): void
    {
        $result = $this->client->compat()->accounts()->get('AC123');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('friendly_name', $result);
    }

    #[Test]
    public function getJournalRecordsGetWithSid(): void
    {
        $this->client->compat()->accounts()->get('AC_SAMPLE_SID');
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/laml/2010-04-01/Accounts/AC_SAMPLE_SID', $j->path);
        // GET should not carry a request body.
        $this->assertTrue($j->body === null || $j->body === '' || $j->body === []);
        $this->assertNotNull($j->matchedRoute, 'spec gap: account-get should match a route');
    }

    // ----- update --------------------------------------------------------

    #[Test]
    public function updateReturnsUpdatedAccount(): void
    {
        $result = $this->client->compat()->accounts()->update('AC123', ['FriendlyName' => 'Renamed']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('friendly_name', $result);
    }

    #[Test]
    public function updateJournalRecordsPostToAccountPath(): void
    {
        $this->client->compat()->accounts()->update('AC_X', ['FriendlyName' => 'NewName']);
        $j = $this->mock->journal()->last();
        // Twilio-compat update is POST (not PATCH/PUT).
        $this->assertSame('POST', $j->method);
        $this->assertSame('/api/laml/2010-04-01/Accounts/AC_X', $j->path);
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('NewName', $body['FriendlyName'] ?? null);
    }
}
