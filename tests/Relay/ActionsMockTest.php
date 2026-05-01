<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\Relay\Action;
use SignalWire\Relay\AIAction;
use SignalWire\Relay\Call;
use SignalWire\Relay\Client as RelayClient;
use SignalWire\Relay\CollectAction;
use SignalWire\Relay\DetectAction;
use SignalWire\Relay\Event;
use SignalWire\Relay\FaxAction;
use SignalWire\Relay\PayAction;
use SignalWire\Relay\PlayAction;
use SignalWire\Relay\RecordAction;
use SignalWire\Relay\StreamAction;
use SignalWire\Relay\TapAction;
use SignalWire\Relay\TranscribeAction;

/**
 * Real-mock-backed tests for Action classes (Play / Record / Detect /
 * Collect / PlayAndCollect / Pay / Fax / Tap / Stream / Transcribe / AI).
 *
 * Ported from signalwire-python/tests/unit/relay/test_actions_mock.py.
 *
 * Each test:
 * 1. answers an inbound call (so we have a Call to issue actions on),
 * 2. issues the action,
 * 3. asserts the on-wire ``calling.<verb>`` frame (call_id / control_id /
 *    payload),
 * 4. where applicable, drives mock-pushed state events to terminal,
 *    asserts the action resolved with the right state, and
 * 5. validates the corresponding ``stop`` / ``pause`` / ``resume`` /
 *    ``volume`` / ``start_input_timers`` sub-commands also journal.
 */
class ActionsMockTest extends TestCase
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
     * Push an inbound call, wait for the handler to capture it, return it
     * with ``state="answered"`` so subsequent action sends don't think the
     * call is gone.
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
        $this->assertTrue($arrived, "inbound call {$callId} did not reach handler");
        /** @var Call $call */
        $call = $captured['call'];
        // Force the SDK side into "answered" so any subsequent action
        // checks pass — the mock won't push a state(answered) on its own.
        $call->state = 'answered';
        return $call;
    }

    // ------------------------------------------------------------------
    // PlayAction
    // ------------------------------------------------------------------

    #[Test]
    public function playJournalsCallingPlay(): void
    {
        $call = $this->answeredInboundCall('call-play');
        $call->play(
            [['type' => 'tts', 'params' => ['text' => 'hi']]],
            ['control_id' => 'play-ctl-1'],
        );
        $entries = $this->mock->journal()->recv('calling.play');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('call-play', $p['call_id'] ?? null);
        $this->assertSame('play-ctl-1', $p['control_id'] ?? null);
        $this->assertSame('tts', $p['play'][0]['type'] ?? null);
    }

    #[Test]
    public function playResolvesOnFinishedEvent(): void
    {
        $call = $this->answeredInboundCall('call-play-fin');
        $this->mock->scenarios()->arm('calling.play', [
            ['emit' => ['state' => 'playing'], 'delay_ms' => 1],
            ['emit' => ['state' => 'finished'], 'delay_ms' => 5],
        ]);
        $action = $call->play(
            [['type' => 'silence', 'params' => ['duration' => 1]]],
            ['control_id' => 'play-ctl-fin'],
        );
        $this->assertInstanceOf(PlayAction::class, $action);
        $event = $action->wait(5);
        $this->assertNotNull($event);
        $this->assertTrue($action->isDone());
        $this->assertSame('finished', $event->getParams()['state'] ?? null);

        $this->assertNotEmpty($this->mock->journal()->recv('calling.play'));
    }

    #[Test]
    public function playStopJournalsPlayStop(): void
    {
        $call = $this->answeredInboundCall('call-play-stop');
        $action = $call->play(
            [['type' => 'silence', 'params' => ['duration' => 60]]],
            ['control_id' => 'play-ctl-stop'],
        );
        $action->stop();
        $stops = $this->mock->journal()->recv('calling.play.stop');
        $this->assertNotEmpty($stops);
        $this->assertSame(
            'play-ctl-stop',
            $stops[count($stops) - 1]->frame['params']['control_id'] ?? null,
        );
    }

    #[Test]
    public function playPauseResumeVolumeJournal(): void
    {
        $call = $this->answeredInboundCall('call-play-prv');
        $action = $call->play(
            [['type' => 'silence', 'params' => ['duration' => 60]]],
            ['control_id' => 'play-ctl-prv'],
        );
        $action->pause();
        $action->resume();
        $action->volume(-3.0);

        $this->assertNotEmpty($this->mock->journal()->recv('calling.play.pause'));
        $this->assertNotEmpty($this->mock->journal()->recv('calling.play.resume'));
        $vols = $this->mock->journal()->recv('calling.play.volume');
        $this->assertNotEmpty($vols);
        // JSON has no int/float distinction; the volume could decode as
        // either depending on the encoder. Compare numerically.
        $this->assertEqualsWithDelta(
            -3.0,
            (float) ($vols[count($vols) - 1]->frame['params']['volume'] ?? null),
            0.0001,
        );
    }

    #[Test]
    public function playOnCompletedCallbackFires(): void
    {
        $call = $this->answeredInboundCall('call-play-cb');
        $this->mock->scenarios()->arm('calling.play', [
            ['emit' => ['state' => 'finished'], 'delay_ms' => 1],
        ]);

        $seen = new \ArrayObject();
        $action = $call->play(
            [['type' => 'silence', 'params' => ['duration' => 1]]],
            [
                'control_id'   => 'play-ctl-cb',
                'on_completed' => function (Action $a) use ($seen): void {
                    $seen[] = $a;
                },
            ],
        );
        $action->wait(5);
        $this->assertCount(1, $seen);
        // The callback receives the resolved Action.
        $this->assertSame($action, $seen[0]);

        $this->assertNotEmpty($this->mock->journal()->recv('calling.play'));
    }

    // ------------------------------------------------------------------
    // RecordAction
    // ------------------------------------------------------------------

    #[Test]
    public function recordJournalsCallingRecord(): void
    {
        $call = $this->answeredInboundCall('call-rec');
        $call->record(
            ['format' => 'mp3'],
            ['control_id' => 'rec-ctl-1'],
        );
        $entries = $this->mock->journal()->recv('calling.record');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('call-rec', $p['call_id'] ?? null);
        $this->assertSame('rec-ctl-1', $p['control_id'] ?? null);
        $this->assertSame('mp3', $p['record']['audio']['format'] ?? null);
    }

    #[Test]
    public function recordResolvesOnFinishedEvent(): void
    {
        $call = $this->answeredInboundCall('call-rec-fin');
        $this->mock->scenarios()->arm('calling.record', [
            ['emit' => ['state' => 'recording'], 'delay_ms' => 1],
            ['emit' => ['state' => 'finished', 'url' => 'http://r.wav'], 'delay_ms' => 5],
        ]);
        $action = $call->record(
            ['format' => 'wav'],
            ['control_id' => 'rec-ctl-fin'],
        );
        $this->assertInstanceOf(RecordAction::class, $action);
        $event = $action->wait(5);
        $this->assertNotNull($event);
        $this->assertSame('finished', $event->getParams()['state'] ?? null);

        $this->assertNotEmpty($this->mock->journal()->recv('calling.record'));
    }

    #[Test]
    public function recordStopJournalsRecordStop(): void
    {
        $call = $this->answeredInboundCall('call-rec-stop');
        $action = $call->record(
            ['format' => 'wav'],
            ['control_id' => 'rec-ctl-stop'],
        );
        $action->stop();
        $stops = $this->mock->journal()->recv('calling.record.stop');
        $this->assertNotEmpty($stops);
        $this->assertSame(
            'rec-ctl-stop',
            $stops[count($stops) - 1]->frame['params']['control_id'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // DetectAction — gotcha: resolves on first detect payload
    // ------------------------------------------------------------------

    #[Test]
    public function detectResolvesOnFirstDetectPayload(): void
    {
        $call = $this->answeredInboundCall('call-det');
        $this->mock->scenarios()->arm('calling.detect', [
            // First payload: a real detect result. Should resolve.
            [
                'emit'    => [
                    'detect' => ['type' => 'machine', 'params' => ['event' => 'MACHINE']],
                ],
                'delay_ms' => 1,
            ],
            // Then a finished — but we already resolved on the first.
            ['emit' => ['state' => 'finished'], 'delay_ms' => 10],
        ]);
        $action = $call->detect(
            ['type' => 'machine', 'params' => new \stdClass()],
            ['control_id' => 'det-ctl-1'],
        );
        $this->assertInstanceOf(DetectAction::class, $action);
        $event = $action->wait(5);
        $this->assertNotNull($event);
        // Resolved with the detect payload, not the state(finished).
        $this->assertSame(
            'machine',
            $event->getParams()['detect']['type'] ?? null,
        );

        $this->assertNotEmpty($this->mock->journal()->recv('calling.detect'));
    }

    #[Test]
    public function detectStopJournalsDetectStop(): void
    {
        $call = $this->answeredInboundCall('call-det-stop');
        $action = $call->detect(
            ['type' => 'fax', 'params' => new \stdClass()],
            ['control_id' => 'det-stop'],
        );
        $action->stop();
        $stops = $this->mock->journal()->recv('calling.detect.stop');
        $this->assertNotEmpty($stops);
        $this->assertSame(
            'det-stop',
            $stops[count($stops) - 1]->frame['params']['control_id'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // CollectAction (play_and_collect) — gotcha: ignore play(finished)
    // ------------------------------------------------------------------

    #[Test]
    public function playAndCollectJournalsPlayAndCollect(): void
    {
        $call = $this->answeredInboundCall('call-pac');
        $call->playAndCollect(
            [['type' => 'tts', 'params' => ['text' => 'Press 1']]],
            ['digits' => ['max' => 1]],
            ['control_id' => 'pac-ctl-1'],
        );
        $entries = $this->mock->journal()->recv('calling.play_and_collect');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('call-pac', $p['call_id'] ?? null);
        $this->assertSame('tts', $p['play'][0]['type'] ?? null);
        $this->assertSame(1, $p['collect']['digits']['max'] ?? null);
    }

    #[Test]
    public function playAndCollectResolvesOnCollectEventOnly(): void
    {
        $call = $this->answeredInboundCall('call-pac-go');
        $action = $call->playAndCollect(
            [['type' => 'silence', 'params' => ['duration' => 1]]],
            ['digits' => ['max' => 1]],
            ['control_id' => 'pac-go'],
        );
        $this->assertInstanceOf(CollectAction::class, $action);

        // Push a play(finished) — the action MUST NOT resolve.
        $this->mock->push([
            'jsonrpc' => '2.0',
            'id'      => bin2hex(random_bytes(8)),
            'method'  => 'signalwire.event',
            'params'  => [
                'event_type' => 'calling.call.play',
                'params'     => [
                    'call_id'    => 'call-pac-go',
                    'control_id' => 'pac-go',
                    'state'      => 'finished',
                ],
            ],
        ]);
        MockTest::pumpFor($this->client, 200);
        $this->assertFalse(
            $action->isDone(),
            'play_and_collect resolved on play(finished); should wait for collect',
        );

        // Push the collect event — action resolves.
        $this->mock->push([
            'jsonrpc' => '2.0',
            'id'      => bin2hex(random_bytes(8)),
            'method'  => 'signalwire.event',
            'params'  => [
                'event_type' => 'calling.call.collect',
                'params'     => [
                    'call_id'    => 'call-pac-go',
                    'control_id' => 'pac-go',
                    'result'     => ['type' => 'digit', 'params' => ['digits' => '1']],
                ],
            ],
        ]);
        $event = $action->wait(2);
        $this->assertNotNull($event);
        $this->assertSame('calling.call.collect', $event->getEventType());
        $this->assertSame(
            'digit',
            $event->getParams()['result']['type'] ?? null,
        );

        $this->assertNotEmpty($this->mock->journal()->recv('calling.play_and_collect'));
    }

    #[Test]
    public function playAndCollectStopJournalsPacStop(): void
    {
        $call = $this->answeredInboundCall('call-pac-stop');
        $action = $call->playAndCollect(
            [['type' => 'silence', 'params' => ['duration' => 1]]],
            ['digits' => ['max' => 1]],
            ['control_id' => 'pac-stop'],
        );
        $action->stop();
        $stops = $this->mock->journal()->recv('calling.play_and_collect.stop');
        $this->assertNotEmpty($stops);
        $this->assertSame(
            'pac-stop',
            $stops[count($stops) - 1]->frame['params']['control_id'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // CollectAction (standalone)
    // ------------------------------------------------------------------

    #[Test]
    public function collectJournalsCallingCollect(): void
    {
        $call = $this->answeredInboundCall('call-col');
        $action = $call->collect([
            'digits'     => ['max' => 4],
            'control_id' => 'col-ctl',
        ]);
        $this->assertInstanceOf(CollectAction::class, $action);

        $entries = $this->mock->journal()->recv('calling.collect');
        $this->assertCount(1, $entries);
        $this->assertSame(
            ['max' => 4],
            $entries[0]->frame['params']['digits'] ?? null,
        );
        $this->assertSame(
            'col-ctl',
            $entries[0]->frame['params']['control_id'] ?? null,
        );
    }

    #[Test]
    public function collectStopJournalsCollectStop(): void
    {
        $call = $this->answeredInboundCall('call-col-stop');
        $action = $call->collect([
            'digits'     => ['max' => 4],
            'control_id' => 'col-stop',
        ]);
        $action->stop();
        $stops = $this->mock->journal()->recv('calling.collect.stop');
        $this->assertNotEmpty($stops);
        $this->assertSame(
            'col-stop',
            $stops[count($stops) - 1]->frame['params']['control_id'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // PayAction
    // ------------------------------------------------------------------

    #[Test]
    public function payJournalsCallingPay(): void
    {
        $call = $this->answeredInboundCall('call-pay');
        $call->pay('https://pay.example/connect', [
            'control_id'    => 'pay-ctl',
            'charge_amount' => '9.99',
        ]);
        $entries = $this->mock->journal()->recv('calling.pay');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('https://pay.example/connect', $p['payment_connector_url'] ?? null);
        $this->assertSame('pay-ctl', $p['control_id'] ?? null);
        $this->assertSame('9.99', $p['charge_amount'] ?? null);
    }

    #[Test]
    public function payReturnsPayAction(): void
    {
        $call = $this->answeredInboundCall('call-pay-act');
        $action = $call->pay('https://pay.example/connect', ['control_id' => 'pay-act']);
        $this->assertInstanceOf(PayAction::class, $action);
        $this->assertSame('pay-act', $action->getControlId());

        $this->assertNotEmpty($this->mock->journal()->recv('calling.pay'));
    }

    #[Test]
    public function payStopJournalsPayStop(): void
    {
        $call = $this->answeredInboundCall('call-pay-stop');
        $action = $call->pay(
            'https://pay.example/connect',
            ['control_id' => 'pay-stop'],
        );
        $action->stop();
        $stops = $this->mock->journal()->recv('calling.pay.stop');
        $this->assertNotEmpty($stops);
        $this->assertSame(
            'pay-stop',
            $stops[count($stops) - 1]->frame['params']['control_id'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // FaxAction
    // ------------------------------------------------------------------

    #[Test]
    public function sendFaxJournalsCallingSendFax(): void
    {
        $call = $this->answeredInboundCall('call-sfax');
        $call->sendFax(
            'https://docs.example/test.pdf',
            '+15551112222',
            ['control_id' => 'sfax-ctl'],
        );
        $entries = $this->mock->journal()->recv('calling.send_fax');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('https://docs.example/test.pdf', $p['document'] ?? null);
        $this->assertSame('+15551112222', $p['identity'] ?? null);
        $this->assertSame('sfax-ctl', $p['control_id'] ?? null);
    }

    #[Test]
    public function receiveFaxReturnsFaxAction(): void
    {
        $call = $this->answeredInboundCall('call-rfax');
        $action = $call->receiveFax(['control_id' => 'rfax-ctl']);
        $this->assertInstanceOf(FaxAction::class, $action);
        $this->assertSame('receive', $action->getFaxType());

        $this->assertNotEmpty($this->mock->journal()->recv('calling.receive_fax'));
    }

    // ------------------------------------------------------------------
    // TapAction
    // ------------------------------------------------------------------

    #[Test]
    public function tapJournalsCallingTap(): void
    {
        $call = $this->answeredInboundCall('call-tap');
        $call->tap(
            ['type' => 'audio', 'params' => new \stdClass()],
            ['type' => 'rtp', 'params' => ['addr' => '203.0.113.1', 'port' => 4000]],
            ['control_id' => 'tap-ctl'],
        );
        $entries = $this->mock->journal()->recv('calling.tap');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('audio', $p['tap']['type'] ?? null);
        $this->assertSame(4000, $p['device']['params']['port'] ?? null);
        $this->assertSame('tap-ctl', $p['control_id'] ?? null);
    }

    #[Test]
    public function tapStopJournalsTapStop(): void
    {
        $call = $this->answeredInboundCall('call-tap-stop');
        $action = $call->tap(
            ['type' => 'audio', 'params' => new \stdClass()],
            ['type' => 'rtp', 'params' => ['addr' => '203.0.113.1', 'port' => 4000]],
            ['control_id' => 'tap-stop'],
        );
        $this->assertInstanceOf(TapAction::class, $action);
        $action->stop();
        $stops = $this->mock->journal()->recv('calling.tap.stop');
        $this->assertNotEmpty($stops);
        $this->assertSame(
            'tap-stop',
            $stops[count($stops) - 1]->frame['params']['control_id'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // StreamAction
    // ------------------------------------------------------------------

    #[Test]
    public function streamJournalsCallingStream(): void
    {
        $call = $this->answeredInboundCall('call-strm');
        $call->stream(
            'wss://stream.example/audio',
            ['codec' => 'OPUS@48000h', 'control_id' => 'strm-ctl'],
        );
        $entries = $this->mock->journal()->recv('calling.stream');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('wss://stream.example/audio', $p['url'] ?? null);
        $this->assertSame('OPUS@48000h', $p['codec'] ?? null);
        $this->assertSame('strm-ctl', $p['control_id'] ?? null);
    }

    #[Test]
    public function streamStopJournalsStreamStop(): void
    {
        $call = $this->answeredInboundCall('call-strm-stop');
        $action = $call->stream(
            'wss://stream.example/audio',
            ['control_id' => 'strm-stop'],
        );
        $this->assertInstanceOf(StreamAction::class, $action);
        $action->stop();
        $stops = $this->mock->journal()->recv('calling.stream.stop');
        $this->assertNotEmpty($stops);
        $this->assertSame(
            'strm-stop',
            $stops[count($stops) - 1]->frame['params']['control_id'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // TranscribeAction
    // ------------------------------------------------------------------

    #[Test]
    public function transcribeJournalsCallingTranscribe(): void
    {
        $call = $this->answeredInboundCall('call-tr');
        $action = $call->transcribe(['control_id' => 'tr-ctl']);
        $this->assertInstanceOf(TranscribeAction::class, $action);

        $entries = $this->mock->journal()->recv('calling.transcribe');
        $this->assertCount(1, $entries);
        $this->assertSame(
            'tr-ctl',
            $entries[0]->frame['params']['control_id'] ?? null,
        );
    }

    #[Test]
    public function transcribeStopJournalsTranscribeStop(): void
    {
        $call = $this->answeredInboundCall('call-tr-stop');
        $action = $call->transcribe(['control_id' => 'tr-stop']);
        $action->stop();
        $stops = $this->mock->journal()->recv('calling.transcribe.stop');
        $this->assertNotEmpty($stops);
        $this->assertSame(
            'tr-stop',
            $stops[count($stops) - 1]->frame['params']['control_id'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // AIAction
    // ------------------------------------------------------------------

    #[Test]
    public function aiJournalsCallingAi(): void
    {
        $call = $this->answeredInboundCall('call-ai');
        $action = $call->ai(
            ['text' => 'You are helpful.'],
            ['control_id' => 'ai-ctl'],
        );
        $this->assertInstanceOf(AIAction::class, $action);

        $entries = $this->mock->journal()->recv('calling.ai');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame(['text' => 'You are helpful.'], $p['prompt'] ?? null);
        $this->assertSame('ai-ctl', $p['control_id'] ?? null);
    }

    #[Test]
    public function aiStopJournalsAiStop(): void
    {
        $call = $this->answeredInboundCall('call-ai-stop');
        $action = $call->ai(
            ['text' => 'You are helpful.'],
            ['control_id' => 'ai-stop'],
        );
        $action->stop();
        $stops = $this->mock->journal()->recv('calling.ai.stop');
        $this->assertNotEmpty($stops);
        $this->assertSame(
            'ai-stop',
            $stops[count($stops) - 1]->frame['params']['control_id'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // General — control_id correlation across multiple concurrent actions
    // ------------------------------------------------------------------

    #[Test]
    public function concurrentPlayAndRecordRouteIndependently(): void
    {
        $call = $this->answeredInboundCall('call-multi');
        $playAction = $call->play(
            [['type' => 'silence', 'params' => ['duration' => 60]]],
            ['control_id' => 'ctl-play-x'],
        );
        $recordAction = $call->record(
            ['format' => 'wav'],
            ['control_id' => 'ctl-rec-y'],
        );
        $this->assertSame('ctl-play-x', $playAction->getControlId());
        $this->assertSame('ctl-rec-y', $recordAction->getControlId());

        // Push a finished event for ONLY the play.
        $this->mock->push([
            'jsonrpc' => '2.0',
            'id'      => bin2hex(random_bytes(8)),
            'method'  => 'signalwire.event',
            'params'  => [
                'event_type' => 'calling.call.play',
                'params'     => [
                    'call_id'    => 'call-multi',
                    'control_id' => 'ctl-play-x',
                    'state'      => 'finished',
                ],
            ],
        ]);
        $playAction->wait(2);
        $this->assertTrue($playAction->isDone());
        $this->assertFalse($recordAction->isDone());

        $this->assertNotEmpty($this->mock->journal()->recv('calling.play'));
        $this->assertNotEmpty($this->mock->journal()->recv('calling.record'));
    }
}
