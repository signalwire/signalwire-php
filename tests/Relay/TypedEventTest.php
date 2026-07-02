<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\Relay\Event\CallingErrorEvent;
use SignalWire\Relay\Event\CallReceiveEvent;
use SignalWire\Relay\Event\CallStateEvent;
use SignalWire\Relay\Event\CollectEvent;
use SignalWire\Relay\Event\ConferenceEvent;
use SignalWire\Relay\Event\ConnectEvent;
use SignalWire\Relay\Event\DenoiseEvent;
use SignalWire\Relay\Event\DetectEvent;
use SignalWire\Relay\Event\DialEvent;
use SignalWire\Relay\Event\EchoEvent;
use SignalWire\Relay\Event\FaxEvent;
use SignalWire\Relay\Event\HoldEvent;
use SignalWire\Relay\Event\MessageReceiveEvent;
use SignalWire\Relay\Event\MessageStateEvent;
use SignalWire\Relay\Event\PayEvent;
use SignalWire\Relay\Event\PlayEvent;
use SignalWire\Relay\Event\QueueEvent;
use SignalWire\Relay\Event\RecordEvent;
use SignalWire\Relay\Event\ReferEvent;
use SignalWire\Relay\Event\RelayEvent;
use SignalWire\Relay\Event\SendDigitsEvent;
use SignalWire\Relay\Event\StreamEvent;
use SignalWire\Relay\Event\TapEvent;
use SignalWire\Relay\Event\TranscribeEvent;
use SignalWire\Tests\Support\Shape;

/**
 * Tests for the typed RELAY event hierarchy (signalwire.relay.event).
 *
 * Ported from signalwire-python/tests/unit/relay/test_event.py and mirrors the
 * TypeScript RelayEvent.ts shape oracle. Every test feeds a representative raw
 * wire payload through `fromPayload` / `parseEvent` and asserts the typed
 * accessors return the parsed values — real parsing behaviour, not
 * construction-only asserts.
 */
final class TypedEventTest extends TestCase
{
    #[Test]
    public function baseRelayEventFromPayload(): void
    {
        $event = RelayEvent::fromPayload([
            'event_type' => 'calling.call.state',
            'params'     => ['call_id' => 'abc-123', 'call_state' => 'answered', 'timestamp' => 12.5],
        ]);
        $this->assertSame('calling.call.state', $event->getEventType());
        $this->assertSame('abc-123', $event->getCallId());
        $this->assertSame(12.5, $event->getTimestamp());
        $this->assertSame('answered', $event->getParams()['call_state']);
    }

    #[Test]
    public function baseRelayEventFromEmptyPayload(): void
    {
        $event = RelayEvent::fromPayload([]);
        $this->assertSame('', $event->getEventType());
        $this->assertSame('', $event->getCallId());
        $this->assertSame([], $event->getParams());
        $this->assertSame(0.0, $event->getTimestamp());
    }

    #[Test]
    public function callStateEvent(): void
    {
        $event = CallStateEvent::fromPayload([
            'event_type' => 'calling.call.state',
            'params'     => [
                'call_id'    => 'c1',
                'call_state' => 'ended',
                'end_reason' => 'hangup',
                'direction'  => 'inbound',
                'device'     => ['type' => 'phone', 'params' => ['from_number' => '+15551234567']],
            ],
        ]);
        $this->assertSame('ended', $event->getCallState());
        $this->assertSame('hangup', $event->getEndReason());
        $this->assertSame('inbound', $event->getDirection());
        $this->assertSame('phone', $event->getDevice()['type']);
    }

    #[Test]
    public function callReceiveEvent(): void
    {
        $event = CallReceiveEvent::fromPayload([
            'event_type' => 'calling.call.receive',
            'params'     => [
                'call_id'    => 'c1',
                'node_id'    => 'n1',
                'project_id' => 'p1',
                'call_state' => 'created',
                'context'    => 'office',
                'device'     => ['type' => 'sip'],
                'tag'        => 't1',
                'segment_id' => 's1',
            ],
        ]);
        $this->assertSame('n1', $event->getNodeId());
        $this->assertSame('p1', $event->getProjectId());
        $this->assertSame('office', $event->getContext());
        $this->assertSame('t1', $event->getTag());
        $this->assertSame('s1', $event->getSegmentId());
        $this->assertSame('created', $event->getCallState());
        $this->assertSame('sip', $event->getDevice()['type']);
    }

    #[Test]
    public function callReceiveEventContextFallsBackToProtocol(): void
    {
        $event = CallReceiveEvent::fromPayload([
            'event_type' => 'calling.call.receive',
            'params'     => ['call_id' => 'c1', 'protocol' => 'support'],
        ]);
        $this->assertSame('support', $event->getContext());
    }

    #[Test]
    public function playEvent(): void
    {
        $event = PlayEvent::fromPayload([
            'event_type' => 'calling.call.play',
            'params'     => ['call_id' => 'c1', 'control_id' => 'ctl1', 'state' => 'playing'],
        ]);
        $this->assertSame('ctl1', $event->getControlId());
        $this->assertSame('playing', $event->getState());
    }

    #[Test]
    public function recordEvent(): void
    {
        $event = RecordEvent::fromPayload([
            'event_type' => 'calling.call.record',
            'params'     => [
                'call_id'    => 'c1',
                'control_id' => 'ctl1',
                'state'      => 'finished',
                'url'        => 'https://example.com/rec.mp3',
                'duration'   => 15.5,
                'size'       => 128000,
                'record'     => ['audio' => ['format' => 'mp3']],
            ],
        ]);
        $this->assertSame('finished', $event->getState());
        $this->assertSame('https://example.com/rec.mp3', $event->getUrl());
        $this->assertSame(15.5, $event->getDuration());
        $this->assertSame(128000, $event->getSize());
        $this->assertSame(['format' => 'mp3'], $event->getRecord()['audio']);
    }

    #[Test]
    public function recordEventUrlFromNestedRecord(): void
    {
        $event = RecordEvent::fromPayload([
            'event_type' => 'calling.call.record',
            'params'     => [
                'call_id'    => 'c1',
                'control_id' => 'ctl1',
                'state'      => 'finished',
                'record'     => ['url' => 'https://nested.com/rec.mp3', 'duration' => 10.0, 'size' => 5000],
            ],
        ]);
        $this->assertSame('https://nested.com/rec.mp3', $event->getUrl());
        $this->assertSame(10.0, $event->getDuration());
        $this->assertSame(5000, $event->getSize());
    }

    #[Test]
    public function collectEvent(): void
    {
        $event = CollectEvent::fromPayload([
            'event_type' => 'calling.call.collect',
            'params'     => [
                'call_id'    => 'c1',
                'control_id' => 'ctl1',
                'state'      => 'finished',
                'result'     => ['type' => 'digit', 'params' => ['digits' => '1234', 'terminator' => '#']],
                'final'      => true,
            ],
        ]);
        $this->assertSame('digit', $event->getResult()['type']);
        $this->assertTrue($event->getFinal());
        $this->assertSame('finished', $event->getState());
    }

    #[Test]
    public function collectEventFinalNullWhenAbsent(): void
    {
        $event = CollectEvent::fromPayload([
            'event_type' => 'calling.call.collect',
            'params'     => ['call_id' => 'c1', 'control_id' => 'ctl1'],
        ]);
        $this->assertNull($event->getFinal());
    }

    #[Test]
    public function connectEvent(): void
    {
        $event = ConnectEvent::fromPayload([
            'event_type' => 'calling.call.connect',
            'params'     => [
                'call_id'       => 'c1',
                'connect_state' => 'connected',
                'peer'          => ['call_id' => 'c2', 'node_id' => 'n2'],
            ],
        ]);
        $this->assertSame('connected', $event->getConnectState());
        $this->assertSame('c2', $event->getPeer()['call_id']);
    }

    #[Test]
    public function detectEvent(): void
    {
        $event = DetectEvent::fromPayload([
            'event_type' => 'calling.call.detect',
            'params'     => [
                'call_id'    => 'c1',
                'control_id' => 'ctl1',
                'detect'     => ['type' => 'machine', 'params' => ['event' => 'HUMAN']],
            ],
        ]);
        $this->assertSame('machine', $event->getDetect()['type']);
        $this->assertSame('ctl1', $event->getControlId());
    }

    #[Test]
    public function faxEvent(): void
    {
        $event = FaxEvent::fromPayload([
            'event_type' => 'calling.call.fax',
            'params'     => [
                'call_id'    => 'c1',
                'control_id' => 'ctl1',
                'fax'        => ['type' => 'page', 'params' => ['direction' => 'send', 'number' => 1]],
            ],
        ]);
        $this->assertSame('page', $event->getFax()['type']);
        $this->assertSame('ctl1', $event->getControlId());
    }

    #[Test]
    public function tapEvent(): void
    {
        $event = TapEvent::fromPayload([
            'event_type' => 'calling.call.tap',
            'params'     => [
                'call_id'    => 'c1',
                'control_id' => 'ctl1',
                'state'      => 'tapping',
                'tap'        => ['type' => 'audio', 'params' => ['direction' => 'speak']],
                'device'     => ['type' => 'rtp', 'params' => ['addr' => '1.2.3.4', 'port' => 1234]],
            ],
        ]);
        $this->assertSame('tapping', $event->getState());
        $this->assertSame('audio', $event->getTap()['type']);
        $this->assertSame('1.2.3.4', Shape::at($event->getDevice(), 'params', 'addr'));
    }

    #[Test]
    public function streamEvent(): void
    {
        $event = StreamEvent::fromPayload([
            'event_type' => 'calling.call.stream',
            'params'     => [
                'call_id'    => 'c1',
                'control_id' => 'ctl1',
                'state'      => 'streaming',
                'url'        => 'wss://example.com/audio',
                'name'       => 'my_stream',
            ],
        ]);
        $this->assertSame('streaming', $event->getState());
        $this->assertSame('wss://example.com/audio', $event->getUrl());
        $this->assertSame('my_stream', $event->getName());
    }

    #[Test]
    public function sendDigitsEvent(): void
    {
        $event = SendDigitsEvent::fromPayload([
            'event_type' => 'calling.call.send_digits',
            'params'     => ['call_id' => 'c1', 'control_id' => 'ctl1', 'state' => 'finished'],
        ]);
        $this->assertSame('finished', $event->getState());
        $this->assertSame('ctl1', $event->getControlId());
    }

    #[Test]
    public function dialEvent(): void
    {
        $event = DialEvent::fromPayload([
            'event_type' => 'calling.call.dial',
            'params'     => [
                'tag'        => 't1',
                'dial_state' => 'answered',
                'call'       => ['call_id' => 'c1', 'node_id' => 'n1', 'dial_winner' => true],
            ],
        ]);
        $this->assertSame('t1', $event->getTag());
        $this->assertSame('answered', $event->getDialState());
        $this->assertTrue($event->getCall()['dial_winner']);
    }

    #[Test]
    public function referEvent(): void
    {
        $event = ReferEvent::fromPayload([
            'event_type' => 'calling.call.refer',
            'params'     => [
                'call_id'                  => 'c1',
                'state'                    => 'success',
                'sip_refer_to'             => 'sip:user@example.com',
                'sip_refer_response_code'  => '202',
                'sip_notify_response_code' => '200',
            ],
        ]);
        $this->assertSame('success', $event->getState());
        $this->assertSame('sip:user@example.com', $event->getSipReferTo());
        $this->assertSame('202', $event->getSipReferResponseCode());
        $this->assertSame('200', $event->getSipNotifyResponseCode());
    }

    #[Test]
    public function denoiseEvent(): void
    {
        $event = DenoiseEvent::fromPayload([
            'event_type' => 'calling.call.denoise',
            'params'     => ['call_id' => 'c1', 'denoised' => true],
        ]);
        $this->assertTrue($event->getDenoised());
    }

    #[Test]
    public function denoiseEventDefaultFalse(): void
    {
        $event = DenoiseEvent::fromPayload([
            'event_type' => 'calling.call.denoise',
            'params'     => ['call_id' => 'c1'],
        ]);
        $this->assertFalse($event->getDenoised());
    }

    #[Test]
    public function payEvent(): void
    {
        $event = PayEvent::fromPayload([
            'event_type' => 'calling.call.pay',
            'params'     => ['call_id' => 'c1', 'control_id' => 'ctl1', 'state' => 'finished'],
        ]);
        $this->assertSame('finished', $event->getState());
        $this->assertSame('ctl1', $event->getControlId());
    }

    #[Test]
    public function queueEvent(): void
    {
        $event = QueueEvent::fromPayload([
            'event_type' => 'calling.call.queue',
            'params'     => [
                'call_id'    => 'c1',
                'control_id' => 'ctl1',
                'status'     => 'enqueue',
                'id'         => 'q1',
                'name'       => 'support',
                'position'   => 3,
                'size'       => 10,
            ],
        ]);
        $this->assertSame('enqueue', $event->getStatus());
        $this->assertSame('q1', $event->getQueueId());
        $this->assertSame('support', $event->getQueueName());
        $this->assertSame(3, $event->getPosition());
        $this->assertSame(10, $event->getSize());
    }

    #[Test]
    public function echoEvent(): void
    {
        $event = EchoEvent::fromPayload([
            'event_type' => 'calling.call.echo',
            'params'     => ['call_id' => 'c1', 'state' => 'echoing'],
        ]);
        $this->assertSame('echoing', $event->getState());
    }

    #[Test]
    public function transcribeEvent(): void
    {
        $event = TranscribeEvent::fromPayload([
            'event_type' => 'calling.call.transcribe',
            'params'     => [
                'call_id'      => 'c1',
                'control_id'   => 'ctl1',
                'state'        => 'finished',
                'url'          => 'recordings/abc.wav',
                'recording_id' => 'r1',
                'duration'     => 30.0,
                'size'         => 123456,
            ],
        ]);
        $this->assertSame('finished', $event->getState());
        $this->assertSame('recordings/abc.wav', $event->getUrl());
        $this->assertSame('r1', $event->getRecordingId());
        $this->assertSame(30.0, $event->getDuration());
        $this->assertSame(123456, $event->getSize());
    }

    #[Test]
    public function holdEvent(): void
    {
        $event = HoldEvent::fromPayload([
            'event_type' => 'calling.call.hold',
            'params'     => ['call_id' => 'c1', 'state' => 'hold'],
        ]);
        $this->assertSame('hold', $event->getState());
    }

    #[Test]
    public function conferenceEvent(): void
    {
        $event = ConferenceEvent::fromPayload([
            'event_type' => 'calling.conference',
            'params'     => [
                'conference_id' => 'conf1',
                'name'          => 'my_conf',
                'status'        => 'conference-start',
            ],
        ]);
        $this->assertSame('conf1', $event->getConferenceId());
        $this->assertSame('my_conf', $event->getName());
        $this->assertSame('conference-start', $event->getStatus());
    }

    #[Test]
    public function callingErrorEvent(): void
    {
        $event = CallingErrorEvent::fromPayload([
            'event_type' => 'calling.error',
            'params'     => ['call_id' => 'c1', 'code' => '500', 'message' => 'Server error'],
        ]);
        $this->assertSame('500', $event->getCode());
        $this->assertSame('Server error', $event->getMessage());
    }

    #[Test]
    public function messageReceiveEventBasicInbound(): void
    {
        $event = MessageReceiveEvent::fromPayload([
            'event_type' => 'messaging.receive',
            'params'     => [
                'message_id'    => 'msg-rcv-1',
                'context'       => 'default',
                'direction'     => 'inbound',
                'from_number'   => '+15553333333',
                'to_number'     => '+15551111111',
                'body'          => 'Hi there',
                'media'         => [],
                'segments'      => 1,
                'message_state' => 'received',
                'tags'          => [],
            ],
        ]);
        $this->assertSame('messaging.receive', $event->getEventType());
        $this->assertSame('msg-rcv-1', $event->getMessageId());
        $this->assertSame('default', $event->getContext());
        $this->assertSame('inbound', $event->getDirection());
        $this->assertSame('+15553333333', $event->getFromNumber());
        $this->assertSame('+15551111111', $event->getToNumber());
        $this->assertSame('Hi there', $event->getBody());
        $this->assertSame(1, $event->getSegments());
        $this->assertSame('received', $event->getMessageState());
        $this->assertSame([], $event->getMedia());
        $this->assertSame([], $event->getTags());
    }

    #[Test]
    public function messageReceiveEventWithMediaAndTags(): void
    {
        $event = MessageReceiveEvent::fromPayload([
            'event_type' => 'messaging.receive',
            'params'     => [
                'message_id'    => 'msg-rcv-2',
                'context'       => 'support',
                'direction'     => 'inbound',
                'from_number'   => '+15553333333',
                'to_number'     => '+15551111111',
                'body'          => 'Check this out',
                'media'         => ['https://example.com/photo.jpg', 'https://example.com/doc.pdf'],
                'segments'      => 2,
                'message_state' => 'received',
                'tags'          => ['vip', 'support'],
            ],
        ]);
        $this->assertSame(
            ['https://example.com/photo.jpg', 'https://example.com/doc.pdf'],
            $event->getMedia(),
        );
        $this->assertSame(2, $event->getSegments());
        $this->assertSame(['vip', 'support'], $event->getTags());
        $this->assertSame('support', $event->getContext());
    }

    #[Test]
    public function messageReceiveEventEmptyParams(): void
    {
        $event = MessageReceiveEvent::fromPayload(['event_type' => 'messaging.receive', 'params' => []]);
        $this->assertSame('', $event->getMessageId());
        $this->assertSame('', $event->getBody());
        $this->assertSame([], $event->getMedia());
        $this->assertSame([], $event->getTags());
        $this->assertSame(0, $event->getSegments());
    }

    #[Test]
    public function messageStateEventOutboundDelivered(): void
    {
        $event = MessageStateEvent::fromPayload([
            'event_type' => 'messaging.state',
            'params'     => [
                'message_id'    => 'msg-st-1',
                'context'       => 'default',
                'direction'     => 'outbound',
                'from_number'   => '+15551111111',
                'to_number'     => '+15552222222',
                'body'          => 'Hello',
                'media'         => [],
                'segments'      => 1,
                'message_state' => 'delivered',
                'reason'        => '',
                'tags'          => [],
            ],
        ]);
        $this->assertSame('messaging.state', $event->getEventType());
        $this->assertSame('msg-st-1', $event->getMessageId());
        $this->assertSame('delivered', $event->getMessageState());
        $this->assertSame('outbound', $event->getDirection());
        $this->assertSame('+15551111111', $event->getFromNumber());
        $this->assertSame('+15552222222', $event->getToNumber());
        $this->assertSame('Hello', $event->getBody());
        $this->assertSame('', $event->getReason());
    }

    #[Test]
    public function messageStateEventFailedWithReason(): void
    {
        $event = MessageStateEvent::fromPayload([
            'event_type' => 'messaging.state',
            'params'     => [
                'message_id'    => 'msg-st-2',
                'from_number'   => '+15551111111',
                'to_number'     => '+15552222222',
                'message_state' => 'failed',
                'reason'        => 'spam',
                'tags'          => ['promo'],
            ],
        ]);
        $this->assertSame('failed', $event->getMessageState());
        $this->assertSame('spam', $event->getReason());
        $this->assertSame(['promo'], $event->getTags());
    }

    /**
     * The complete event-type → subclass dispatch table, mirroring the Python
     * EVENT_CLASS_MAP / TS EVENT_CLASS_MAP (the shape oracle) — 23 entries.
     *
     * @return array<string, array{0: string, 1: class-string<RelayEvent>}>
     */
    public static function eventTypeMap(): array
    {
        return [
            'calling.call.state'       => ['calling.call.state', CallStateEvent::class],
            'calling.call.receive'     => ['calling.call.receive', CallReceiveEvent::class],
            'calling.call.play'        => ['calling.call.play', PlayEvent::class],
            'calling.call.record'      => ['calling.call.record', RecordEvent::class],
            'calling.call.collect'     => ['calling.call.collect', CollectEvent::class],
            'calling.call.connect'     => ['calling.call.connect', ConnectEvent::class],
            'calling.call.detect'      => ['calling.call.detect', DetectEvent::class],
            'calling.call.fax'         => ['calling.call.fax', FaxEvent::class],
            'calling.call.tap'         => ['calling.call.tap', TapEvent::class],
            'calling.call.stream'      => ['calling.call.stream', StreamEvent::class],
            'calling.call.send_digits' => ['calling.call.send_digits', SendDigitsEvent::class],
            'calling.call.dial'        => ['calling.call.dial', DialEvent::class],
            'calling.call.refer'       => ['calling.call.refer', ReferEvent::class],
            'calling.call.denoise'     => ['calling.call.denoise', DenoiseEvent::class],
            'calling.call.pay'         => ['calling.call.pay', PayEvent::class],
            'calling.call.queue'       => ['calling.call.queue', QueueEvent::class],
            'calling.call.echo'        => ['calling.call.echo', EchoEvent::class],
            'calling.call.transcribe'  => ['calling.call.transcribe', TranscribeEvent::class],
            'calling.call.hold'        => ['calling.call.hold', HoldEvent::class],
            'calling.conference'       => ['calling.conference', ConferenceEvent::class],
            'calling.error'            => ['calling.error', CallingErrorEvent::class],
            'messaging.receive'        => ['messaging.receive', MessageReceiveEvent::class],
            'messaging.state'          => ['messaging.state', MessageStateEvent::class],
        ];
    }

    /**
     * @param class-string<RelayEvent> $expectedClass
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('eventTypeMap')]
    public function parseEventReturnsTypedSubclass(string $eventType, string $expectedClass): void
    {
        $event = RelayEvent::parseEvent(['event_type' => $eventType, 'params' => ['call_id' => 'c1']]);
        $this->assertInstanceOf($expectedClass, $event);
        $this->assertSame($eventType, $event->getEventType());
    }

    #[Test]
    public function parseEventUnknownReturnsBaseRelayEvent(): void
    {
        $event = RelayEvent::parseEvent([
            'event_type' => 'calling.call.unknown_future_event',
            'params'     => ['call_id' => 'c1'],
        ]);
        $this->assertSame(RelayEvent::class, $event::class);
        $this->assertSame('calling.call.unknown_future_event', $event->getEventType());
        $this->assertSame('c1', $event->getCallId());
    }

    #[Test]
    public function parseEventEmptyPayloadReturnsBase(): void
    {
        $event = RelayEvent::parseEvent([]);
        $this->assertSame(RelayEvent::class, $event::class);
        $this->assertSame('', $event->getEventType());
        $this->assertSame('', $event->getCallId());
    }

    #[Test]
    public function parseEventRoutesToCallStateWithFields(): void
    {
        $event = RelayEvent::parseEvent([
            'event_type' => 'calling.call.state',
            'params'     => ['call_id' => 'c1', 'call_state' => 'answered', 'direction' => 'inbound'],
        ]);
        $this->assertInstanceOf(CallStateEvent::class, $event);
        $this->assertSame('answered', $event->getCallState());
        $this->assertSame('inbound', $event->getDirection());
    }

    #[Test]
    public function parseEventRoutesToMessagingReceiveWithFields(): void
    {
        $event = RelayEvent::parseEvent([
            'event_type' => 'messaging.receive',
            'params'     => [
                'message_id'    => 'msg-pe-1',
                'from_number'   => '+15553333333',
                'to_number'     => '+15551111111',
                'body'          => 'Hello',
                'message_state' => 'received',
            ],
        ]);
        $this->assertInstanceOf(MessageReceiveEvent::class, $event);
        $this->assertSame('msg-pe-1', $event->getMessageId());
        $this->assertSame('Hello', $event->getBody());
        $this->assertSame('received', $event->getMessageState());
    }
}
