<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\Relay\Call;
use SignalWire\Relay\Client as RelayClient;
use SignalWire\Relay\Constants;
use SignalWire\Relay\Event\RelayEvent;
use SignalWire\Relay\RelayError;
use SignalWire\Tests\Support\Shape;

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
     * Push an inbound call, wait for the handler to capture it, then force
     * the SDK side to "answered" so subsequent action sends fire. Mirrors
     * ActionsMockTest::answeredInboundCall.
     */
    private function answeredInboundCall(string $callId): Call
    {
        /** @var \ArrayObject<string, Call> $captured */
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
        /** @var \ArrayObject<string, Call> $captured */
        $captured = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($captured): void {
            $captured['call'] = $call;
        });

        $this->mock->inboundCall(['call_id' => $callId, 'auto_states' => ['created']]);
        $arrived = MockTest::pumpUntil(
            $this->client,
            fn () => isset($captured['call']),
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
        // Return type is PlayAction, so an instanceof assertion is redundant.
        $call->playTts('Hello there', [
            'language'   => 'en-US',
            'gender'     => 'female',
            'voice'      => 'spore',
            'volume'     => 3.5,
            'control_id' => 'ptts-ctl',
        ]);

        $entries = $this->mock->journal()->recv('calling.play');
        $this->assertCount(1, $entries);
        $p = Shape::sub($entries[0]->frame, 'params');
        $this->assertSame('call-ptts', $p['call_id'] ?? null);
        $this->assertSame('ptts-ctl', $p['control_id'] ?? null);
        // Media: a single tts frame with text + optional voice params nested.
        $this->assertCount(1, Shape::sub($p, 'play'));
        $media0 = Shape::sub($p, 'play', 0);
        $this->assertSame('tts', $media0['type'] ?? null);
        $mparams = Shape::sub($media0, 'params');
        $this->assertSame('Hello there', $mparams['text'] ?? null);
        $this->assertSame('en-US', $mparams['language'] ?? null);
        $this->assertSame('female', $mparams['gender'] ?? null);
        $this->assertSame('spore', $mparams['voice'] ?? null);
        // volume rides at the top level, NOT inside the tts params.
        $volume = $p['volume'] ?? 0;
        $this->assertEqualsWithDelta(3.5, Shape::num($volume), 0.0001);
        $this->assertArrayNotHasKey('volume', $mparams);
    }

    #[Test]
    public function playTtsOmitsUnsuppliedVoiceParams(): void
    {
        $call = $this->answeredInboundCall('call-ptts-min');
        $call->playTts('Just text', ['control_id' => 'ptts-min']);

        $entries = $this->mock->journal()->recv('calling.play');
        $this->assertCount(1, $entries);
        $params = Shape::sub($entries[0]->frame, 'params', 'play', 0, 'params');
        $this->assertSame('Just text', $params['text'] ?? null);
        // Only-provided-keys: language/gender/voice absent when not passed.
        $this->assertArrayNotHasKey('language', $params);
        $this->assertArrayNotHasKey('gender', $params);
        $this->assertArrayNotHasKey('voice', $params);
        // No volume sibling when not supplied.
        $this->assertArrayNotHasKey('volume', Shape::sub($entries[0]->frame, 'params'));
    }

    // ------------------------------------------------------------------
    // play_audio — [{type:"audio", params:{url}}]
    // ------------------------------------------------------------------

    #[Test]
    public function playAudioJournalsAudioMediaShape(): void
    {
        $call = $this->answeredInboundCall('call-paud');
        // Return type is PlayAction; instanceof would be redundant.
        $call->playAudio('https://cdn.example/clip.mp3', [
            'volume'     => -6.0,
            'control_id' => 'paud-ctl',
        ]);

        $entries = $this->mock->journal()->recv('calling.play');
        $this->assertCount(1, $entries);
        $p = Shape::sub($entries[0]->frame, 'params');
        $this->assertSame('paud-ctl', $p['control_id'] ?? null);
        $media0 = Shape::sub($p, 'play', 0);
        $this->assertSame('audio', $media0['type'] ?? null);
        $this->assertSame(
            'https://cdn.example/clip.mp3',
            Shape::at($media0, 'params', 'url'),
        );
        $volume = $p['volume'] ?? 0;
        $this->assertEqualsWithDelta(-6.0, Shape::num($volume), 0.0001);
    }

    // ------------------------------------------------------------------
    // play_silence — [{type:"silence", params:{duration}}]
    // ------------------------------------------------------------------

    #[Test]
    public function playSilenceJournalsSilenceMediaShape(): void
    {
        $call = $this->answeredInboundCall('call-psil');
        // The reference takes exactly one option, `on_completed`; a caller who
        // omits it gets null (no completion handler), NOT an invented default.
        // Return type is PlayAction; instanceof would be redundant.
        $call->playSilence(2.5);

        $entries = $this->mock->journal()->recv('calling.play');
        $this->assertCount(1, $entries);
        $p = Shape::sub($entries[0]->frame, 'params');
        $media0 = Shape::sub($p, 'play', 0);
        $this->assertSame('silence', $media0['type'] ?? null);
        $duration = Shape::at($media0, 'params', 'duration');
        $this->assertEqualsWithDelta(2.5, Shape::num($duration), 0.0001);
    }

    // ------------------------------------------------------------------
    // play_ringtone — [{type:"ringtone", params:{name, duration?}}]
    // ------------------------------------------------------------------

    #[Test]
    public function playRingtoneJournalsRingtoneMediaShape(): void
    {
        $call = $this->answeredInboundCall('call-prng');
        // Return type is PlayAction; instanceof would be redundant.
        $call->playRingtone('us', [
            'duration'   => 4,
            'volume'     => 1.0,
            'control_id' => 'prng-ctl',
        ]);

        $entries = $this->mock->journal()->recv('calling.play');
        $this->assertCount(1, $entries);
        $p = Shape::sub($entries[0]->frame, 'params');
        $media0 = Shape::sub($p, 'play', 0);
        $this->assertSame('ringtone', $media0['type'] ?? null);
        $mparams = Shape::sub($media0, 'params');
        $this->assertSame('us', $mparams['name'] ?? null);
        $this->assertSame(4, $mparams['duration'] ?? null);
        $volume = $p['volume'] ?? 0;
        $this->assertEqualsWithDelta(1.0, Shape::num($volume), 0.0001);
    }

    #[Test]
    public function playRingtoneOmitsDurationWhenUnsupplied(): void
    {
        $call = $this->answeredInboundCall('call-prng-min');
        $call->playRingtone('de', ['control_id' => 'prng-min']);

        $entries = $this->mock->journal()->recv('calling.play');
        $this->assertCount(1, $entries);
        $params = Shape::sub($entries[0]->frame, 'params', 'play', 0, 'params');
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
        // Return type is DetectAction; instanceof would be redundant.
        $call->detectDigit([
            'digits'     => '123#',
            'timeout'    => 12.0,
            'control_id' => 'ddig-ctl',
        ]);

        $entries = $this->mock->journal()->recv('calling.detect');
        $this->assertCount(1, $entries);
        $p = Shape::sub($entries[0]->frame, 'params');
        $detect = Shape::sub($p, 'detect');
        $this->assertSame('digit', $detect['type'] ?? null);
        $dparams = Shape::sub($detect, 'params');
        $this->assertSame('123#', $dparams['digits'] ?? null);
        // timeout is a SIBLING of detect, not nested inside it.
        $timeout = $p['timeout'] ?? 0;
        $this->assertEqualsWithDelta(12.0, Shape::num($timeout), 0.0001);
        $this->assertArrayNotHasKey('timeout', $dparams);
    }

    #[Test]
    public function detectDigitOmitsDigitsWhenUnsupplied(): void
    {
        $call = $this->answeredInboundCall('call-ddig-min');
        $call->detectDigit(['control_id' => 'ddig-min']);

        $entries = $this->mock->journal()->recv('calling.detect');
        $this->assertCount(1, $entries);
        $detect = Shape::sub($entries[0]->frame, 'params', 'detect');
        $this->assertSame('digit', $detect['type'] ?? null);
        // Empty params object when no digits supplied (server applies default).
        $this->assertArrayNotHasKey('digits', Shape::sub($detect, 'params'));
    }

    // ------------------------------------------------------------------
    // detect_answering_machine — detect {type:"machine", params:{only-provided}}
    // ------------------------------------------------------------------

    #[Test]
    public function detectAnsweringMachineJournalsMachineDetectShape(): void
    {
        $call = $this->answeredInboundCall('call-damd');
        // Return type is DetectAction; instanceof would be redundant.
        $call->detectAnsweringMachine([
            'initial_timeout'         => 5.0,
            'end_silence_timeout'     => 1.5,
            'machine_voice_threshold' => 1.25,
            'machine_words_threshold' => 6,
            'detect_interruptions'    => true,
            'detect_message_end'      => false,
            'timeout'                 => 30.0,
            'control_id'              => 'damd-ctl',
        ]);

        $entries = $this->mock->journal()->recv('calling.detect');
        $this->assertCount(1, $entries);
        $p = Shape::sub($entries[0]->frame, 'params');
        $detect = Shape::sub($p, 'detect');
        $this->assertSame('machine', $detect['type'] ?? null);
        $mp = Shape::sub($detect, 'params');
        $initialTimeout = $mp['initial_timeout'] ?? 0;
        $this->assertEqualsWithDelta(5.0, Shape::num($initialTimeout), 0.0001);
        $endSilence = $mp['end_silence_timeout'] ?? 0;
        $this->assertEqualsWithDelta(1.5, Shape::num($endSilence), 0.0001);
        $voiceThreshold = $mp['machine_voice_threshold'] ?? 0;
        $this->assertEqualsWithDelta(1.25, Shape::num($voiceThreshold), 0.0001);
        $this->assertSame(6, $mp['machine_words_threshold'] ?? null);
        $this->assertTrue($mp['detect_interruptions'] ?? null);
        $this->assertFalse($mp['detect_message_end'] ?? null);
        // timeout rides at the top level (sibling of detect).
        $timeout = $p['timeout'] ?? 0;
        $this->assertEqualsWithDelta(30.0, Shape::num($timeout), 0.0001);
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
        $mp = Shape::sub($entries[0]->frame, 'params', 'detect', 'params');
        // Only the supplied key is present; the rest are server defaults.
        // (JSON may round-trip 7.0 as int 7 — compare key set + value
        // numerically rather than with a strict float identity.)
        $this->assertSame(['initial_timeout'], array_keys($mp));
        $initialTimeout = $mp['initial_timeout'];
        $this->assertIsNumeric($initialTimeout);
        $this->assertEqualsWithDelta(7.0, (float) $initialTimeout, 0.0001);
    }

    // ------------------------------------------------------------------
    // detect_fax — detect {type:"fax", params:{tone?}}, timeout sibling
    // ------------------------------------------------------------------

    #[Test]
    public function detectFaxJournalsFaxDetectShape(): void
    {
        $call = $this->answeredInboundCall('call-dfax');
        // Return type is DetectAction; instanceof would be redundant.
        $call->detectFax([
            'tone'       => 'CED',
            'timeout'    => 20.0,
            'control_id' => 'dfax-ctl',
        ]);

        $entries = $this->mock->journal()->recv('calling.detect');
        $this->assertCount(1, $entries);
        $p = Shape::sub($entries[0]->frame, 'params');
        $detect = Shape::sub($p, 'detect');
        $this->assertSame('fax', $detect['type'] ?? null);
        $dparams = Shape::sub($detect, 'params');
        $this->assertSame('CED', $dparams['tone'] ?? null);
        $timeout = $p['timeout'] ?? 0;
        $this->assertEqualsWithDelta(20.0, Shape::num($timeout), 0.0001);
        $this->assertArrayNotHasKey('timeout', $dparams);
    }

    #[Test]
    public function detectFaxOmitsToneWhenUnsupplied(): void
    {
        $call = $this->answeredInboundCall('call-dfax-min');
        $call->detectFax(['control_id' => 'dfax-min']);

        $entries = $this->mock->journal()->recv('calling.detect');
        $this->assertCount(1, $entries);
        $detect = Shape::sub($entries[0]->frame, 'params', 'detect');
        $this->assertSame('fax', $detect['type'] ?? null);
        $this->assertArrayNotHasKey('tone', Shape::sub($detect, 'params'));
    }

    // ------------------------------------------------------------------
    // prompt_tts — play_and_collect [{type:"tts",...}] + collect
    // ------------------------------------------------------------------

    #[Test]
    public function promptTtsJournalsTtsMediaAndCollect(): void
    {
        $call = $this->answeredInboundCall('call-prtts');
        // Return type is CollectAction; instanceof would be redundant.
        $call->promptTts(
            'Press a digit',
            ['digits' => ['max' => 1]],
            [
                'language'   => 'en-US',
                'voice'      => 'spore',
                'volume'     => 2.0,
                'control_id' => 'prtts-ctl',
            ],
        );

        $entries = $this->mock->journal()->recv('calling.play_and_collect');
        $this->assertCount(1, $entries);
        $p = Shape::sub($entries[0]->frame, 'params');
        $this->assertSame('prtts-ctl', $p['control_id'] ?? null);
        // Typed media built like play_tts.
        $media0 = Shape::sub($p, 'play', 0);
        $this->assertSame('tts', $media0['type'] ?? null);
        $mparams = Shape::sub($media0, 'params');
        $this->assertSame('Press a digit', $mparams['text'] ?? null);
        $this->assertSame('en-US', $mparams['language'] ?? null);
        $this->assertSame('spore', $mparams['voice'] ?? null);
        // Caller's collect spec forwarded verbatim.
        $this->assertSame(1, Shape::at($p, 'collect', 'digits', 'max'));
        // volume sibling, not inside the media params.
        $volume = $p['volume'] ?? 0;
        $this->assertEqualsWithDelta(2.0, Shape::num($volume), 0.0001);
        $this->assertArrayNotHasKey('volume', $mparams);
    }

    // ------------------------------------------------------------------
    // prompt_audio — play_and_collect [{type:"audio",...}] + collect
    // ------------------------------------------------------------------

    #[Test]
    public function promptAudioJournalsAudioMediaAndCollect(): void
    {
        $call = $this->answeredInboundCall('call-praud');
        // Return type is CollectAction; instanceof would be redundant.
        $call->promptAudio(
            'https://cdn.example/prompt.wav',
            ['speech' => ['end_silence_timeout' => 1.0]],
            ['volume' => -2.0, 'control_id' => 'praud-ctl'],
        );

        $entries = $this->mock->journal()->recv('calling.play_and_collect');
        $this->assertCount(1, $entries);
        $p = Shape::sub($entries[0]->frame, 'params');
        $media0 = Shape::sub($p, 'play', 0);
        $this->assertSame('audio', $media0['type'] ?? null);
        $this->assertSame(
            'https://cdn.example/prompt.wav',
            Shape::at($media0, 'params', 'url'),
        );
        $endSilence = Shape::at($p, 'collect', 'speech', 'end_silence_timeout');
        $this->assertEqualsWithDelta(1.0, Shape::num($endSilence), 0.0001);
        $volume = $p['volume'] ?? 0;
        $this->assertEqualsWithDelta(-2.0, Shape::num($volume), 0.0001);
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
        $event = $call->waitForAnswered(5.0);
        $after = count($this->mock->journal()->recv());
        $this->assertSame($before, $after, 'wait_for_answered must not send a wire frame');

        // The short-circuit returns the reference's synthetic state snapshot:
        // RelayEvent(event_type=EVENT_CALL_STATE, params={"call_state": state}).
        $this->assertSame('calling.call.state', $event->getEventType());
        $this->assertSame(
            Constants::CALL_STATE_ANSWERED,
            $event->getParams()['call_state'] ?? null,
        );
    }

    #[Test]
    public function waitForRingingShortCircuitsWhenAlreadyPastRinging(): void
    {
        // 'answered' is PAST 'ringing' in the lifecycle ordering, so
        // wait_for_ringing returns true immediately.
        $call = $this->answeredInboundCall('call-wfr-past');
        $this->assertSame(Constants::CALL_STATE_ANSWERED, $call->state);
        $event = $call->waitForRinging(5.0);
        // The snapshot carries the CURRENT state (answered), not the target —
        // matching the reference's `params={"call_state": self.state}`.
        $this->assertSame('calling.call.state', $event->getEventType());
        $this->assertSame(
            Constants::CALL_STATE_ANSWERED,
            $event->getParams()['call_state'] ?? null,
        );
    }

    #[Test]
    public function waitForRingingResolvesOnPushedStateEvent(): void
    {
        $call = $this->createdInboundCall('call-wfr-fwd');
        $this->assertSame(Constants::CALL_STATE_CREATED, $call->state);

        // Queue a ringing state event; wait_for_ringing pumps readOnce()
        // internally, drains it, and resolves true.
        $this->mock->push(self::stateFrame('call-wfr-fwd', 'ringing'));
        $event = $call->waitForRinging(5.0);
        $this->assertSame(Constants::CALL_STATE_RINGING, $call->state);

        // The returned event is the REAL wire event that satisfied the wait,
        // projected onto RelayEvent — not a snapshot and not a bool.
        $this->assertSame('calling.call.state', $event->getEventType());
        $this->assertSame('ringing', $event->getParams()['call_state'] ?? null);
        $this->assertSame('call-wfr-fwd', $event->getCallId());
    }

    #[Test]
    public function waitForAnsweredResolvesOnPushedStateEvent(): void
    {
        $call = $this->createdInboundCall('call-wfa-fwd');
        $this->mock->push(self::stateFrame('call-wfa-fwd', 'ringing'));
        $this->mock->push(self::stateFrame('call-wfa-fwd', 'answered'));
        $event = $call->waitForAnswered(5.0);
        $this->assertSame(Constants::CALL_STATE_ANSWERED, $call->state);
        // The ringing frame arrived FIRST but does not satisfy an answered
        // wait; the returned event must be the answered one.
        $this->assertSame('answered', $event->getParams()['call_state'] ?? null);
        $this->assertSame('call-wfa-fwd', $event->getCallId());
    }

    #[Test]
    public function waitForEndingResolvesOnPushedStateEvent(): void
    {
        $call = $this->createdInboundCall('call-wfe-fwd');
        $this->mock->push(self::stateFrame('call-wfe-fwd', 'ending'));
        $event = $call->waitForEnding(5.0);
        $this->assertSame(Constants::CALL_STATE_ENDING, $call->state);
        $this->assertSame('ending', $event->getParams()['call_state'] ?? null);
    }

    #[Test]
    public function waitForAnsweredThrowsOnTimeout(): void
    {
        $call = $this->createdInboundCall('call-wfa-to');
        // No state event is pushed; the call stays 'created'. The short
        // timeout elapses and the wait RAISES — the reference awaits
        // asyncio.wait_for(), which throws TimeoutError rather than resolving
        // to None, which is why its annotation is a non-optional RelayEvent.
        try {
            $call->waitForAnswered(0.3);
            $this->fail('waitForAnswered must throw when the timeout elapses');
        } catch (RelayError $e) {
            // 408 is the port's client-side-timeout sentinel (Client::dial).
            $this->assertSame(408, $e->relayCode);
            $this->assertStringContainsString('timed out', $e->relayMessage);
        }
        $this->assertSame(Constants::CALL_STATE_CREATED, $call->state);
    }

    #[Test]
    public function waitForEndedResolvesOnPushedStateEvent(): void
    {
        $call = $this->createdInboundCall('call-wfd-fwd');
        // wait_for_ended pumps readOnce() until the terminal ended state.
        $this->mock->push(self::stateFrame('call-wfd-fwd', 'ended'));
        $event = $call->waitForEnded(5.0);
        $this->assertSame(Constants::CALL_STATE_ENDED, $call->state);
        $this->assertSame('ended', $event->getParams()['call_state'] ?? null);
        $this->assertSame('call-wfd-fwd', $event->getCallId());
    }

    #[Test]
    public function waitForEndedThrowsOnTimeout(): void
    {
        $call = $this->createdInboundCall('call-wfd-to');
        try {
            $call->waitForEnded(0.3);
            $this->fail('waitForEnded must throw when the timeout elapses');
        } catch (RelayError $e) {
            $this->assertSame(408, $e->relayCode);
        }
        $this->assertSame(Constants::CALL_STATE_CREATED, $call->state);
    }

    #[Test]
    public function waitForResolvesOnMatchingEvent(): void
    {
        $call = $this->createdInboundCall('call-wf-evt');
        // wait_for(eventType) captures the first matching event, pumping
        // readOnce() internally until it arrives.
        $this->mock->push(self::stateFrame('call-wf-evt', 'ringing'));
        $event = $call->waitFor('calling.call.state', null, 5.0);
        // Returns the reference's typed RelayEvent, projected from the raw
        // dispatch Event at the boundary — same four fields the reference's
        // RelayEvent.from_payload extracts.
        $this->assertSame('calling.call.state', $event->getEventType());
        $this->assertSame('ringing', $event->getParams()['call_state'] ?? null);
        $this->assertSame('call-wf-evt', $event->getCallId());
    }

    #[Test]
    public function waitForHonoursPredicate(): void
    {
        $call = $this->createdInboundCall('call-wf-pred');
        // Push ringing then answered; the predicate only matches answered.
        $this->mock->push(self::stateFrame('call-wf-pred', 'ringing'));
        $this->mock->push(self::stateFrame('call-wf-pred', 'answered'));
        $seen = [];
        $event = $call->waitFor(
            'calling.call.state',
            function (RelayEvent $e) use (&$seen): bool {
                // The predicate receives the TYPED RelayEvent, matching the
                // reference's `Callable[[RelayEvent], bool]`.
                $seen[] = $e->getParams()['call_state'] ?? null;
                return ($e->getParams()['call_state'] ?? null) === 'answered';
            },
            5.0,
        );
        $this->assertSame('answered', $event->getParams()['call_state'] ?? null);
        // The non-matching ringing event was offered to the predicate and
        // skipped, proving the filter runs rather than the first event winning.
        $this->assertSame(['ringing', 'answered'], $seen);
    }

    #[Test]
    public function waitForThrowsOnTimeout(): void
    {
        $call = $this->createdInboundCall('call-wf-to');
        // No matching event pushed; the short timeout elapses -> RelayError.
        // The reference's asyncio.wait_for raises TimeoutError here; it never
        // resolves to None, hence the non-optional RelayEvent annotation.
        try {
            $call->waitFor('calling.call.state', null, 0.3);
            $this->fail('waitFor must throw when the timeout elapses');
        } catch (RelayError $e) {
            $this->assertSame(408, $e->relayCode);
            $this->assertStringContainsString(
                'wait_for(calling.call.state)',
                $e->relayMessage,
            );
        }
    }
}
