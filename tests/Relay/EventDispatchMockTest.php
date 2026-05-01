<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\Relay\Call;
use SignalWire\Relay\Client as RelayClient;
use SignalWire\Relay\Event;

/**
 * Real-mock-backed tests for the SDK's event dispatch / routing logic.
 *
 * Ported from signalwire-python/tests/unit/relay/test_event_dispatch_mock.py.
 *
 * Focus: edge cases in the recv loop and event router that don't fit
 * neatly into per-action / per-call test files.
 */
class EventDispatchMockTest extends TestCase
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
     * Push an inbound call through the handler, return the Call.
     */
    private function answeredInboundCall(string $callId): Call
    {
        $captured = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($captured): void {
            $captured['call'] = $call;
            $call->answer();
        });
        $this->mock->inboundCall(['call_id' => $callId, 'auto_states' => ['created']]);
        $arrived = MockTest::pumpUntil(
            $this->client,
            fn() => isset($captured['call']),
            5.0,
        );
        $this->assertTrue($arrived);
        /** @var Call $call */
        $call = $captured['call'];
        $call->state = 'answered';
        return $call;
    }

    /**
     * @return array<string,mixed>
     */
    private static function bareEventFrame(string $eventType, array $params): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => bin2hex(random_bytes(8)),
            'method'  => 'signalwire.event',
            'params'  => ['event_type' => $eventType, 'params' => $params],
        ];
    }

    // ------------------------------------------------------------------
    // Sub-command journaling
    // ------------------------------------------------------------------

    #[Test]
    public function recordPauseJournalsRecordPause(): void
    {
        $call = $this->answeredInboundCall('ec-rec-pa');
        $action = $call->record(
            ['format' => 'wav'],
            ['control_id' => 'ec-rec-pa-1'],
        );
        $action->pause(['behavior' => 'continuous']);
        $pauses = $this->mock->journal()->recv('calling.record.pause');
        $this->assertNotEmpty($pauses);
        $p = $pauses[count($pauses) - 1]->frame['params'] ?? [];
        $this->assertSame('ec-rec-pa-1', $p['control_id'] ?? null);
        $this->assertSame('continuous', $p['behavior'] ?? null);
    }

    #[Test]
    public function recordResumeJournalsRecordResume(): void
    {
        $call = $this->answeredInboundCall('ec-rec-re');
        $action = $call->record(
            ['format' => 'wav'],
            ['control_id' => 'ec-rec-re-1'],
        );
        $action->resume();
        $resumes = $this->mock->journal()->recv('calling.record.resume');
        $this->assertNotEmpty($resumes);
        $this->assertSame(
            'ec-rec-re-1',
            $resumes[count($resumes) - 1]->frame['params']['control_id'] ?? null,
        );
    }

    #[Test]
    public function collectStartInputTimersJournalsCorrectly(): void
    {
        $call = $this->answeredInboundCall('ec-col-sit');
        $action = $call->collect([
            'digits'             => ['max' => 4],
            'start_input_timers' => false,
            'control_id'         => 'ec-col-sit-1',
        ]);
        $action->startInputTimers();
        $starts = $this->mock->journal()->recv('calling.collect.start_input_timers');
        $this->assertNotEmpty($starts);
        $this->assertSame(
            'ec-col-sit-1',
            $starts[count($starts) - 1]->frame['params']['control_id'] ?? null,
        );
    }

    #[Test]
    public function playVolumeCarriesNegativeValue(): void
    {
        $call = $this->answeredInboundCall('ec-pvol');
        $action = $call->play(
            [['type' => 'silence', 'params' => ['duration' => 60]]],
            ['control_id' => 'ec-pvol-1'],
        );
        $action->volume(-5.5);
        $vol = $this->mock->journal()->recv('calling.play.volume');
        $this->assertNotEmpty($vol);
        $this->assertEqualsWithDelta(
            -5.5,
            (float) ($vol[count($vol) - 1]->frame['params']['volume'] ?? null),
            0.0001,
        );
    }

    // ------------------------------------------------------------------
    // Unknown event types — recv loop survives
    // ------------------------------------------------------------------

    #[Test]
    public function unknownEventTypeDoesNotCrash(): void
    {
        $this->mock->push(self::bareEventFrame('nonsense.unknown', ['foo' => 'bar']));
        MockTest::pumpFor($this->client, 100);
        $this->assertTrue($this->client->connected);

        // Smoke: harness wired up correctly.
        $this->assertNotEmpty($this->mock->journal()->all());
    }

    #[Test]
    public function eventWithBadCallIdIsDropped(): void
    {
        $this->mock->push(self::bareEventFrame('calling.call.play', [
            'call_id'    => 'no-such-call-bogus',
            'control_id' => 'stranger',
            'state'      => 'playing',
        ]));
        MockTest::pumpFor($this->client, 100);
        $this->assertTrue($this->client->connected);

        $this->assertNotEmpty($this->mock->journal()->all());
    }

    #[Test]
    public function eventWithEmptyEventTypeIsDropped(): void
    {
        $this->mock->push(self::bareEventFrame('', ['call_id' => 'x']));
        MockTest::pumpFor($this->client, 100);
        $this->assertTrue($this->client->connected);

        $this->assertNotEmpty($this->mock->journal()->all());
    }

    // ------------------------------------------------------------------
    // Multi-action concurrency: 3 actions on one call
    // ------------------------------------------------------------------

    #[Test]
    public function threeConcurrentActionsResolveIndependently(): void
    {
        $call = $this->answeredInboundCall('ec-3acts');
        $play1 = $call->play(
            [['type' => 'silence', 'params' => ['duration' => 60]]],
            ['control_id' => '3a-p1'],
        );
        $play2 = $call->play(
            [['type' => 'silence', 'params' => ['duration' => 60]]],
            ['control_id' => '3a-p2'],
        );
        $rec = $call->record(['format' => 'wav'], ['control_id' => '3a-r1']);

        // Fire only play1's finished.
        $this->mock->push(self::bareEventFrame('calling.call.play', [
            'call_id'    => 'ec-3acts',
            'control_id' => '3a-p1',
            'state'      => 'finished',
        ]));
        $play1->wait(2);
        $this->assertTrue($play1->isDone());
        $this->assertFalse($play2->isDone());
        $this->assertFalse($rec->isDone());

        // Fire play2's.
        $this->mock->push(self::bareEventFrame('calling.call.play', [
            'call_id'    => 'ec-3acts',
            'control_id' => '3a-p2',
            'state'      => 'finished',
        ]));
        $play2->wait(2);
        $this->assertTrue($play2->isDone());
        $this->assertFalse($rec->isDone());

        $this->assertNotEmpty($this->mock->journal()->recv('calling.play'));
        $this->assertNotEmpty($this->mock->journal()->recv('calling.record'));
    }

    // ------------------------------------------------------------------
    // Event ACK round-trip — server-pushed events get ack frames back
    // ------------------------------------------------------------------

    #[Test]
    public function eventAckSentBackToServer(): void
    {
        $evtId = 'evt-ack-test-1';
        $this->mock->push([
            'jsonrpc' => '2.0',
            'id'      => $evtId,
            'method'  => 'signalwire.event',
            'params'  => [
                'event_type' => 'calling.call.play',
                'params'     => [
                    'call_id'    => 'anything',
                    'control_id' => 'x',
                    'state'      => 'playing',
                ],
            ],
        ]);
        MockTest::pumpFor($this->client, 200);

        // The ACK is a recv frame (server PoV) with id == evtId and a
        // result key.
        $acks = [];
        foreach ($this->mock->journal()->all() as $e) {
            if (
                $e->direction === 'recv'
                && ($e->frame['id'] ?? null) === $evtId
                && array_key_exists('result', $e->frame)
            ) {
                $acks[] = $e;
            }
        }
        $this->assertNotEmpty($acks, "no event ACK with id={$evtId}");
    }

    // ------------------------------------------------------------------
    // Tag-based dial routing — call.call_id nested
    // ------------------------------------------------------------------

    #[Test]
    public function dialEventRoutesViaTagWhenNoTopLevelCallId(): void
    {
        // Use a fresh client so the dial is independent of the shared one.
        $client = MockTest::client();
        try {
            $this->mock->scenarios()->armDial([
                'tag'            => 'ec-tag-route',
                'winner_call_id' => 'WINTAG',
                'states'         => ['created', 'answered'],
                'node_id'        => 'n',
                'device'         => ['type' => 'phone', 'params' => new \stdClass()],
            ]);
            $call = $client->dial(
                [[['type' => 'phone', 'params' => ['to_number' => '+1', 'from_number' => '+2']]]],
                ['tag' => 'ec-tag-route', 'dial_timeout' => 5.0],
            );
            $this->assertSame('WINTAG', $call->callId);

            $sends = $this->mock->journal()->send('calling.call.dial');
            $this->assertNotEmpty($sends);
            $inner = $sends[count($sends) - 1]->frame['params']['params'] ?? [];
            // Top-level params: tag, dial_state, call. NO call_id.
            $this->assertArrayNotHasKey('call_id', $inner);
            $this->assertSame('WINTAG', $inner['call']['call_id'] ?? null);
        } finally {
            $client->disconnect();
        }
    }

    // ------------------------------------------------------------------
    // Server ping handling
    // ------------------------------------------------------------------

    #[Test]
    public function serverPingAckedBySdk(): void
    {
        $pingId = 'ping-test-1';
        $this->mock->push([
            'jsonrpc' => '2.0',
            'id'      => $pingId,
            'method'  => 'signalwire.ping',
            'params'  => new \stdClass(),
        ]);
        MockTest::pumpFor($this->client, 200);

        $pongs = [];
        foreach ($this->mock->journal()->all() as $e) {
            if (
                $e->direction === 'recv'
                && ($e->frame['id'] ?? null) === $pingId
                && array_key_exists('result', $e->frame)
            ) {
                $pongs[] = $e;
            }
        }
        $this->assertNotEmpty($pongs);
    }

    // ------------------------------------------------------------------
    // Authorization state — captured for reconnect
    // ------------------------------------------------------------------

    #[Test]
    public function authorizationStateEventCaptured(): void
    {
        $this->mock->push(self::bareEventFrame(
            'signalwire.authorization.state',
            ['authorization_state' => 'test-auth-state-blob'],
        ));
        $captured = MockTest::pumpUntil(
            $this->client,
            fn() => $this->client->authorizationState === 'test-auth-state-blob',
            5.0,
        );
        $this->assertTrue($captured);
        $this->assertSame('test-auth-state-blob', $this->client->authorizationState);

        $this->assertNotEmpty($this->mock->journal()->all());
    }

    // ------------------------------------------------------------------
    // Calling.error event — does not raise into the SDK
    // ------------------------------------------------------------------

    #[Test]
    public function callingErrorEventDoesNotCrash(): void
    {
        $this->mock->push(self::bareEventFrame(
            'calling.error',
            ['code' => '5001', 'message' => 'synthetic error'],
        ));
        MockTest::pumpFor($this->client, 100);
        $this->assertTrue($this->client->connected);

        $this->assertNotEmpty($this->mock->journal()->all());
    }

    // ------------------------------------------------------------------
    // State event for an answered call updates Call.state
    // ------------------------------------------------------------------

    #[Test]
    public function callStateEventUpdatesState(): void
    {
        $call = $this->answeredInboundCall('ec-stt');
        $this->mock->push(self::bareEventFrame('calling.call.state', [
            'call_id'    => 'ec-stt',
            'call_state' => 'ending',
            'direction'  => 'inbound',
        ]));
        $reached = MockTest::pumpUntil(
            $this->client,
            fn() => $call->state === 'ending',
            5.0,
        );
        $this->assertTrue($reached);
        $this->assertSame('ending', $call->state);

        $this->assertNotEmpty($this->mock->journal()->recv('calling.answer'));
    }

    #[Test]
    public function callListenerFiresOnEvent(): void
    {
        $call = $this->answeredInboundCall('ec-list');
        $seen = new \ArrayObject();
        $call->on(function (Event $e) use ($seen): void {
            if ($e->getEventType() === 'calling.call.play') {
                $seen[] = $e;
            }
        });
        $this->mock->push(self::bareEventFrame('calling.call.play', [
            'call_id'    => 'ec-list',
            'control_id' => 'x',
            'state'      => 'playing',
        ]));
        $arrived = MockTest::pumpUntil(
            $this->client,
            fn() => count($seen) >= 1,
            2.0,
        );
        $this->assertTrue($arrived);
        $this->assertSame('calling.call.play', $seen[0]->getEventType());

        $this->assertNotEmpty($this->mock->journal()->all());
    }
}
