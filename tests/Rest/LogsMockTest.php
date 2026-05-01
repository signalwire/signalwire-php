<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_logs_mock.py.
 *
 * Covers ``client.logs.{messages,voice,fax,conferences}`` — message,
 * voice, fax, and conference logs (read-only).
 */
class LogsMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = new RestClient('test_proj', 'test_tok', $this->mock->url());
    }

    // ----- message logs -----------------------------------------------

    #[Test]
    public function messagesListReturnsArray(): void
    {
        $body = $this->client->logs()->messages()->list();
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/messaging/logs', $j->path);
        $this->assertSame('message.list_message_logs', $j->matchedRoute);
    }

    #[Test]
    public function messagesGetUsesIdInPath(): void
    {
        $body = $this->client->logs()->messages()->get('ml-42');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/messaging/logs/ml-42', $j->path);
        $this->assertNotNull($j->matchedRoute, 'spec gap: message log retrieve');
    }

    // ----- voice logs -------------------------------------------------

    #[Test]
    public function voiceListReturnsArray(): void
    {
        $body = $this->client->logs()->voice()->list();
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/voice/logs', $j->path);
        $this->assertSame('voice.list_voice_logs', $j->matchedRoute);
    }

    #[Test]
    public function voiceGetUsesIdInPath(): void
    {
        $body = $this->client->logs()->voice()->get('vl-99');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/voice/logs/vl-99', $j->path);
    }

    // ----- fax logs ---------------------------------------------------

    #[Test]
    public function faxListReturnsArray(): void
    {
        $body = $this->client->logs()->fax()->list();
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/fax/logs', $j->path);
        $this->assertSame('fax.list_fax_logs', $j->matchedRoute);
    }

    #[Test]
    public function faxGetUsesIdInPath(): void
    {
        $body = $this->client->logs()->fax()->get('fl-7');
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/fax/logs/fl-7', $j->path);
    }

    // ----- conference logs --------------------------------------------

    #[Test]
    public function conferencesListReturnsArray(): void
    {
        $body = $this->client->logs()->conferences()->list();
        $this->assertIsArray($body);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/logs/conferences', $j->path);
        $this->assertSame('logs.list_conferences', $j->matchedRoute);
    }
}
