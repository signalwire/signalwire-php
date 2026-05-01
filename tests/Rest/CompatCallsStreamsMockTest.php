<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_compat_calls_streams.py.
 *
 * Each PHP test mirrors one Python test, calling the real SDK method and
 * asserting on both the response body shape and the wire request the mock
 * server journaled.
 */
class CompatCallsStreamsMockTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = new RestClient('test_proj', 'test_tok', $this->mock->url());
    }

    // ----------------------------------------------------------------
    // CompatCalls.startStream  -> POST /Calls/{sid}/Streams
    // ----------------------------------------------------------------

    #[Test]
    public function startStreamReturnsStreamResource(): void
    {
        $result = $this->client->compat()->calls()->startStream(
            'CA_TEST',
            ['Url' => 'wss://example.com/stream', 'Name' => 'my-stream']
        );
        $this->assertIsArray($result);
        // Stream resources carry a 'sid' or 'name' identifier.
        $this->assertTrue(
            array_key_exists('sid', $result) || array_key_exists('name', $result),
            'expected stream sid/name in body, got keys ' . implode(',', array_keys($result))
        );
    }

    #[Test]
    public function startStreamJournalRecordsPostToStreamsCollection(): void
    {
        $this->client->compat()->calls()->startStream(
            'CA_JX1',
            ['Url' => 'wss://a.b/s', 'Name' => 'strm-x']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Calls/CA_JX1/Streams',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body, 'expected JSON body, got ' . var_export($j->body, true));
        $this->assertSame('wss://a.b/s', $body['Url']);
        $this->assertSame('strm-x', $body['Name']);
    }

    // ----------------------------------------------------------------
    // CompatCalls.stopStream  -> POST .../Streams/{stream_sid}
    // ----------------------------------------------------------------

    #[Test]
    public function stopStreamReturnsStreamResourceWithStatus(): void
    {
        $result = $this->client->compat()->calls()->stopStream(
            'CA_T1',
            'ST_T1',
            ['Status' => 'stopped']
        );
        $this->assertIsArray($result);
        // The stop endpoint synthesises a stream resource (sid + status).
        $this->assertTrue(
            array_key_exists('sid', $result) || array_key_exists('status', $result)
        );
    }

    #[Test]
    public function stopStreamJournalRecordsPostToSpecificStream(): void
    {
        $this->client->compat()->calls()->stopStream(
            'CA_S1',
            'ST_S1',
            ['Status' => 'stopped']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Calls/CA_S1/Streams/ST_S1',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('stopped', $body['Status']);
    }

    // ----------------------------------------------------------------
    // CompatCalls.updateRecording  -> POST .../Recordings/{rec_sid}
    // ----------------------------------------------------------------

    #[Test]
    public function updateRecordingReturnsRecordingResource(): void
    {
        $result = $this->client->compat()->calls()->updateRecording(
            'CA_T2',
            'RE_T2',
            ['Status' => 'paused']
        );
        $this->assertIsArray($result);
        $this->assertTrue(
            array_key_exists('sid', $result) || array_key_exists('status', $result)
        );
    }

    #[Test]
    public function updateRecordingJournalRecordsPostToSpecificRecording(): void
    {
        $this->client->compat()->calls()->updateRecording(
            'CA_R1',
            'RE_R1',
            ['Status' => 'paused']
        );
        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/test_proj/Calls/CA_R1/Recordings/RE_R1',
            $j->path
        );
        $body = $j->bodyMap();
        $this->assertNotNull($body);
        $this->assertSame('paused', $body['Status']);
    }
}
