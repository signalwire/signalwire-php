<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_calling_mock.py.
 *
 * Every command in ``signalwire.rest.namespaces.calling.CallingNamespace``
 * is exercised against the local mock server. Each test calls the SDK,
 * asserts on the response shape, and then asserts on the journal entry
 * the mock recorded — method, path, command, id, and params.
 */
class CallingMockTest extends TestCase
{
    private const CALLS_PATH = '/api/calling/calls';

    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = new RestClient('test_proj', 'test_tok', $this->mock->url());
    }

    /**
     * Asserts the journal entry shape — method/path/command — and returns
     * the params map for caller-specific assertions. ``$expectedId`` may be
     * null to require no ``id`` field at the body root (true for dial /
     * update, which carry id inside params).
     *
     * @return array<string,mixed>
     */
    private function commandAssert(JournalEntry $j, string $command, ?string $expectedId): array
    {
        $this->assertSame('POST', $j->method, 'method');
        $this->assertSame(self::CALLS_PATH, $j->path, 'path');
        $body = $j->bodyMap();
        $this->assertNotNull($body, 'expected JSON body');
        $this->assertSame($command, $body['command'] ?? null, 'command');
        if ($expectedId === null) {
            $this->assertArrayNotHasKey('id', $body, 'expected no id at body root');
        } else {
            $this->assertSame($expectedId, $body['id'] ?? null, 'id');
        }
        $params = $body['params'] ?? null;
        $this->assertIsArray($params, 'expected params array');
        return $params;
    }

    // ----- Lifecycle ---------------------------------------------------

    #[Test]
    public function update(): void
    {
        $body = $this->client->calling()->update(['id' => 'call-1', 'state' => 'hold']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'update', null);
        $this->assertSame('call-1', $p['id'] ?? null);
        $this->assertSame('hold', $p['state'] ?? null);
    }

    #[Test]
    public function transfer(): void
    {
        $body = $this->client->calling()->transfer('call-123', [
            'destination' => '+15551234567',
            'from_number' => '+15559876543',
        ]);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.transfer', 'call-123');
        $this->assertSame('+15551234567', $p['destination'] ?? null);
        $this->assertSame('+15559876543', $p['from_number'] ?? null);
    }

    #[Test]
    public function disconnect(): void
    {
        $body = $this->client->calling()->disconnect('call-456', ['reason' => 'busy']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.disconnect', 'call-456');
        $this->assertSame('busy', $p['reason'] ?? null);
    }

    // ----- Play --------------------------------------------------------

    #[Test]
    public function playPause(): void
    {
        $body = $this->client->calling()->playPause('call-1', ['control_id' => 'ctrl-1']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.play.pause', 'call-1');
        $this->assertSame('ctrl-1', $p['control_id'] ?? null);
    }

    #[Test]
    public function playResume(): void
    {
        $body = $this->client->calling()->playResume('call-1', ['control_id' => 'ctrl-1']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.play.resume', 'call-1');
        $this->assertSame('ctrl-1', $p['control_id'] ?? null);
    }

    #[Test]
    public function playStop(): void
    {
        $body = $this->client->calling()->playStop('call-1', ['control_id' => 'ctrl-1']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.play.stop', 'call-1');
        $this->assertSame('ctrl-1', $p['control_id'] ?? null);
    }

    #[Test]
    public function playVolume(): void
    {
        $body = $this->client->calling()->playVolume(
            'call-1',
            ['control_id' => 'ctrl-1', 'volume' => 2.5]
        );
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.play.volume', 'call-1');
        $this->assertSame(2.5, $p['volume'] ?? null);
    }

    // ----- Record ------------------------------------------------------

    #[Test]
    public function record(): void
    {
        $body = $this->client->calling()->record('call-1', ['record' => ['format' => 'mp3']]);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.record', 'call-1');
        $this->assertSame(['format' => 'mp3'], $p['record'] ?? null);
    }

    #[Test]
    public function recordPause(): void
    {
        $body = $this->client->calling()->recordPause('call-1', ['control_id' => 'rec-1']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.record.pause', 'call-1');
        $this->assertSame('rec-1', $p['control_id'] ?? null);
    }

    #[Test]
    public function recordResume(): void
    {
        $body = $this->client->calling()->recordResume('call-1', ['control_id' => 'rec-1']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.record.resume', 'call-1');
        $this->assertSame('rec-1', $p['control_id'] ?? null);
    }

    // ----- Collect -----------------------------------------------------

    #[Test]
    public function collect(): void
    {
        $body = $this->client->calling()->collect(
            'call-1',
            ['initial_timeout' => 5, 'digits' => ['max' => 4]]
        );
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.collect', 'call-1');
        $this->assertSame(5, $p['initial_timeout'] ?? null);
    }

    #[Test]
    public function collectStop(): void
    {
        $body = $this->client->calling()->collectStop('call-1', ['control_id' => 'col-1']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.collect.stop', 'call-1');
        $this->assertSame('col-1', $p['control_id'] ?? null);
    }

    #[Test]
    public function collectStartInputTimers(): void
    {
        $body = $this->client->calling()->collectStartInputTimers(
            'call-1',
            ['control_id' => 'col-1']
        );
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert(
            $this->mock->journal()->last(),
            'calling.collect.start_input_timers',
            'call-1'
        );
        $this->assertSame('col-1', $p['control_id'] ?? null);
    }

    // ----- Detect ------------------------------------------------------

    #[Test]
    public function detect(): void
    {
        $body = $this->client->calling()->detect(
            'call-1',
            ['detect' => ['type' => 'machine', 'params' => []]]
        );
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.detect', 'call-1');
        $this->assertSame('machine', $p['detect']['type'] ?? null);
    }

    #[Test]
    public function detectStop(): void
    {
        $body = $this->client->calling()->detectStop('call-1', ['control_id' => 'det-1']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.detect.stop', 'call-1');
        $this->assertSame('det-1', $p['control_id'] ?? null);
    }

    // ----- Tap ---------------------------------------------------------

    #[Test]
    public function tap(): void
    {
        $body = $this->client->calling()->tap(
            'call-1',
            ['tap' => ['type' => 'audio'], 'device' => ['type' => 'rtp']]
        );
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.tap', 'call-1');
        $this->assertSame(['type' => 'audio'], $p['tap'] ?? null);
    }

    #[Test]
    public function tapStop(): void
    {
        $body = $this->client->calling()->tapStop('call-1', ['control_id' => 'tap-1']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.tap.stop', 'call-1');
        $this->assertSame('tap-1', $p['control_id'] ?? null);
    }

    // ----- Stream ------------------------------------------------------

    #[Test]
    public function stream(): void
    {
        $body = $this->client->calling()->stream('call-1', ['url' => 'wss://example.com/audio']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.stream', 'call-1');
        $this->assertSame('wss://example.com/audio', $p['url'] ?? null);
    }

    #[Test]
    public function streamStop(): void
    {
        $body = $this->client->calling()->streamStop('call-1', ['control_id' => 'stream-1']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.stream.stop', 'call-1');
        $this->assertSame('stream-1', $p['control_id'] ?? null);
    }

    // ----- Denoise -----------------------------------------------------

    #[Test]
    public function denoise(): void
    {
        $body = $this->client->calling()->denoise('call-1');
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $this->commandAssert($this->mock->journal()->last(), 'calling.denoise', 'call-1');
    }

    #[Test]
    public function denoiseStop(): void
    {
        $body = $this->client->calling()->denoiseStop('call-1', ['control_id' => 'dn-1']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.denoise.stop', 'call-1');
        $this->assertSame('dn-1', $p['control_id'] ?? null);
    }

    // ----- Transcribe --------------------------------------------------

    #[Test]
    public function transcribe(): void
    {
        $body = $this->client->calling()->transcribe(
            'call-1',
            ['language' => 'en-US', 'transcribe' => ['engine' => 'google']]
        );
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.transcribe', 'call-1');
        $this->assertSame('en-US', $p['language'] ?? null);
    }

    #[Test]
    public function transcribeStop(): void
    {
        $body = $this->client->calling()->transcribeStop('call-1', ['control_id' => 'tr-1']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert(
            $this->mock->journal()->last(),
            'calling.transcribe.stop',
            'call-1'
        );
        $this->assertSame('tr-1', $p['control_id'] ?? null);
    }

    // ----- AI ----------------------------------------------------------

    #[Test]
    public function aiHold(): void
    {
        $body = $this->client->calling()->aiHold('call-1');
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $this->commandAssert($this->mock->journal()->last(), 'calling.ai_hold', 'call-1');
    }

    #[Test]
    public function aiUnhold(): void
    {
        $body = $this->client->calling()->aiUnhold('call-1');
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $this->commandAssert($this->mock->journal()->last(), 'calling.ai_unhold', 'call-1');
    }

    #[Test]
    public function aiStop(): void
    {
        $body = $this->client->calling()->aiStop('call-1');
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $this->commandAssert($this->mock->journal()->last(), 'calling.ai.stop', 'call-1');
    }

    // ----- Live transcribe / translate --------------------------------

    #[Test]
    public function liveTranscribe(): void
    {
        $body = $this->client->calling()->liveTranscribe('call-1', ['language' => 'en-US']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert(
            $this->mock->journal()->last(),
            'calling.live_transcribe',
            'call-1'
        );
        $this->assertSame('en-US', $p['language'] ?? null);
    }

    #[Test]
    public function liveTranslate(): void
    {
        $body = $this->client->calling()->liveTranslate(
            'call-1',
            ['source_language' => 'en', 'target_language' => 'es']
        );
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert(
            $this->mock->journal()->last(),
            'calling.live_translate',
            'call-1'
        );
        $this->assertSame('en', $p['source_language'] ?? null);
        $this->assertSame('es', $p['target_language'] ?? null);
    }

    // ----- Fax ---------------------------------------------------------

    #[Test]
    public function sendFaxStop(): void
    {
        $body = $this->client->calling()->sendFaxStop('call-1');
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $this->commandAssert($this->mock->journal()->last(), 'calling.send_fax.stop', 'call-1');
    }

    #[Test]
    public function receiveFaxStop(): void
    {
        $body = $this->client->calling()->receiveFaxStop('call-1');
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $this->commandAssert($this->mock->journal()->last(), 'calling.receive_fax.stop', 'call-1');
    }

    // ----- Misc (refer + user_event) ----------------------------------

    #[Test]
    public function refer(): void
    {
        $body = $this->client->calling()->refer('call-1', ['to' => 'sip:other@example.com']);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert($this->mock->journal()->last(), 'calling.refer', 'call-1');
        $this->assertSame('sip:other@example.com', $p['to'] ?? null);
    }

    #[Test]
    public function userEvent(): void
    {
        $body = $this->client->calling()->userEvent(
            'call-1',
            ['event_name' => 'my-event', 'payload' => ['foo' => 'bar']]
        );
        $this->assertIsArray($body);
        $this->assertArrayHasKey('id', $body);
        $p = $this->commandAssert(
            $this->mock->journal()->last(),
            'calling.user_event',
            'call-1'
        );
        $this->assertSame('my-event', $p['event_name'] ?? null);
        $this->assertSame(['foo' => 'bar'], $p['payload'] ?? null);
    }
}
