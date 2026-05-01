<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_compat_tokens.py.
 *
 * Covers ``CompatTokens.create / .update / .delete``.  Note: ``CompatTokens``
 * extends ``BaseResource`` (not ``CrudResource``), so ``update`` uses PATCH
 * rather than POST.
 */
class CompatTokensMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = new RestClient('test_proj', 'test_tok', $this->mock->url());
    }

    // ----- create -------------------------------------------------------

    #[Test]
    public function createReturnsTokenResource(): void
    {
        $result = $this->client->compat()->tokens()->create(['Ttl' => 3600]);
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('token', $result) || array_key_exists('id', $result)
        );
    }

    #[Test]
    public function createJournalRecordsPostWithTtl(): void
    {
        $this->client->compat()->tokens()->create(['Ttl' => 3600, 'Name' => 'api-key']);
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/tokens',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame(3600, $body['Ttl'] ?? null);
        $this->assertSame('api-key', $body['Name'] ?? null);
    }

    // ----- update (PATCH) ----------------------------------------------

    #[Test]
    public function updateReturnsTokenResource(): void
    {
        $result = $this->client->compat()->tokens()->update('TK_U', ['Ttl' => 7200]);
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('token', $result) || array_key_exists('id', $result)
        );
    }

    #[Test]
    public function updateJournalRecordsPatchWithTtl(): void
    {
        $this->client->compat()->tokens()->update('TK_UU', ['Ttl' => 7200]);
        $j = $this->mock->journal()->last();
        // CompatTokens.update uses PATCH (BaseResource.update -> http.patch).
        $this->assertSame('PATCH', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/tokens/TK_UU',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame(7200, $body['Ttl'] ?? null);
    }

    // ----- delete -------------------------------------------------------

    #[Test]
    public function deleteReturnsArray(): void
    {
        $result = $this->client->compat()->tokens()->delete('TK_D');
        $this->assertIsArray($result);
    }

    #[Test]
    public function deleteJournalRecordsDelete(): void
    {
        $this->client->compat()->tokens()->delete('TK_DEL');
        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/tokens/TK_DEL',
            $j->path
        );
    }
}
