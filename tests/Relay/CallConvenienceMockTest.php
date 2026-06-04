<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\Relay\Call;
use SignalWire\Relay\Client as RelayClient;
use SignalWire\Relay\CollectAction;
use SignalWire\Relay\Constants;
use SignalWire\Relay\DetectAction;
use SignalWire\Relay\PlayAction;

/**
 * Real-mock-backed tests for Call's typed convenience methods:
 *   - play_tts / play_audio / play_silence / play_ringtone (typed media over play)
 *   - detect_digit / detect_answering_machine / detect_fax (typed detect)
 *   - prompt_tts / prompt_audio (typed media over play_and_collect)
 *   - wait_for_answered / wait_for_ringing / wait_for_ending (lifecycle waits)
 *
 * Ported from signalwire-python/signalwire/relay/call.py (Call.play_tts etc.)
 * and mirrors the parity tests in
 * signalwire-python/tests/unit/relay/test_actions_mock.py.
 *
 * Each play/detect/prompt test answers an inbound call (so we have a real
 * mock-backed Call), invokes the convenience method, and asserts the exact
 * RELAY media/detect WIRE SHAPE the verb hand-builds in the mock's journal —
 * NOT a transport mock. Each wait_for_* test asserts the short-circuit
 * (immediate when already at/past the target) and the forward-wait that
 * resolves off a mock-pushed calling.call.state event.
 */
class CallConvenienceMockTest extends TestCase
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
     * Push an inbound call, wait for the handler to capture it, then force
     * the SDK side to "answered" so subsequent action sends fire. Mirrors
     * ActionsMockTest::answeredInboundCall.
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
        $call->state = 'answered';
        return $call;
    }

    /**
     * Push an inbound call and return it WITHOUT advancing past 'created'
     * (no answer) — used by the wait_for_* forward-wait tests.
     */
    private function createdInboundCall(string $callId): Call
    {
        $captured = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($captured): void {
            $captured['call'] = $call;
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
        return $call;
    }

    /**
     * Build a signalwire.event(calling.call.state) frame the harness can
     * push at the SDK (same helper InboundCallMockTest uses).
     *
     * @return array<string,mixed>
     */
    private static function stateFrame(string $callId, string $callState): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => bin2hex(random_bytes(8)),
            'method'  => 'signalwire.event',
            'params'  => [
                'event_type' => 'calling.call.state',
                'params'     => [
                    'call_id'    => $callId,
                    'node_id'    => 'mock-relay-node-1',
                    'tag'        => '',
                    'call_state' => $callState,
                    'direction'  => 'inbound',
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // play_tts — [{type:"tts", params:{text, language?, gender?, voice?}}]
    // ------------------------------------------------------------------

    #[Test]
    public function playTtsJournalsTtsMediaShape(): void
    {
        $call = $this->answeredInboundCall('call-ptts');
        $action = $call->playTts('Hello there', [
            'language'   => 'en-US',
            'gender'     => 'female',
            'voice'      => 'spore',
            'volume'     => 3.5,
            'control_id' => 'ptts-ctl',
        ]);
        $this->assertInstanceOf(PlayAction::class, $action);

        $entries = $this->mock->journal()->recv('calling.play');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('call-ptts', $p['call_id'] ?? null);
        $this->assertSame('ptts-ctl', $p['control_id'] ?? null);
        // Media: a single tts frame with text + optional voice params nested.
        $this->assertCount(1, $p['play'] ?? []);
        $this->assertSame('tts', $p['play'][0]['type'] ?? null);
        $this->assertSame('Hello there', $p['play'][0]['params']['text'] ?? null);
        $this->assertSame('en-US', $p['play'][0]['params']['language'] ?? null);
        $this->assertSame('female', $p['play'][0]['params']['gender'] ?? null);
        $this->assertSame('spore', $p['play'][0]['params']['voice'] ?? null);
        // volume rides at the top level, NOT inside the tts params.
        $this->assertEqualsWithDelta(3.5, (float) ($p['volume'] ?? 0), 0.0001);
        $this->assertArrayNotHasKey('volume', $p['play'][0]['params']);
    }

    #[Test]
    public function playTtsOmitsUnsuppliedVoiceParams(): void
    {
        $call = $this->answeredInboundCall('call-ptts-min');
        $call->playTts('Just text', ['control_id' => 'ptts-min']);

        $entries = $this->mock->journal()->recv('calling.play');
        $this->assertCount(1, $entries);
        $params = $entries[0]->frame['params']['play'][0]['params'] ?? [];
        $this->assertSame('Just text', $params['text'] ?? null);
        // Only-provided-keys: language/gender/voice absent when not passed.
        $this->assertArrayNotHasKey('language', $params);
        $this->assertArrayNotHasKey('gender', $params);
        $this->assertArrayNotHasKey('voice', $params);
        // No volume sibling when not supplied.
        $this->assertArrayNotHasKey('volume', $entries[0]->frame['params']);
    }

    // ------------------------------------------------------------------
    // play_audio — [{type:"audio", params:{url}}]
    // ------------------------------------------------------------------

    #[Test]
    public function playAudioJournalsAudioMediaShape(): void
    {
        $call = $this->answeredInboundCall('call-paud');
        $action = $call->playAudio('https://cdn.example/clip.mp3', [
            'volume'     => -6.0,
            'control_id' => 'paud-ctl',
        ]);
        $this->assertInstanceOf(PlayAction::class, $action);

        $entries = $this->mock->journal()->recv('calling.play');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('paud-ctl', $p['control_id'] ?? null);
        $this->assertSame('audio', $p['play'][0]['type'] ?? null);
        $this->assertSame(
            'https://cdn.example/clip.mp3',
            $p['play'][0]['params']['url'] ?? null,
        );
        $this->assertEqualsWithDelta(-6.0, (float) ($p['volume'] ?? 0), 0.0001);
    }

    // ------------------------------------------------------------------
    // play_silence — [{type:"silence", params:{duration}}]
    // ------------------------------------------------------------------

    #[Test]
    public function playSilenceJournalsSilenceMediaShape(): void
    {
        $call = $this->answeredInboundCall('call-psil');
        $action = $call->playSilence(2.5, ['control_id' => 'psil-ctl']);
        $this->assertInstanceOf(PlayAction::class, $action);

        $entries = $this->mock->journal()->recv('calling.play');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('silence', $p['play'][0]['type'] ?? null);
        $this->assertEqualsWithDelta(
            2.5,
            (float) ($p['play'][0]['params']['duration'] ?? 0),
            0.0001,
        );
    }

    // ------------------------------------------------------------------
    // play_ringtone — [{type:"ringtone", params:{name, duration?}}]
    // ------------------------------------------------------------------

    #[Test]
    public function playRingtoneJournalsRingtoneMediaShape(): void
    {
        $call = $this->answeredInboundCall('call-prng');
        $action = $call->playRingtone('us', [
            'duration'   => 4,
            'volume'     => 1.0,
            'control_id' => 'prng-ctl',
        ]);
        $this->assertInstanceOf(PlayAction::class, $action);

        $entries = $this->mock->journal()->recv('calling.play');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('ringtone', $p['play'][0]['type'] ?? null);
        $this->assertSame('us', $p['play'][0]['params']['name'] ?? null);
        $this->assertSame(4, $p['play'][0]['params']['duration'] ?? null);
        $this->assertEqualsWithDelta(1.0, (float) ($p['volume'] ?? 0), 0.0001);
    }

    #[Test]
    public function playRingtoneOmitsDurationWhenUnsupplied(): void
    {
        $call = $this->answeredInboundCall('call-prng-min');
        $call->playRingtone('de', ['control_id' => 'prng-min']);

        $entries = $this->mock->journal()->recv('calling.play');
        $this->assertCount(1, $entries);
        $params = $entries[0]->frame['params']['play'][0]['params'] ?? [];
        $this->assertSame('de', $params['name'] ?? null);
        $this->assertArrayNotHasKey('duration', $params);
    }

    // ------------------------------------------------------------------
    // detect_digit — detect {type:"digit", params:{digits?}}, timeout sibling
    // ------------------------------------------------------------------

    #[Test]
    public function detectDigitJournalsDigitDetectShape(): void
    {
        $call = $this->answeredInboundCall('call-ddig');
        $action = $call->detectDigit([
            'digits'     => '123#',
            'timeout'    => 12.0,
            'control_id' => 'ddig-ctl',
        ]);
        $this->assertInstanceOf(DetectAction::class, $action);

        $entries = $this->mock->journal()->recv('calling.detect');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('digit', $p['detect']['type'] ?? null);
        $this->assertSame('123#', $p['detect']['params']['digits'] ?? null);
        // timeout is a SIBLING of detect, not nested inside it.
        $this->assertEqualsWithDelta(12.0, (float) ($p['timeout'] ?? 0), 0.0001);
        $this->assertArrayNotHasKey('timeout', $p['detect']['params']);
    }

    #[Test]
    public function detectDigitOmitsDigitsWhenUnsupplied(): void
    {
        $call = $this->answeredInboundCall('call-ddig-min');
        $call->detectDigit(['control_id' => 'ddig-min']);

        $entries = $this->mock->journal()->recv('calling.detect');
        $this->assertCount(1, $entries);
        $detect = $entries[0]->frame['params']['detect'] ?? [];
        $this->assertSame('digit', $detect['type'] ?? null);
        // Empty params object when no digits supplied (server applies default).
        $this->assertArrayNotHasKey('digits', $detect['params'] ?? []);
    }

    // ------------------------------------------------------------------
    // detect_answering_machine — detect {type:"machine", params:{only-provided}}
    // ------------------------------------------------------------------

    #[Test]
    public function detectAnsweringMachineJournalsMachineDetectShape(): void
    {
        $call = $this->answeredInboundCall('call-damd');
        $action = $call->detectAnsweringMachine([
            'initial_timeout'         => 5.0,
            'end_silence_timeout'     => 1.5,
            'machine_voice_threshold' => 1.25,
            'machine_words_threshold' => 6,
            'detect_interruptions'    => true,
            'detect_message_end'      => false,
            'timeout'                 => 30.0,
            'control_id'              => 'damd-ctl',
        ]);
        $this->assertInstanceOf(DetectAction::class, $action);

        $entries = $this->mock->journal()->recv('calling.detect');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('machine', $p['detect']['type'] ?? null);
        $mp = $p['detect']['params'] ?? [];
        $this->assertEqualsWithDelta(5.0, (float) ($mp['initial_timeout'] ?? 0), 0.0001);
        $this->assertEqualsWithDelta(1.5, (float) ($mp['end_silence_timeout'] ?? 0), 0.0001);
        $this->assertEqualsWithDelta(1.25, (float) ($mp['machine_voice_threshold'] ?? 0), 0.0001);
        $this->assertSame(6, $mp['machine_words_threshold'] ?? null);
        $this->assertTrue($mp['detect_interruptions'] ?? null);
        $this->assertFalse($mp['detect_message_end'] ?? null);
        // timeout rides at the top level (sibling of detect).
        $this->assertEqualsWithDelta(30.0, (float) ($p['timeout'] ?? 0), 0.0001);
        $this->assertArrayNotHasKey('timeout', $mp);
    }

    #[Test]
    public function detectAnsweringMachineEmitsOnlyProvidedKeys(): void
    {
        $call = $this->answeredInboundCall('call-damd-min');
        $call->detectAnsweringMachine([
            'initial_timeout' => 7.0,
            'control_id'      => 'damd-min',
        ]);

        $entries = $this->mock->journal()->recv('calling.detect');
        $this->assertCount(1, $entries);
        $mp = $entries[0]->frame['params']['detect']['params'] ?? [];
        // Only the supplied key is present; the rest are server defaults.
        // (JSON may round-trip 7.0 as int 7 — compare key set + value
        // numerically rather than with a strict float identity.)
        $this->assertSame(['initial_timeout'], array_keys($mp));
        $this->assertEqualsWithDelta(7.0, (float) $mp['initial_timeout'], 0.0001);
    }

    // ------------------------------------------------------------------
    // detect_fax — detect {type:"fax", params:{tone?}}, timeout sibling
    // ------------------------------------------------------------------

    #[Test]
    public function detectFaxJournalsFaxDetectShape(): void
    {
        $call = $this->answeredInboundCall('call-dfax');
        $action = $call->detectFax([
            'tone'       => 'CED',
            'timeout'    => 20.0,
            'control_id' => 'dfax-ctl',
        ]);
        $this->assertInstanceOf(DetectAction::class, $action);

        $entries = $this->mock->journal()->recv('calling.detect');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('fax', $p['detect']['type'] ?? null);
        $this->assertSame('CED', $p['detect']['params']['tone'] ?? null);
        $this->assertEqualsWithDelta(20.0, (float) ($p['timeout'] ?? 0), 0.0001);
        $this->assertArrayNotHasKey('timeout', $p['detect']['params']);
    }

    #[Test]
    public function detectFaxOmitsToneWhenUnsupplied(): void
    {
        $call = $this->answeredInboundCall('call-dfax-min');
        $call->detectFax(['control_id' => 'dfax-min']);

        $entries = $this->mock->journal()->recv('calling.detect');
        $this->assertCount(1, $entries);
        $detect = $entries[0]->frame['params']['detect'] ?? [];
        $this->assertSame('fax', $detect['type'] ?? null);
        $this->assertArrayNotHasKey('tone', $detect['params'] ?? []);
    }

    // ------------------------------------------------------------------
    // prompt_tts — play_and_collect [{type:"tts",...}] + collect
    // ------------------------------------------------------------------

    #[Test]
    public function promptTtsJournalsTtsMediaAndCollect(): void
    {
        $call = $this->answeredInboundCall('call-prtts');
        $action = $call->promptTts(
            'Press a digit',
            ['digits' => ['max' => 1]],
            [
                'language'   => 'en-US',
                'voice'      => 'spore',
                'volume'     => 2.0,
                'control_id' => 'prtts-ctl',
            ],
        );
        $this->assertInstanceOf(CollectAction::class, $action);

        $entries = $this->mock->journal()->recv('calling.play_and_collect');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('prtts-ctl', $p['control_id'] ?? null);
        // Typed media built like play_tts.
        $this->assertSame('tts', $p['play'][0]['type'] ?? null);
        $this->assertSame('Press a digit', $p['play'][0]['params']['text'] ?? null);
        $this->assertSame('en-US', $p['play'][0]['params']['language'] ?? null);
        $this->assertSame('spore', $p['play'][0]['params']['voice'] ?? null);
        // Caller's collect spec forwarded verbatim.
        $this->assertSame(1, $p['collect']['digits']['max'] ?? null);
        // volume sibling, not inside the media params.
        $this->assertEqualsWithDelta(2.0, (float) ($p['volume'] ?? 0), 0.0001);
        $this->assertArrayNotHasKey('volume', $p['play'][0]['params']);
    }

    // ------------------------------------------------------------------
    // prompt_audio — play_and_collect [{type:"audio",...}] + collect
    // ------------------------------------------------------------------

    #[Test]
    public function promptAudioJournalsAudioMediaAndCollect(): void
    {
        $call = $this->answeredInboundCall('call-praud');
        $action = $call->promptAudio(
            'https://cdn.example/prompt.wav',
            ['speech' => ['end_silence_timeout' => 1.0]],
            ['volume' => -2.0, 'control_id' => 'praud-ctl'],
        );
        $this->assertInstanceOf(CollectAction::class, $action);

        $entries = $this->mock->journal()->recv('calling.play_and_collect');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('audio', $p['play'][0]['type'] ?? null);
        $this->assertSame(
            'https://cdn.example/prompt.wav',
            $p['play'][0]['params']['url'] ?? null,
        );
        $this->assertEqualsWithDelta(
            1.0,
            (float) ($p['collect']['speech']['end_silence_timeout'] ?? 0),
            0.0001,
        );
        $this->assertEqualsWithDelta(-2.0, (float) ($p['volume'] ?? 0), 0.0001);
    }

    // ------------------------------------------------------------------
    // wait_for_answered / wait_for_ringing / wait_for_ending
    // ------------------------------------------------------------------

    #[Test]
    public function waitForAnsweredShortCircuitsWhenAlreadyAnswered(): void
    {
        $call = $this->answeredInboundCall('call-wfa-now');
        // Already 'answered' — must return immediately without touching the
        // wire (no calling.* frame is sent by a wait).
        $before = count($this->mock->journal()->recv());
        $this->assertTrue($call->waitForAnswered(5.0));
        $after = count($this->mock->journal()->recv());
        $this->assertSame($before, $after, 'wait_for_answered must not send a wire frame');
    }

    #[Test]
    public function waitForRingingShortCircuitsWhenAlreadyPastRinging(): void
    {
        // 'answered' is PAST 'ringing' in the lifecycle ordering, so
        // wait_for_ringing returns true immediately.
        $call = $this->answeredInboundCall('call-wfr-past');
        $this->assertSame(Constants::CALL_STATE_ANSWERED, $call->state);
        $this->assertTrue($call->waitForRinging(5.0));
    }

    #[Test]
    public function waitForRingingResolvesOnPushedStateEvent(): void
    {
        $call = $this->createdInboundCall('call-wfr-fwd');
        $this->assertSame(Constants::CALL_STATE_CREATED, $call->state);

        // Queue a ringing state event; wait_for_ringing pumps readOnce()
        // internally, drains it, and resolves true.
        $this->mock->push(self::stateFrame('call-wfr-fwd', 'ringing'));
        $this->assertTrue($call->waitForRinging(5.0));
        $this->assertSame(Constants::CALL_STATE_RINGING, $call->state);
    }

    #[Test]
    public function waitForAnsweredResolvesOnPushedStateEvent(): void
    {
        $call = $this->createdInboundCall('call-wfa-fwd');
        $this->mock->push(self::stateFrame('call-wfa-fwd', 'ringing'));
        $this->mock->push(self::stateFrame('call-wfa-fwd', 'answered'));
        $this->assertTrue($call->waitForAnswered(5.0));
        $this->assertSame(Constants::CALL_STATE_ANSWERED, $call->state);
    }

    #[Test]
    public function waitForEndingResolvesOnPushedStateEvent(): void
    {
        $call = $this->createdInboundCall('call-wfe-fwd');
        $this->mock->push(self::stateFrame('call-wfe-fwd', 'ending'));
        $this->assertTrue($call->waitForEnding(5.0));
        $this->assertSame(Constants::CALL_STATE_ENDING, $call->state);
    }

    #[Test]
    public function waitForAnsweredReturnsFalseOnTimeout(): void
    {
        $call = $this->createdInboundCall('call-wfa-to');
        // No state event is pushed; the call stays 'created'. The short
        // timeout elapses and the wait returns false.
        $this->assertFalse($call->waitForAnswered(0.3));
        $this->assertSame(Constants::CALL_STATE_CREATED, $call->state);
    }
}
