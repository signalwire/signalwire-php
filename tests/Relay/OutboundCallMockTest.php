<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\Relay\Call;
use SignalWire\Relay\Client as RelayClient;
use SignalWire\Relay\RelayError;

/**
 * Real-mock-backed tests for outbound calls (``Client::dial``).
 *
 * Ported from signalwire-python/tests/unit/relay/test_outbound_call_mock.py.
 * The dial flow is the most fragile RELAY surface: ``calling.dial`` returns
 * a plain 200 with NO call_id; the actual call info arrives via subsequent
 * ``calling.call.state`` (per leg) and ``calling.call.dial`` (with the
 * winner) events keyed by ``tag``.
 */
class OutboundCallMockTest extends TestCase
{
    private RelayHarness $mock;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = MockTest::client();
    }

    protected function tearDown(): void
    {
        try {
            $this->client->disconnect();
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function phoneDevice(string $to = '+15551112222', string $frm = '+15553334444'): array
    {
        return ['type' => 'phone', 'params' => ['to_number' => $to, 'from_number' => $frm]];
    }

    // ------------------------------------------------------------------
    // Happy-path dial
    // ------------------------------------------------------------------

    #[Test]
    public function dialResolvesToCallWithWinnerId(): void
    {
        $this->mock->scenarios()->armDial([
            'tag'             => 't-happy',
            'winner_call_id'  => 'winner-1',
            'states'          => ['created', 'ringing', 'answered'],
            'node_id'         => 'node-mock-1',
            'device'          => self::phoneDevice(),
            'delay_ms'        => 1,
        ]);
        $call = $this->client->dial(
            [[self::phoneDevice()]],
            ['tag' => 't-happy', 'dial_timeout' => 5.0],
        );
        $this->assertInstanceOf(Call::class, $call);
        $this->assertSame('winner-1', $call->callId);
        $this->assertSame('t-happy', $call->tag);
        $this->assertSame('answered', $call->state);
        $this->assertSame('outbound', $call->direction);

        $this->assertCount(1, $this->mock->journal()->recv('calling.dial'));
    }

    #[Test]
    public function dialJournalRecordsCallingDialFrame(): void
    {
        $this->mock->scenarios()->armDial([
            'tag'            => 't-frame',
            'winner_call_id' => 'winner-frame',
            'states'         => ['created', 'answered'],
            'node_id'        => 'node-mock-1',
            'device'         => self::phoneDevice(),
        ]);
        $this->client->dial(
            [[self::phoneDevice()]],
            ['tag' => 't-frame', 'dial_timeout' => 5.0],
        );
        $entries = $this->mock->journal()->recv('calling.dial');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('t-frame', $p['tag'] ?? null);
        $this->assertIsArray($p['devices'] ?? null);
        $this->assertSame('phone', $p['devices'][0][0]['type'] ?? null);
    }

    #[Test]
    public function dialWithMaxDurationInFrame(): void
    {
        $this->mock->scenarios()->armDial([
            'tag'            => 't-md',
            'winner_call_id' => 'winner-md',
            'states'         => ['created', 'answered'],
            'node_id'        => 'node-mock-1',
            'device'         => self::phoneDevice(),
        ]);
        $this->client->dial(
            [[self::phoneDevice()]],
            ['tag' => 't-md', 'max_duration' => 300, 'dial_timeout' => 5.0],
        );
        $entries = $this->mock->journal()->recv('calling.dial');
        $this->assertCount(1, $entries);
        $this->assertSame(300, $entries[0]->frame['params']['max_duration'] ?? null);
    }

    #[Test]
    public function dialAutoGeneratesUuidTagWhenOmitted(): void
    {
        // Without an explicit tag the SDK generates a UUID. We can't seed
        // a scenario keyed by an unknown tag — instead we fork a small
        // PHP worker that watches the journal for the dial frame, reads
        // the generated tag, then pushes a `calling.call.dial(answered)`
        // event back via the mock's HTTP control plane.
        $worker = AsyncWorker::launch(<<<'WORKER'
            $http = $argv[1];
            $winnerCallId = $argv[2];
            for ($i = 0; $i < 400; $i++) {
                $body = @file_get_contents($http . '/__mock__/journal');
                if (is_string($body)) {
                    $entries = json_decode($body, true) ?? [];
                    foreach ($entries as $e) {
                        if (
                            ($e['direction'] ?? null) === 'recv'
                            && ($e['method'] ?? null) === 'calling.dial'
                        ) {
                            $tag = $e['frame']['params']['tag'] ?? '';
                            if ($tag === '') break 2;
                            file_put_contents('/tmp/auto_tag.txt', $tag);
                            $payload = json_encode([
                                'frame' => [
                                    'jsonrpc' => '2.0',
                                    'id'      => 'x',
                                    'method'  => 'signalwire.event',
                                    'params'  => [
                                        'event_type' => 'calling.call.dial',
                                        'params'     => [
                                            'tag'        => $tag,
                                            'node_id'    => 'node-mock-1',
                                            'dial_state' => 'answered',
                                            'call'       => [
                                                'call_id'     => $winnerCallId,
                                                'node_id'     => 'node-mock-1',
                                                'tag'         => $tag,
                                                'device'      => [
                                                    'type'   => 'phone',
                                                    'params' => [
                                                        'to_number'   => '+15551112222',
                                                        'from_number' => '+15553334444',
                                                    ],
                                                ],
                                                'dial_winner' => true,
                                            ],
                                        ],
                                    ],
                                ],
                            ]);
                            $ctx = stream_context_create([
                                'http' => [
                                    'method'  => 'POST',
                                    'header'  => 'Content-Type: application/json',
                                    'content' => $payload,
                                    'timeout' => 5,
                                ],
                            ]);
                            @file_get_contents($http . '/__mock__/push', false, $ctx);
                            exit(0);
                        }
                    }
                }
                usleep(50_000);
            }
            WORKER, [$this->mock->httpUrl(), 'auto-tag-winner']);

        try {
            $call = $this->client->dial(
                [[self::phoneDevice()]],
                ['dial_timeout' => 15.0],
            );
        } finally {
            $worker->terminate();
        }
        $this->assertSame('auto-tag-winner', $call->callId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $call->tag,
        );

        // Verify the journal recorded a dial with that tag.
        $entries = $this->mock->journal()->recv('calling.dial');
        $this->assertCount(1, $entries);
        $this->assertSame($call->tag, $entries[0]->frame['params']['tag'] ?? null);
    }

    // ------------------------------------------------------------------
    // Failure paths
    // ------------------------------------------------------------------

    #[Test]
    public function dialFailedRaisesRelayError(): void
    {
        // Fork a PHP worker that watches for the dial frame, then pushes a
        // failed dial event back.
        $worker = AsyncWorker::launch(<<<'WORKER'
            $http = $argv[1];
            $tag = $argv[2];
            for ($i = 0; $i < 400; $i++) {
                $body = @file_get_contents($http . '/__mock__/journal');
                if (is_string($body)) {
                    $entries = json_decode($body, true) ?? [];
                    foreach ($entries as $e) {
                        if (
                            ($e['direction'] ?? null) === 'recv'
                            && ($e['method'] ?? null) === 'calling.dial'
                        ) {
                            $payload = json_encode([
                                'frame' => [
                                    'jsonrpc' => '2.0',
                                    'id'      => 'e',
                                    'method'  => 'signalwire.event',
                                    'params'  => [
                                        'event_type' => 'calling.call.dial',
                                        'params'     => [
                                            'tag'        => $tag,
                                            'node_id'    => 'node-mock-1',
                                            'dial_state' => 'failed',
                                            'call'       => new \stdClass(),
                                        ],
                                    ],
                                ],
                            ]);
                            $ctx = stream_context_create([
                                'http' => [
                                    'method'  => 'POST',
                                    'header'  => 'Content-Type: application/json',
                                    'content' => $payload,
                                    'timeout' => 5,
                                ],
                            ]);
                            @file_get_contents($http . '/__mock__/push', false, $ctx);
                            exit(0);
                        }
                    }
                }
                usleep(50_000);
            }
            WORKER, [$this->mock->httpUrl(), 't-fail']);

        try {
            $thrown = null;
            try {
                $this->client->dial(
                    [[self::phoneDevice()]],
                    ['tag' => 't-fail', 'dial_timeout' => 15.0],
                );
            } catch (RelayError $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown);
            $this->assertStringContainsString('Dial failed', $thrown->getMessage());
        } finally {
            $worker->terminate();
        }

        $this->assertNotEmpty($this->mock->journal()->recv('calling.dial'));
    }

    #[Test]
    public function dialTimeoutWhenNoDialEvent(): void
    {
        // Don't arm any dial scenario.
        $thrown = null;
        try {
            $this->client->dial(
                [[self::phoneDevice()]],
                ['tag' => 't-timeout', 'dial_timeout' => 0.5],
            );
        } catch (RelayError $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown);
        $this->assertStringContainsString('timed out', $thrown->getMessage());

        // Wire was still touched.
        $this->assertNotEmpty($this->mock->journal()->recv('calling.dial'));
    }

    // ------------------------------------------------------------------
    // Parallel dial — winner + losers
    // ------------------------------------------------------------------

    #[Test]
    public function dialWinnerCarriesDialWinnerTrue(): void
    {
        $this->mock->scenarios()->armDial([
            'tag'            => 't-winner',
            'winner_call_id' => 'WIN-ID',
            'states'         => ['created', 'answered'],
            'node_id'        => 'node-mock-1',
            'device'         => self::phoneDevice(),
            'losers'         => [
                ['call_id' => 'LOSE-A', 'states' => ['created', 'ended']],
                ['call_id' => 'LOSE-B', 'states' => ['created', 'ended']],
            ],
        ]);
        $call = $this->client->dial(
            [[self::phoneDevice()]],
            ['tag' => 't-winner', 'dial_timeout' => 5.0],
        );
        $this->assertSame('WIN-ID', $call->callId);

        $sends = $this->mock->journal()->send('calling.call.dial');
        $this->assertNotEmpty($sends);
        $finalForAnswered = null;
        foreach ($sends as $e) {
            $params = $e->frame['params']['params'] ?? [];
            if (($params['dial_state'] ?? null) === 'answered') {
                $finalForAnswered = $e;
            }
        }
        $this->assertNotNull($finalForAnswered);
        $inner = $finalForAnswered->frame['params']['params'] ?? [];
        $this->assertTrue($inner['call']['dial_winner'] ?? null);
        $this->assertSame('WIN-ID', $inner['call']['call_id'] ?? null);
    }

    #[Test]
    public function dialLosersGetStateEvents(): void
    {
        $this->mock->scenarios()->armDial([
            'tag'            => 't-losers',
            'winner_call_id' => 'WIN-2',
            'states'         => ['created', 'answered'],
            'node_id'        => 'node-mock-1',
            'device'         => self::phoneDevice(),
            'losers'         => [
                ['call_id' => 'L1', 'states' => ['created', 'ended']],
            ],
        ]);
        $this->client->dial(
            [[self::phoneDevice()]],
            ['tag' => 't-losers', 'dial_timeout' => 5.0],
        );
        $stateEvents = $this->mock->journal()->send('calling.call.state');
        $loserEnded = false;
        foreach ($stateEvents as $e) {
            $p = $e->frame['params']['params'] ?? [];
            if (($p['call_id'] ?? null) === 'L1' && ($p['call_state'] ?? null) === 'ended') {
                $loserEnded = true;
                break;
            }
        }
        $this->assertTrue($loserEnded, "loser L1 never reached 'ended'");

        $this->assertNotEmpty($this->mock->journal()->recv('calling.dial'));
    }

    #[Test]
    public function dialLosersCleanedUpFromCallsRegistry(): void
    {
        $this->mock->scenarios()->armDial([
            'tag'            => 't-cleanup',
            'winner_call_id' => 'WIN-CL',
            'states'         => ['created', 'answered'],
            'node_id'        => 'node-mock-1',
            'device'         => self::phoneDevice(),
            'losers'         => [
                ['call_id' => 'LOSE-CL', 'states' => ['created', 'ended']],
            ],
        ]);
        $call = $this->client->dial(
            [[self::phoneDevice()]],
            ['tag' => 't-cleanup', 'dial_timeout' => 5.0],
        );
        // Pump a moment so any straggling state events flow through.
        MockTest::pumpFor($this->client, 200);
        $this->assertArrayNotHasKey('LOSE-CL', $this->client->getCalls());
        $this->assertArrayHasKey($call->callId, $this->client->getCalls());

        $this->assertNotEmpty($this->mock->journal()->recv('calling.dial'));
    }

    // ------------------------------------------------------------------
    // Devices shape on the wire
    // ------------------------------------------------------------------

    #[Test]
    public function dialDevicesSerialTwoLegsOnWire(): void
    {
        $this->mock->scenarios()->armDial([
            'tag'            => 't-serial',
            'winner_call_id' => 'WIN-SER',
            'states'         => ['created', 'answered'],
            'node_id'        => 'node-mock-1',
            'device'         => self::phoneDevice(),
        ]);
        $devs = [[
            self::phoneDevice('+15551110001'),
            self::phoneDevice('+15551110002'),
        ]];
        $this->client->dial($devs, ['tag' => 't-serial', 'dial_timeout' => 5.0]);

        $entries = $this->mock->journal()->recv('calling.dial');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertCount(1, $p['devices']);
        $this->assertCount(2, $p['devices'][0]);
        $this->assertSame('+15551110001', $p['devices'][0][0]['params']['to_number'] ?? null);
    }

    #[Test]
    public function dialDevicesParallelTwoLegsOnWire(): void
    {
        $this->mock->scenarios()->armDial([
            'tag'            => 't-par',
            'winner_call_id' => 'WIN-PAR',
            'states'         => ['created', 'answered'],
            'node_id'        => 'node-mock-1',
            'device'         => self::phoneDevice(),
        ]);
        $devs = [
            [self::phoneDevice('+15551110001')],
            [self::phoneDevice('+15551110002')],
        ];
        $this->client->dial($devs, ['tag' => 't-par', 'dial_timeout' => 5.0]);
        $entries = $this->mock->journal()->recv('calling.dial');
        $this->assertCount(1, $entries);
        $this->assertCount(2, $entries[0]->frame['params']['devices'] ?? []);
    }

    // ------------------------------------------------------------------
    // State transitions during dial
    // ------------------------------------------------------------------

    #[Test]
    public function dialRecordsCallStateProgressionOnWinner(): void
    {
        $this->mock->scenarios()->armDial([
            'tag'            => 't-prog',
            'winner_call_id' => 'WIN-PROG',
            'states'         => ['created', 'ringing', 'answered'],
            'node_id'        => 'node-mock-1',
            'device'         => self::phoneDevice(),
        ]);
        $call = $this->client->dial(
            [[self::phoneDevice()]],
            ['tag' => 't-prog', 'dial_timeout' => 5.0],
        );
        $stateEvents = $this->mock->journal()->send('calling.call.state');
        $winnerStates = [];
        foreach ($stateEvents as $e) {
            $p = $e->frame['params']['params'] ?? [];
            if (($p['call_id'] ?? null) === 'WIN-PROG') {
                $winnerStates[] = $p['call_state'] ?? null;
            }
        }
        $this->assertContains('created', $winnerStates);
        $this->assertContains('ringing', $winnerStates);
        $this->assertContains('answered', $winnerStates);
        $this->assertSame('answered', $call->state);
    }

    // ------------------------------------------------------------------
    // After dial — call object is usable
    // ------------------------------------------------------------------

    #[Test]
    public function dialedCallCanSendSubsequentCommand(): void
    {
        $this->mock->scenarios()->armDial([
            'tag'            => 't-after',
            'winner_call_id' => 'WIN-AFTER',
            'states'         => ['created', 'answered'],
            'node_id'        => 'node-mock-1',
            'device'         => self::phoneDevice(),
        ]);
        $call = $this->client->dial(
            [[self::phoneDevice()]],
            ['tag' => 't-after', 'dial_timeout' => 5.0],
        );
        $call->hangup();
        $endFrames = $this->mock->journal()->recv('calling.end');
        $this->assertNotEmpty($endFrames);
        $this->assertSame(
            'WIN-AFTER',
            $endFrames[count($endFrames) - 1]->frame['params']['call_id'] ?? null,
        );
    }

    #[Test]
    public function dialedCallCanPlay(): void
    {
        $this->mock->scenarios()->armDial([
            'tag'            => 't-play',
            'winner_call_id' => 'WIN-PLAY',
            'states'         => ['created', 'answered'],
            'node_id'        => 'node-mock-1',
            'device'         => self::phoneDevice(),
        ]);
        $call = $this->client->dial(
            [[self::phoneDevice()]],
            ['tag' => 't-play', 'dial_timeout' => 5.0],
        );
        $call->play(
            [['type' => 'tts', 'params' => ['text' => 'hi']]],
        );
        $play = $this->mock->journal()->recv('calling.play');
        $this->assertNotEmpty($play);
        $p = $play[count($play) - 1]->frame['params'] ?? [];
        $this->assertSame('WIN-PLAY', $p['call_id'] ?? null);
        $this->assertSame('tts', $p['play'][0]['type'] ?? null);
    }

    // ------------------------------------------------------------------
    // Tag preservation
    // ------------------------------------------------------------------

    #[Test]
    public function dialPreservesExplicitTag(): void
    {
        $this->mock->scenarios()->armDial([
            'tag'            => 'my-very-explicit-tag-99',
            'winner_call_id' => 'WIN-T',
            'states'         => ['created', 'answered'],
            'node_id'        => 'node-mock-1',
            'device'         => self::phoneDevice(),
        ]);
        $call = $this->client->dial(
            [[self::phoneDevice()]],
            ['tag' => 'my-very-explicit-tag-99', 'dial_timeout' => 5.0],
        );
        $this->assertSame('my-very-explicit-tag-99', $call->tag);

        // Wire confirms.
        $entries = $this->mock->journal()->recv('calling.dial');
        $this->assertSame(
            'my-very-explicit-tag-99',
            $entries[0]->frame['params']['tag'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // JSON-RPC envelope
    // ------------------------------------------------------------------

    #[Test]
    public function dialUsesJsonrpc20(): void
    {
        $this->mock->scenarios()->armDial([
            'tag'            => 't-rpc',
            'winner_call_id' => 'W',
            'states'         => ['created', 'answered'],
            'node_id'        => 'n',
            'device'         => self::phoneDevice(),
        ]);
        $this->client->dial(
            [[self::phoneDevice()]],
            ['tag' => 't-rpc', 'dial_timeout' => 5.0],
        );
        $entries = $this->mock->journal()->recv('calling.dial');
        $this->assertCount(1, $entries);
        $f = $entries[0]->frame;
        $this->assertSame('2.0', $f['jsonrpc'] ?? null);
        $this->assertSame('calling.dial', $f['method'] ?? null);
        $this->assertArrayHasKey('id', $f);
        $this->assertArrayHasKey('params', $f);
    }
}
