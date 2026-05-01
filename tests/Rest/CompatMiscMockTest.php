<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_compat_misc.py.
 *
 * Covers single-method gaps:
 *   - CompatApplications.update
 *   - CompatLamlBins.update
 */
class CompatMiscMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = new RestClient('test_proj', 'test_tok', $this->mock->url());
    }

    // ----- CompatApplications.update -----------------------------------

    #[Test]
    public function applicationsUpdateReturnsApplicationResource(): void
    {
        $result = $this->client->compat()->applications()->update(
            'AP_U',
            ['FriendlyName' => 'updated']
        );
        $this->assertIsArray($result);
        // Application resources carry friendly_name + sid + voice_url.
        $this->assertTrue(
            array_key_exists('friendly_name', $result) || array_key_exists('sid', $result)
        );
    }

    #[Test]
    public function applicationsUpdateJournalRecordsPostWithFriendlyName(): void
    {
        $this->client->compat()->applications()->update(
            'AP_UU',
            ['FriendlyName' => 'renamed', 'VoiceUrl' => 'https://a.b/v']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Applications/AP_UU',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('renamed', $body['FriendlyName'] ?? null);
        $this->assertSame('https://a.b/v', $body['VoiceUrl'] ?? null);
    }

    // ----- CompatLamlBins.update ---------------------------------------

    #[Test]
    public function lamlBinsUpdateReturnsLamlBinResource(): void
    {
        $result = $this->client->compat()->lamlBins()->update(
            'LB_U',
            ['FriendlyName' => 'updated']
        );
        $this->assertIsArray($result);
        // LAML bin resources carry friendly_name + sid + contents.
        $this->assertTrue(
            array_key_exists('friendly_name', $result)
            || array_key_exists('sid', $result)
            || array_key_exists('contents', $result)
        );
    }

    #[Test]
    public function lamlBinsUpdateJournalRecordsPostWithFriendlyName(): void
    {
        $this->client->compat()->lamlBins()->update(
            'LB_UU',
            ['FriendlyName' => 'renamed', 'Contents' => '<Response/>']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/LamlBins/LB_UU',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('renamed', $body['FriendlyName'] ?? null);
        $this->assertSame('<Response/>', $body['Contents'] ?? null);
    }
}
