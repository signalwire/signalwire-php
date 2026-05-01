<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_compat_queues.py.
 *
 * Covers ``CompatQueues.update``, ``listMembers``, ``getMember``, and
 * ``dequeueMember``.
 */
class CompatQueuesMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = new RestClient('test_proj', 'test_tok', $this->mock->url());
    }

    // ----- update --------------------------------------------------------

    #[Test]
    public function updateReturnsQueueResource(): void
    {
        $result = $this->client->compat()->queues()->update(
            'QU_U',
            ['FriendlyName' => 'updated']
        );
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('friendly_name', $result) || array_key_exists('sid', $result)
        );
    }

    #[Test]
    public function updateJournalRecordsPostWithFriendlyName(): void
    {
        $this->client->compat()->queues()->update(
            'QU_UU',
            ['FriendlyName' => 'renamed', 'MaxSize' => 200]
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Queues/QU_UU',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('renamed', $body['FriendlyName'] ?? null);
        $this->assertSame(200, $body['MaxSize'] ?? null);
    }

    // ----- listMembers ---------------------------------------------------

    #[Test]
    public function listMembersReturnsPaginatedMembers(): void
    {
        $result = $this->client->compat()->queues()->listMembers('QU_LM');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('queue_members', $result);
        $this->assertIsArray($result['queue_members']);
    }

    #[Test]
    public function listMembersJournalRecordsGet(): void
    {
        $this->client->compat()->queues()->listMembers('QU_LMX');
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Queues/QU_LMX/Members',
            $j->path
        );
    }

    // ----- getMember -----------------------------------------------------

    #[Test]
    public function getMemberReturnsMemberResource(): void
    {
        $result = $this->client->compat()->queues()->getMember('QU_GM', 'CA_GM');
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('call_sid', $result) || array_key_exists('queue_sid', $result)
        );
    }

    #[Test]
    public function getMemberJournalRecordsGetToSpecificMember(): void
    {
        $this->client->compat()->queues()->getMember('QU_GMX', 'CA_GMX');
        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Queues/QU_GMX/Members/CA_GMX',
            $j->path
        );
    }

    // ----- dequeueMember -------------------------------------------------

    #[Test]
    public function dequeueMemberReturnsMemberResource(): void
    {
        $result = $this->client->compat()->queues()->dequeueMember(
            'QU_DM',
            'CA_DM',
            ['Url' => 'https://a.b']
        );
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('call_sid', $result) || array_key_exists('queue_sid', $result)
        );
    }

    #[Test]
    public function dequeueMemberJournalRecordsPostWithUrl(): void
    {
        $this->client->compat()->queues()->dequeueMember(
            'QU_DMX',
            'CA_DMX',
            ['Url' => 'https://a.b/url', 'Method' => 'POST']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Queues/QU_DMX/Members/CA_DMX',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('https://a.b/url', $body['Url'] ?? null);
        $this->assertSame('POST', $body['Method'] ?? null);
    }
}
