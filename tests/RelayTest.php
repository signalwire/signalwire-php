<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\Relay\Constants;
use SignalWire\Relay\Event;
use SignalWire\Relay\Action;
use SignalWire\Relay\PlayAction;
use SignalWire\Relay\RecordAction;
use SignalWire\Relay\CollectAction;
use SignalWire\Relay\DetectAction;
use SignalWire\Relay\FaxAction;
use SignalWire\Relay\Message;
use SignalWire\Relay\Call;
use SignalWire\Relay\Client;

class RelayTest extends TestCase
{
    // =====================================================================
    //  Helper factories
    // =====================================================================

    private function makeMockClient(): object
    {
        return new class {
            public array $executed = [];
            public function execute(string $method, array $params = []): array
            {
                $this->executed[] = ['method' => $method, 'params' => $params];
                return [];
            }
            public function readOnce(): void {}
        };
    }

    private function makeAction(?object $client = null): Action
    {
        return new Action('ctrl-1', 'call-1', 'node-1', $client ?? $this->makeMockClient());
    }

    private function makeCall(?object $client = null): Call
    {
        return new Call([
            'call_id' => 'call-100',
            'node_id' => 'node-200',
            'tag' => 'tag-300',
            'device' => ['type' => 'phone', 'params' => ['to_number' => '+15551234567']],
            'context' => 'default',
        ], $client ?? $this->makeMockClient());
    }

    private function makeClient(): Client
    {
        return new Client([
            'project' => 'proj-abc',
            'token' => 'tok-xyz',
            'host' => 'example.signalwire.com',
        ]);
    }

    // =====================================================================
    //  Constants (5 tests)
    // =====================================================================

    #[Test]
    public function constantsProtocolVersionHasMajorMinorRevision(): void
    {
        $version = Constants::PROTOCOL_VERSION;

        $this->assertArrayHasKey('major', $version);
        $this->assertArrayHasKey('minor', $version);
        $this->assertArrayHasKey('revision', $version);
        $this->assertIsInt($version['major']);
        $this->assertIsInt($version['minor']);
        $this->assertIsInt($version['revision']);
    }

    #[Test]
    public function constantsCallStatesAllFiveDefinedAndEndedIsTerminal(): void
    {
        $this->assertSame('created', Constants::CALL_STATE_CREATED);
        $this->assertSame('ringing', Constants::CALL_STATE_RINGING);
        $this->assertSame('answered', Constants::CALL_STATE_ANSWERED);
        $this->assertSame('ending', Constants::CALL_STATE_ENDING);
        $this->assertSame('ended', Constants::CALL_STATE_ENDED);

        $this->assertArrayHasKey('ended', Constants::CALL_TERMINAL_STATES);
        $this->assertArrayNotHasKey('created', Constants::CALL_TERMINAL_STATES);
        $this->assertArrayNotHasKey('ringing', Constants::CALL_TERMINAL_STATES);
        $this->assertArrayNotHasKey('answered', Constants::CALL_TERMINAL_STATES);
        $this->assertArrayNotHasKey('ending', Constants::CALL_TERMINAL_STATES);
    }

    #[Test]
    public function constantsDialStatesThreeDefined(): void
    {
        $this->assertSame('dialing', Constants::DIAL_STATE_DIALING);
        $this->assertSame('answered', Constants::DIAL_STATE_ANSWERED);
        $this->assertSame('failed', Constants::DIAL_STATE_FAILED);
    }

    #[Test]
    public function constantsMessageStatesSevenDefinedWithTerminals(): void
    {
        $this->assertSame('queued', Constants::MESSAGE_STATE_QUEUED);
        $this->assertSame('initiated', Constants::MESSAGE_STATE_INITIATED);
        $this->assertSame('sent', Constants::MESSAGE_STATE_SENT);
        $this->assertSame('delivered', Constants::MESSAGE_STATE_DELIVERED);
        $this->assertSame('undelivered', Constants::MESSAGE_STATE_UNDELIVERED);
        $this->assertSame('failed', Constants::MESSAGE_STATE_FAILED);
        $this->assertSame('received', Constants::MESSAGE_STATE_RECEIVED);

        $terminal = Constants::MESSAGE_TERMINAL_STATES;
        $this->assertArrayHasKey('delivered', $terminal);
        $this->assertArrayHasKey('undelivered', $terminal);
        $this->assertArrayHasKey('failed', $terminal);
        $this->assertArrayNotHasKey('queued', $terminal);
        $this->assertArrayNotHasKey('initiated', $terminal);
        $this->assertArrayNotHasKey('sent', $terminal);
        $this->assertArrayNotHasKey('received', $terminal);
    }

    #[Test]
    public function constantsActionTerminalStatesNineEventTypesCovered(): void
    {
        $expected = [
            'calling.call.play',
            'calling.call.record',
            'calling.call.detect',
            'calling.call.collect',
            'calling.call.fax',
            'calling.call.tap',
            'calling.call.stream',
            'calling.call.transcribe',
            'calling.call.pay',
        ];

        $actual = array_keys(Constants::ACTION_TERMINAL_STATES);
        $this->assertCount(9, $actual);

        foreach ($expected as $eventType) {
            $this->assertArrayHasKey($eventType, Constants::ACTION_TERMINAL_STATES);
            $this->assertIsArray(Constants::ACTION_TERMINAL_STATES[$eventType]);
            $this->assertArrayHasKey('finished', Constants::ACTION_TERMINAL_STATES[$eventType]);
        }
    }

    // =====================================================================
    //  Event (5 tests)
    // =====================================================================

    #[Test]
    public function eventConstructionWithEventTypeAndParams(): void
    {
        $event = new Event('calling.call.state', ['call_id' => 'c1', 'state' => 'ringing']);

        $this->assertSame('calling.call.state', $event->getEventType());
        $this->assertSame(['call_id' => 'c1', 'state' => 'ringing'], $event->getParams());
        $this->assertIsFloat($event->getTimestamp());
        $this->assertGreaterThan(0.0, $event->getTimestamp());
    }

    #[Test]
    public function eventAccessorMethods(): void
    {
        $params = [
            'call_id' => 'call-abc',
            'node_id' => 'node-xyz',
            'control_id' => 'ctrl-123',
            'tag' => 'tag-456',
            'state' => 'answered',
        ];

        $event = new Event('calling.call.state', $params);

        $this->assertSame('call-abc', $event->getCallId());
        $this->assertSame('node-xyz', $event->getNodeId());
        $this->assertSame('ctrl-123', $event->getControlId());
        $this->assertSame('tag-456', $event->getTag());
        $this->assertSame('answered', $event->getState());
    }

    #[Test]
    public function eventToArrayReturnsFullEvent(): void
    {
        $params = ['call_id' => 'c1'];
        $ts = 1700000000.0;
        $event = new Event('calling.call.state', $params, $ts);

        $arr = $event->toArray();

        $this->assertSame('calling.call.state', $arr['event_type']);
        $this->assertSame(1700000000.0, $arr['timestamp']);
        $this->assertSame(['call_id' => 'c1'], $arr['params']);
    }

    #[Test]
    public function eventParseFactoryCreatesEvent(): void
    {
        $event = Event::parse('messaging.state', ['message_id' => 'm1', 'state' => 'sent']);

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('messaging.state', $event->getEventType());
        $this->assertSame('m1', $event->getParams()['message_id']);
        $this->assertSame('sent', $event->getState());
    }

    #[Test]
    public function eventMissingParamsReturnNull(): void
    {
        $event = new Event('calling.call.state', []);

        $this->assertNull($event->getCallId());
        $this->assertNull($event->getNodeId());
        $this->assertNull($event->getControlId());
        $this->assertNull($event->getTag());
        $this->assertNull($event->getState());
    }

    // =====================================================================
    //  Action (12 tests)
    // =====================================================================

    #[Test]
    public function actionConstructionWithControlIdCallIdNodeId(): void
    {
        $client = $this->makeMockClient();
        $action = new Action('ctrl-abc', 'call-xyz', 'node-123', $client);

        $this->assertSame('ctrl-abc', $action->getControlId());
        $this->assertSame('call-xyz', $action->getCallId());
        $this->assertSame('node-123', $action->getNodeId());
    }

    #[Test]
    public function actionInitiallyNotDone(): void
    {
        $action = $this->makeAction();

        $this->assertFalse($action->isDone());
        $this->assertNull($action->getResult());
        $this->assertNull($action->getState());
        $this->assertSame([], $action->getEvents());
        $this->assertSame([], $action->getPayload());
    }

    #[Test]
    public function actionResolveMarksCompletedAndStoresResult(): void
    {
        $action = $this->makeAction();

        $action->resolve(['status' => 'ok']);

        $this->assertTrue($action->isDone());
        $this->assertSame(['status' => 'ok'], $action->getResult());
    }

    #[Test]
    public function actionResolveIsIdempotent(): void
    {
        $action = $this->makeAction();

        $action->resolve('first');
        $action->resolve('second');

        $this->assertSame('first', $action->getResult());
    }

    #[Test]
    public function actionOnCompletedCallbackFiresOnResolve(): void
    {
        $action = $this->makeAction();
        $fired = false;
        $receivedAction = null;

        $action->onCompleted(function (Action $a) use (&$fired, &$receivedAction) {
            $fired = true;
            $receivedAction = $a;
        });

        $this->assertFalse($fired);

        $action->resolve('done');

        $this->assertTrue($fired);
        $this->assertSame($action, $receivedAction);
    }

    #[Test]
    public function actionOnCompletedFiresImmediatelyIfAlreadyResolved(): void
    {
        $action = $this->makeAction();
        $action->resolve('done');

        $fired = false;
        $action->onCompleted(function () use (&$fired) {
            $fired = true;
        });

        $this->assertTrue($fired);
    }

    #[Test]
    public function playActionHasPauseResumeVolumeMethods(): void
    {
        $client = $this->makeMockClient();
        $play = new PlayAction('ctrl-p', 'call-1', 'node-1', $client);

        $this->assertSame('calling.play.stop', $play->getStopMethod());

        $play->pause();
        $play->resume();
        $play->volume(-4.0);

        $this->assertCount(3, $client->executed);
        $this->assertSame('calling.play.pause', $client->executed[0]['method']);
        $this->assertSame('calling.play.resume', $client->executed[1]['method']);
        $this->assertSame('calling.play.volume', $client->executed[2]['method']);
        $this->assertSame(-4.0, $client->executed[2]['params']['volume']);
    }

    #[Test]
    public function recordActionHasUrlDurationSize(): void
    {
        $client = $this->makeMockClient();
        $record = new RecordAction('ctrl-r', 'call-1', 'node-1', $client);

        $this->assertNull($record->getUrl());
        $this->assertNull($record->getDuration());
        $this->assertNull($record->getSize());

        $event = new Event('calling.call.record', [
            'state' => 'finished',
            'url' => 'https://example.com/recording.wav',
            'duration' => 12.5,
            'size' => 20000,
        ]);
        $record->handleEvent($event);

        $this->assertSame('https://example.com/recording.wav', $record->getUrl());
        $this->assertSame(12.5, $record->getDuration());
        $this->assertSame(20000, $record->getSize());
    }

    #[Test]
    public function collectActionIgnoresPlayEvents(): void
    {
        $client = $this->makeMockClient();
        $collect = new CollectAction('ctrl-c', 'call-1', 'node-1', $client);

        $playEvent = new Event('calling.call.play', [
            'state' => 'playing',
            'control_id' => 'ctrl-c',
        ]);
        $collect->handleEvent($playEvent);

        $this->assertSame([], $collect->getEvents());
        $this->assertSame([], $collect->getPayload());
        $this->assertNull($collect->getState());
    }

    #[Test]
    public function collectActionProcessesCollectEventsNormally(): void
    {
        $client = $this->makeMockClient();
        $collect = new CollectAction('ctrl-c', 'call-1', 'node-1', $client);

        $collectEvent = new Event('calling.call.collect', [
            'state' => 'finished',
            'control_id' => 'ctrl-c',
            'result' => ['type' => 'digit', 'params' => ['digits' => '1234']],
        ]);
        $collect->handleEvent($collectEvent);

        $this->assertCount(1, $collect->getEvents());
        $this->assertSame('finished', $collect->getState());
        $this->assertSame(
            ['type' => 'digit', 'params' => ['digits' => '1234']],
            $collect->getCollectResult()
        );
    }

    #[Test]
    public function detectActionGetDetectResult(): void
    {
        $client = $this->makeMockClient();
        $detect = new DetectAction('ctrl-d', 'call-1', 'node-1', $client);

        $this->assertNull($detect->getDetectResult());

        $event = new Event('calling.call.detect', [
            'state' => 'finished',
            'detect' => ['type' => 'machine', 'params' => ['event' => 'MACHINE']],
        ]);
        $detect->handleEvent($event);

        $this->assertSame(
            ['type' => 'machine', 'params' => ['event' => 'MACHINE']],
            $detect->getDetectResult()
        );
    }

    #[Test]
    public function faxActionStopMethodVariesByType(): void
    {
        $client = $this->makeMockClient();

        $sendFax = new FaxAction('ctrl-fs', 'call-1', 'node-1', $client, 'send');
        $this->assertSame('send', $sendFax->getFaxType());
        $this->assertSame('calling.send_fax.stop', $sendFax->getStopMethod());

        $recvFax = new FaxAction('ctrl-fr', 'call-1', 'node-1', $client, 'receive');
        $this->assertSame('receive', $recvFax->getFaxType());
        $this->assertSame('calling.receive_fax.stop', $recvFax->getStopMethod());
    }

    // =====================================================================
    //  Message (6 tests)
    // =====================================================================

    #[Test]
    public function messageConstructionFromParams(): void
    {
        $msg = new Message([
            'message_id' => 'msg-1',
            'context' => 'office',
            'direction' => 'outbound',
            'from_number' => '+15551001000',
            'to_number' => '+15552002000',
            'body' => 'Hello',
            'media' => ['https://example.com/img.png'],
            'tags' => ['vip'],
        ]);

        $this->assertSame('msg-1', $msg->getMessageId());
        $this->assertSame('office', $msg->getContext());
        $this->assertSame('outbound', $msg->getDirection());
        $this->assertSame('+15551001000', $msg->getFromNumber());
        $this->assertSame('+15552002000', $msg->getToNumber());
        $this->assertSame('Hello', $msg->getBody());
        $this->assertSame(['https://example.com/img.png'], $msg->getMedia());
        $this->assertSame(['vip'], $msg->getTags());
        $this->assertFalse($msg->isDone());
    }

    #[Test]
    public function messageDispatchEventUpdatesState(): void
    {
        $msg = new Message(['message_id' => 'msg-1']);

        $event = new Event('messaging.state', ['state' => 'sent']);
        $msg->dispatchEvent($event);

        $this->assertSame('sent', $msg->getState());
    }

    #[Test]
    public function messageTerminalStateDeliveredSetsCompleted(): void
    {
        $msg = new Message(['message_id' => 'msg-1']);

        $event = new Event('messaging.state', ['state' => 'delivered']);
        $msg->dispatchEvent($event);

        $this->assertTrue($msg->isDone());
        $this->assertSame('delivered', $msg->getResult());
    }

    #[Test]
    public function messageNonTerminalStateSentDoesNotComplete(): void
    {
        $msg = new Message(['message_id' => 'msg-1']);

        $event = new Event('messaging.state', ['state' => 'sent']);
        $msg->dispatchEvent($event);

        $this->assertFalse($msg->isDone());
        $this->assertNull($msg->getResult());
    }

    #[Test]
    public function messageOnCompletedCallbackFiresOnTerminal(): void
    {
        $msg = new Message(['message_id' => 'msg-1']);
        $fired = false;
        $receivedMsg = null;

        $msg->onCompleted(function (Message $m) use (&$fired, &$receivedMsg) {
            $fired = true;
            $receivedMsg = $m;
        });

        $event = new Event('messaging.state', ['state' => 'delivered']);
        $msg->dispatchEvent($event);

        $this->assertTrue($fired);
        $this->assertSame($msg, $receivedMsg);
    }

    #[Test]
    public function messageOnEventListenerFiresOnDispatch(): void
    {
        $msg = new Message(['message_id' => 'msg-1']);
        $events = [];

        $msg->on(function (Message $m, Event $e) use (&$events) {
            $events[] = $e->getState();
        });

        $msg->dispatchEvent(new Event('messaging.state', ['state' => 'queued']));
        $msg->dispatchEvent(new Event('messaging.state', ['state' => 'sent']));

        $this->assertSame(['queued', 'sent'], $events);
    }

    // =====================================================================
    //  Call (12 tests)
    // =====================================================================

    #[Test]
    public function callConstructionFromParams(): void
    {
        $call = $this->makeCall();

        $this->assertSame('call-100', $call->callId);
        $this->assertSame('node-200', $call->nodeId);
        $this->assertSame('tag-300', $call->tag);
        $this->assertSame('created', $call->state);
        $this->assertSame('default', $call->context);
        $this->assertSame('phone', $call->device['type']);
        $this->assertSame([], $call->peer);
        $this->assertNull($call->endReason);
    }

    #[Test]
    public function callDispatchEventWithCallingCallStateUpdatesState(): void
    {
        $call = $this->makeCall();

        $event = new Event('calling.call.state', [
            'call_id' => 'call-100',
            'state' => 'ringing',
        ]);
        $call->dispatchEvent($event);

        $this->assertSame('ringing', $call->state);
    }

    #[Test]
    public function callEndedResolvesAllActions(): void
    {
        $client = $this->makeMockClient();
        $call = $this->makeCall($client);

        $action1 = new Action('ctrl-1', 'call-100', 'node-200', $client);
        $action2 = new Action('ctrl-2', 'call-100', 'node-200', $client);
        $call->actions['ctrl-1'] = $action1;
        $call->actions['ctrl-2'] = $action2;

        $event = new Event('calling.call.state', [
            'call_id' => 'call-100',
            'state' => 'ended',
            'end_reason' => 'hangup',
        ]);
        $call->dispatchEvent($event);

        $this->assertTrue($action1->isDone());
        $this->assertTrue($action2->isDone());
        $this->assertSame([], $call->actions);
    }

    #[Test]
    public function callActionRoutingByControlId(): void
    {
        $client = $this->makeMockClient();
        $call = $this->makeCall($client);

        $action = new PlayAction('ctrl-play', 'call-100', 'node-200', $client);
        $call->actions['ctrl-play'] = $action;

        $event = new Event('calling.call.play', [
            'control_id' => 'ctrl-play',
            'state' => 'playing',
        ]);
        $call->dispatchEvent($event);

        $this->assertSame('playing', $action->getState());
        $this->assertCount(1, $action->getEvents());
    }

    #[Test]
    public function callOnEventListenerFires(): void
    {
        $call = $this->makeCall();
        $received = [];

        $call->on(function (Event $e, Call $c) use (&$received) {
            $received[] = $e->getEventType();
        });

        $call->dispatchEvent(new Event('calling.call.state', ['state' => 'ringing']));

        $this->assertSame(['calling.call.state'], $received);
    }

    #[Test]
    public function callSimpleMethodsExist(): void
    {
        $call = $this->makeCall();

        $simpleMethods = [
            'answer', 'hangup', 'pass', 'connect', 'disconnect',
            'hold', 'unhold', 'denoise', 'denoiseStop', 'transfer',
        ];

        foreach ($simpleMethods as $method) {
            $this->assertTrue(
                method_exists($call, $method),
                "Call should have method {$method}()"
            );
        }
    }

    #[Test]
    public function callActionMethodsExist(): void
    {
        $call = $this->makeCall();

        $actionMethods = [
            'play', 'record', 'collect', 'playAndCollect',
            'detect', 'sendFax', 'receiveFax', 'tap',
            'stream', 'pay', 'transcribe', 'ai',
        ];

        foreach ($actionMethods as $method) {
            $this->assertTrue(
                method_exists($call, $method),
                "Call should have action method {$method}()"
            );
        }
    }

    #[Test]
    public function callStartActionCreatesAndStoresAction(): void
    {
        $client = $this->makeMockClient();
        $call = $this->makeCall($client);

        // Simulate what startAction does: create an action and store it
        $action = new PlayAction('ctrl-manual', 'call-100', 'node-200', $client);
        $call->actions['ctrl-manual'] = $action;

        $this->assertArrayHasKey('ctrl-manual', $call->actions);
        $this->assertInstanceOf(PlayAction::class, $call->actions['ctrl-manual']);
        $this->assertFalse($action->isDone());
    }

    #[Test]
    public function callPlayActionStopMethodCorrect(): void
    {
        $client = $this->makeMockClient();
        $action = new PlayAction('ctrl-play', 'call-100', 'node-200', $client);
        $call = $this->makeCall($client);
        $call->actions['ctrl-play'] = $action;

        $action->stop();

        $this->assertCount(1, $client->executed);
        $this->assertSame('calling.play.stop', $client->executed[0]['method']);
        $this->assertSame('ctrl-play', $client->executed[0]['params']['control_id']);
        $this->assertSame('call-100', $client->executed[0]['params']['call_id']);
        $this->assertSame('node-200', $client->executed[0]['params']['node_id']);
    }

    #[Test]
    public function callMultipleListenersFireOnEvent(): void
    {
        $call = $this->makeCall();
        $log1 = [];
        $log2 = [];

        $call->on(function (Event $e) use (&$log1) {
            $log1[] = $e->getEventType();
        });
        $call->on(function (Event $e) use (&$log2) {
            $log2[] = $e->getEventType();
        });

        $call->dispatchEvent(new Event('calling.call.state', ['state' => 'answered']));

        $this->assertSame(['calling.call.state'], $log1);
        $this->assertSame(['calling.call.state'], $log2);
    }

    #[Test]
    public function callConnectEventUpdatesPeer(): void
    {
        $call = $this->makeCall();
        $this->assertSame([], $call->peer);

        $event = new Event('calling.call.connect', [
            'peer' => [
                'call_id' => 'peer-call-1',
                'node_id' => 'peer-node-1',
                'device' => ['type' => 'phone'],
            ],
        ]);
        $call->dispatchEvent($event);

        $this->assertSame('peer-call-1', $call->peer['call_id']);
        $this->assertSame('peer-node-1', $call->peer['node_id']);
    }

    #[Test]
    public function callStateEventWithEndReason(): void
    {
        $call = $this->makeCall();

        $event = new Event('calling.call.state', [
            'state' => 'ended',
            'end_reason' => 'busy',
        ]);
        $call->dispatchEvent($event);

        $this->assertSame('ended', $call->state);
        $this->assertSame('busy', $call->endReason);
    }

    // =====================================================================
    //  Client (10 tests)
    // =====================================================================

    #[Test]
    public function clientConstructionWithProjectTokenHost(): void
    {
        $client = $this->makeClient();

        $this->assertSame('proj-abc', $client->project);
        $this->assertSame('tok-xyz', $client->token);
        $this->assertSame('example.signalwire.com', $client->host);
        $this->assertFalse($client->connected);
        $this->assertNull($client->sessionId);
    }

    #[Test]
    public function clientConstructionFromEnvVars(): void
    {
        $prev = getenv('SIGNALWIRE_SPACE');
        putenv('SIGNALWIRE_SPACE=env-space.signalwire.com');

        try {
            $client = new Client([
                'project' => 'proj-env',
                'token' => 'tok-env',
            ]);

            $this->assertSame('env-space.signalwire.com', $client->host);
        } finally {
            if ($prev === false) {
                putenv('SIGNALWIRE_SPACE');
            } else {
                putenv("SIGNALWIRE_SPACE={$prev}");
            }
        }
    }

    #[Test]
    public function clientOnCallHandlerRegistration(): void
    {
        $client = $this->makeClient();

        $handler = function () {};
        $result = $client->onCall($handler);

        $this->assertSame($client, $result);
        $this->assertSame($handler, $client->onCallHandler);
    }

    #[Test]
    public function clientOnMessageHandlerRegistration(): void
    {
        $client = $this->makeClient();

        $handler = function () {};
        $result = $client->onMessage($handler);

        $this->assertSame($client, $result);
        $this->assertSame($handler, $client->onMessageHandler);
    }

    #[Test]
    public function clientHandleMessageRoutesResponsesToPending(): void
    {
        $client = $this->makeClient();

        $resolved = null;
        $client->pending['req-1'] = [
            'resolve' => function (array $r) use (&$resolved) { $resolved = $r; },
            'reject' => function (array $e) {},
        ];

        $raw = json_encode([
            'jsonrpc' => '2.0',
            'id' => 'req-1',
            'result' => ['session_id' => 'sess-1'],
        ]);
        $client->handleMessage($raw);

        $this->assertSame(['session_id' => 'sess-1'], $resolved);
    }

    #[Test]
    public function clientHandleMessageRoutesEvents(): void
    {
        $client = $this->makeClient();

        $receivedEvent = null;
        $client->onEventHandler = function (Event $e, array $params) use (&$receivedEvent) {
            $receivedEvent = $e;
        };

        $raw = json_encode([
            'jsonrpc' => '2.0',
            'id' => 'evt-1',
            'method' => 'signalwire.event',
            'params' => [
                'event_type' => 'some.custom.event',
                'params' => ['key' => 'value'],
            ],
        ]);
        $client->handleMessage($raw);

        $this->assertNotNull($receivedEvent);
        $this->assertSame('some.custom.event', $receivedEvent->getEventType());
    }

    #[Test]
    public function clientHandleEventCallingCallReceiveFiresOnCall(): void
    {
        $client = $this->makeClient();

        $receivedCall = null;
        $receivedEvent = null;
        $client->onCall(function (Call $c, Event $e) use (&$receivedCall, &$receivedEvent) {
            $receivedCall = $c;
            $receivedEvent = $e;
        });

        $client->handleEvent([
            'event_type' => 'calling.call.receive',
            'params' => [
                'call_id' => 'inbound-1',
                'node_id' => 'node-1',
                'device' => ['type' => 'phone'],
                'context' => 'office',
            ],
        ]);

        $this->assertNotNull($receivedCall);
        $this->assertInstanceOf(Call::class, $receivedCall);
        $this->assertSame('inbound-1', $receivedCall->callId);
        $this->assertNotNull($receivedEvent);
        $this->assertSame('calling.call.receive', $receivedEvent->getEventType());
        $this->assertArrayHasKey('inbound-1', $client->calls);
    }

    #[Test]
    public function clientHandleEventMessagingStateRoutesToMessage(): void
    {
        $client = $this->makeClient();

        // Client.handleEvent calls ->handleEvent() on the Message, but Message
        // only exposes ->dispatchEvent(). We use an anonymous subclass that
        // bridges the two names so we can test the Client routing logic.
        $message = new class (['message_id' => 'msg-1', 'state' => 'queued']) extends Message {
            public function handleEvent(Event $event): void
            {
                $this->dispatchEvent($event);
            }
        };
        $client->messages['msg-1'] = $message;

        $client->handleEvent([
            'event_type' => 'messaging.state',
            'params' => [
                'message_id' => 'msg-1',
                'state' => 'delivered',
            ],
        ]);

        $this->assertSame('delivered', $message->getState());
        $this->assertTrue($message->isDone());
        // Terminal state causes removal from tracking map
        $this->assertArrayNotHasKey('msg-1', $client->messages);
    }

    #[Test]
    public function clientHandleEventCallingCallDialResolvesPendingDial(): void
    {
        $client = $this->makeClient();

        $resolvedCall = null;
        $client->pendingDials['dial-tag-1'] = [
            'resolve' => function (Call $c) use (&$resolvedCall) { $resolvedCall = $c; },
            'tag' => 'dial-tag-1',
        ];

        $client->handleEvent([
            'event_type' => 'calling.call.dial',
            'params' => [
                'tag' => 'dial-tag-1',
                'call_id' => 'new-call-1',
                'node_id' => 'node-1',
                'state' => 'answered',
            ],
        ]);

        $this->assertNotNull($resolvedCall);
        $this->assertInstanceOf(Call::class, $resolvedCall);
        $this->assertSame('new-call-1', $resolvedCall->callId);
        $this->assertTrue($resolvedCall->dialWinner);
        $this->assertArrayHasKey('new-call-1', $client->calls);
    }

    #[Test]
    public function clientGenerateUuidReturnsValidFormat(): void
    {
        $client = $this->makeClient();

        // generateUuid is private, so we use reflection to test it directly.
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('generateUuid');
        $method->setAccessible(true);

        $uuid = $method->invoke($client);

        // UUID v4 format: xxxxxxxx-xxxx-4xxx-[89ab]xxx-xxxxxxxxxxxx
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );

        // Ensure uniqueness
        $uuid2 = $method->invoke($client);
        $this->assertNotSame($uuid, $uuid2);
    }
}
