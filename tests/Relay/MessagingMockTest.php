<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\Relay\Client as RelayClient;
use SignalWire\Relay\Message;

/**
 * Real-mock-backed tests for messaging (``send_message`` + inbound).
 *
 * Ported from signalwire-python/tests/unit/relay/test_messaging_mock.py.
 * The messaging schemas are permissive (the C# server forwards JObject to
 * the messaging gateway), so the mock validates the wire frame loosely.
 * We still assert the SDK builds the right ``messaging.send`` shape and
 * correctly processes inbound ``messaging.receive`` /
 * ``messaging.state`` events the mock pushes.
 */
class MessagingMockTest extends TestCase
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
            // best-effort
        }
    }

    // ------------------------------------------------------------------
    // send_message — outbound
    // ------------------------------------------------------------------

    #[Test]
    public function sendMessageJournalsMessagingSend(): void
    {
        $msg = $this->client->sendMessage([
            'to_number'   => '+15551112222',
            'from_number' => '+15553334444',
            'body'        => 'hello',
            'tags'        => ['t1', 't2'],
        ]);
        $this->assertInstanceOf(Message::class, $msg);
        $this->assertNotNull($msg->getMessageId());
        $this->assertSame('hello', $msg->getBody());

        $entries = $this->mock->journal()->recv('messaging.send');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame('+15551112222', $p['to_number'] ?? null);
        $this->assertSame('+15553334444', $p['from_number'] ?? null);
        $this->assertSame('hello', $p['body'] ?? null);
        $this->assertSame(['t1', 't2'], $p['tags'] ?? null);
    }

    #[Test]
    public function sendMessageWithMediaOnly(): void
    {
        $msg = $this->client->sendMessage([
            'to_number'   => '+15551112222',
            'from_number' => '+15553334444',
            'media'       => ['https://media.example/cat.jpg'],
        ]);
        $this->assertInstanceOf(Message::class, $msg);

        $entries = $this->mock->journal()->recv('messaging.send');
        $this->assertCount(1, $entries);
        $p = $entries[0]->frame['params'] ?? [];
        $this->assertSame(['https://media.example/cat.jpg'], $p['media'] ?? null);
        // Body absent or empty for media-only.
        $this->assertTrue(
            !isset($p['body']) || $p['body'] === '',
            'body should be absent or empty for media-only',
        );
    }

    #[Test]
    public function sendMessageIncludesContext(): void
    {
        $this->client->sendMessage([
            'to_number'   => '+15551112222',
            'from_number' => '+15553334444',
            'body'        => 'hi',
            'context'     => 'custom-ctx',
        ]);

        $entries = $this->mock->journal()->recv('messaging.send');
        $this->assertCount(1, $entries);
        $this->assertSame('custom-ctx', $entries[0]->frame['params']['context'] ?? null);
    }

    #[Test]
    public function sendMessageReturnsInitialStateQueued(): void
    {
        $msg = $this->client->sendMessage([
            'to_number'   => '+15551112222',
            'from_number' => '+15553334444',
            'body'        => 'hi',
        ]);
        $this->assertSame('queued', $msg->getState());
        $this->assertFalse($msg->isDone());
        $this->assertNotEmpty($this->mock->journal()->recv('messaging.send'));
    }

    #[Test]
    public function sendMessageResolvesOnDelivered(): void
    {
        $msg = $this->client->sendMessage([
            'to_number'   => '+15551112222',
            'from_number' => '+15553334444',
            'body'        => 'hi',
        ]);
        $messageId = $msg->getMessageId();

        // Push a terminal delivered state.
        $this->mock->push([
            'jsonrpc' => '2.0',
            'id'      => bin2hex(random_bytes(8)),
            'method'  => 'signalwire.event',
            'params'  => [
                'event_type' => 'messaging.state',
                'params'     => [
                    'message_id'    => $messageId,
                    'message_state' => 'delivered',
                    'from_number'   => '+15553334444',
                    'to_number'     => '+15551112222',
                    'body'          => 'hi',
                ],
            ],
        ]);

        // Pump the read loop until the message resolves.
        $resolved = MockTest::pumpUntil(
            $this->client,
            fn() => $msg->isDone(),
            5.0,
        );
        $this->assertTrue($resolved, 'delivered event did not resolve the Message');
        $this->assertSame('delivered', $msg->getState());
        $this->assertTrue($msg->isDone());

        // Behavioural + journal: at least one messaging.send + the pushed state.
        $this->assertNotEmpty($this->mock->journal()->recv('messaging.send'));
    }

    #[Test]
    public function sendMessageResolvesOnUndelivered(): void
    {
        $msg = $this->client->sendMessage([
            'to_number'   => '+15551112222',
            'from_number' => '+15553334444',
            'body'        => 'hi',
        ]);
        $messageId = $msg->getMessageId();

        $this->mock->push([
            'jsonrpc' => '2.0',
            'id'      => bin2hex(random_bytes(8)),
            'method'  => 'signalwire.event',
            'params'  => [
                'event_type' => 'messaging.state',
                'params'     => [
                    'message_id'    => $messageId,
                    'message_state' => 'undelivered',
                    'reason'        => 'carrier_blocked',
                ],
            ],
        ]);
        $resolved = MockTest::pumpUntil($this->client, fn() => $msg->isDone(), 5.0);
        $this->assertTrue($resolved);
        $this->assertSame('undelivered', $msg->getState());
        $this->assertSame('carrier_blocked', $msg->getReason());
        $this->assertNotEmpty($this->mock->journal()->recv('messaging.send'));
    }

    #[Test]
    public function sendMessageResolvesOnFailed(): void
    {
        $msg = $this->client->sendMessage([
            'to_number'   => '+15551112222',
            'from_number' => '+15553334444',
            'body'        => 'hi',
        ]);
        $messageId = $msg->getMessageId();

        $this->mock->push([
            'jsonrpc' => '2.0',
            'id'      => bin2hex(random_bytes(8)),
            'method'  => 'signalwire.event',
            'params'  => [
                'event_type' => 'messaging.state',
                'params'     => [
                    'message_id'    => $messageId,
                    'message_state' => 'failed',
                    'reason'        => 'spam',
                ],
            ],
        ]);
        $resolved = MockTest::pumpUntil($this->client, fn() => $msg->isDone(), 5.0);
        $this->assertTrue($resolved);
        $this->assertSame('failed', $msg->getState());
        $this->assertNotEmpty($this->mock->journal()->recv('messaging.send'));
    }

    #[Test]
    public function sendMessageIntermediateStateDoesNotResolve(): void
    {
        $msg = $this->client->sendMessage([
            'to_number'   => '+15551112222',
            'from_number' => '+15553334444',
            'body'        => 'hi',
        ]);
        $messageId = $msg->getMessageId();

        $this->mock->push([
            'jsonrpc' => '2.0',
            'id'      => bin2hex(random_bytes(8)),
            'method'  => 'signalwire.event',
            'params'  => [
                'event_type' => 'messaging.state',
                'params'     => [
                    'message_id'    => $messageId,
                    'message_state' => 'sent',
                ],
            ],
        ]);
        $reached = MockTest::pumpUntil(
            $this->client,
            fn() => $msg->getState() === 'sent',
            2.0,
        );
        $this->assertTrue($reached, 'state did not advance to "sent"');
        $this->assertSame('sent', $msg->getState());
        $this->assertFalse($msg->isDone());

        $this->assertNotEmpty($this->mock->journal()->recv('messaging.send'));
    }

    // ------------------------------------------------------------------
    // Inbound messages
    // ------------------------------------------------------------------

    #[Test]
    public function inboundMessageFiresOnMessageHandler(): void
    {
        $seen = new \ArrayObject();
        $this->client->onMessage(function (Message $msg) use ($seen): void {
            $seen[] = $msg;
        });

        $resp = $this->mock->push([
            'jsonrpc' => '2.0',
            'id'      => bin2hex(random_bytes(8)),
            'method'  => 'signalwire.event',
            'params'  => [
                'event_type' => 'messaging.receive',
                'params'     => [
                    'message_id'    => 'in-msg-1',
                    'context'       => 'default',
                    'direction'     => 'inbound',
                    'from_number'   => '+15551110000',
                    'to_number'     => '+15552220000',
                    'body'          => 'hello back',
                    'media'         => [],
                    'segments'      => 1,
                    'message_state' => 'received',
                    'tags'          => ['incoming'],
                ],
            ],
        ]);
        $this->assertGreaterThanOrEqual(
            1,
            (int) ($resp['count'] ?? 0),
            'mock did not deliver the push to any session',
        );

        $arrived = MockTest::pumpUntil(
            $this->client,
            fn() => count($seen) >= 1,
            5.0,
        );
        $this->assertTrue(
            $arrived,
            'on_message handler did not fire (seen=' . count($seen) . ')',
        );
        $this->assertCount(1, $seen);
        /** @var Message $m */
        $m = $seen[0];
        $this->assertSame('in-msg-1', $m->getMessageId());
        $this->assertSame('inbound', $m->getDirection());
        $this->assertSame('+15551110000', $m->getFromNumber());
        $this->assertSame('+15552220000', $m->getToNumber());
        $this->assertSame('hello back', $m->getBody());
        $this->assertSame(['incoming'], $m->getTags());

        // Journal: the server-side send carried messaging.receive.
        $sends = $this->mock->journal()->send('messaging.receive');
        $this->assertNotEmpty(
            $sends,
            'journaled outbound frame for messaging.receive missing',
        );
    }

    // ------------------------------------------------------------------
    // State progression — full pipeline
    // ------------------------------------------------------------------

    #[Test]
    public function fullMessageStateProgression(): void
    {
        $msg = $this->client->sendMessage([
            'to_number'   => '+15551112222',
            'from_number' => '+15553334444',
            'body'        => 'full pipeline',
        ]);
        $messageId = $msg->getMessageId();

        // Push intermediate "sent".
        $this->mock->push([
            'jsonrpc' => '2.0',
            'id'      => bin2hex(random_bytes(8)),
            'method'  => 'signalwire.event',
            'params'  => [
                'event_type' => 'messaging.state',
                'params'     => [
                    'message_id'    => $messageId,
                    'message_state' => 'sent',
                ],
            ],
        ]);
        $reached = MockTest::pumpUntil(
            $this->client,
            fn() => $msg->getState() === 'sent',
            5.0,
        );
        $this->assertTrue($reached);
        $this->assertSame('sent', $msg->getState());

        // Then "delivered".
        $this->mock->push([
            'jsonrpc' => '2.0',
            'id'      => bin2hex(random_bytes(8)),
            'method'  => 'signalwire.event',
            'params'  => [
                'event_type' => 'messaging.state',
                'params'     => [
                    'message_id'    => $messageId,
                    'message_state' => 'delivered',
                ],
            ],
        ]);
        $resolved = MockTest::pumpUntil($this->client, fn() => $msg->isDone(), 5.0);
        $this->assertTrue($resolved);
        $this->assertSame('delivered', $msg->getState());
        $this->assertTrue($msg->isDone());

        // Two pushed state events show up in the server's outbound journal.
        $sends = $this->mock->journal()->send('messaging.state');
        $this->assertGreaterThanOrEqual(2, count($sends));
    }
}
