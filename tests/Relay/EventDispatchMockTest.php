<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\Relay\Call;
use SignalWire\Relay\CallState;
use SignalWire\Relay\Client as RelayClient;
use SignalWire\Relay\Event;
use SignalWire\Tests\Support\Shape;

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
        [$this->client, $this->mock] = MockTest::scopedClient();
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
        /** @var \ArrayObject<string,Call> $captured */
        $captured = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($captured): void {
            $captured['call'] = $call;
            $call->answer();
        });
        $this->mock->inboundCall(['call_id' => $callId, 'auto_states' => ['created']]);
        $arrived = MockTest::pumpUntil(
            $this->client,
            fn () => isset($captured['call']),
            5.0,
        );
        $this->assertTrue($arrived);
        /** @var Call $call */
        $call = $captured['call'];
        $call->state = 'answered';
        return $call;
    }

    /**
     * @param array<string,mixed> $params
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
        $action->pause('continuous');
        $pauses = $this->mock->journal()->recv('calling.record.pause');
        $this->assertNotEmpty($pauses);
        $p = Shape::sub($pauses[count($pauses) - 1]->frame, 'params');
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
            Shape::at($resumes[count($resumes) - 1]->frame, 'params', 'control_id'),
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
            Shape::at($starts[count($starts) - 1]->frame, 'params', 'control_id'),
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
        $volValue = Shape::at($vol[count($vol) - 1]->frame, 'params', 'volume');
        $this->assertIsNumeric($volValue);
        $this->assertEqualsWithDelta(-5.5, (float) $volValue, 0.0001);
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
        // It needs its OWN scoped harness so the armed scenario and journal
        // reads target this second client's session, not setUp's.
        [$client, $mock] = MockTest::scopedClient();
        try {
            $mock->scenarios()->armDial([
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

            $sends = $mock->journal()->send('calling.call.dial');
            $this->assertNotEmpty($sends);
            $inner = Shape::sub($sends[count($sends) - 1]->frame, 'params', 'params');
            // Top-level params: tag, dial_state, call. NO call_id.
            $this->assertArrayNotHasKey('call_id', $inner);
            $this->assertSame('WINTAG', Shape::at($inner, 'call', 'call_id'));
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
            fn () => $this->client->authorizationState === 'test-auth-state-blob',
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
            fn () => $call->state === 'ending',
            5.0,
        );
        $this->assertTrue($reached);
        $this->assertSame('ending', $call->state);

        $this->assertNotEmpty($this->mock->journal()->recv('calling.answer'));
    }

    // ------------------------------------------------------------------
    // Typed CallState accessor agrees with the string after a real
    // dispatched calling.call.state event (PORT_ADDITION, drives the
    // real recv loop — no mocks of the transport).
    // ------------------------------------------------------------------

    #[Test]
    public function callStateTypedAccessorAgreesWithStringInitialAnswered(): void
    {
        $call = $this->answeredInboundCall('ec-typed-init');

        // Before the terminal event: answered, not terminal.
        $this->assertSame('answered', $call->state);
        $this->assertSame(CallState::Answered, $call->callState());
        $this->assertFalse($call->callState()->isTerminal());
    }

    #[Test]
    public function callStateTypedAccessorAgreesWithStringAfterDispatch(): void
    {
        $call = $this->answeredInboundCall('ec-typed');

        // Drive a real ending->state event through the recv loop.
        $this->mock->push(self::bareEventFrame('calling.call.state', [
            'call_id'    => 'ec-typed',
            'call_state' => 'ended',
            'direction'  => 'inbound',
        ]));
        $reached = MockTest::pumpUntil(
            $this->client,
            fn () => $call->state === 'ended',
            5.0,
        );
        $this->assertTrue($reached);

        // The typed accessor reflects the SAME state the string carries after
        // the dispatched event — proving the enum tracks the dispatched event,
        // not a hand-set value. (Once callState() is proven to be
        // CallState::Ended, ->value === 'ended' and ->isTerminal() === true
        // hold by the enum's definition.)
        $this->assertSame('ended', $call->state);
        $this->assertSame(CallState::Ended, $call->callState());
    }

    #[Test]
    public function callListenerFiresOnEvent(): void
    {
        $call = $this->answeredInboundCall('ec-list');
        /** @var \ArrayObject<int,Event> $seen */
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
            fn () => count($seen) >= 1,
            2.0,
        );
        $this->assertTrue($arrived);
        $first = $seen[0];
        $this->assertNotNull($first);
        $this->assertSame('calling.call.play', $first->getEventType());

        $this->assertNotEmpty($this->mock->journal()->all());
    }
}
