<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\Relay\Call;
use SignalWire\Relay\Client as RelayClient;

/**
 * Mock-backed tests for queue management and digit-binding control on
 * Call. Ported from signalwire-python/tests/unit/relay/test_call.py
 * (queue_leave / clear_digit_bindings sections).
 *
 * These verify the Python-shaped overloads:
 *   * Call.queueLeave($queueName, $controlId, $queueId, $statusUrl)
 *   * Call.clearDigitBindings($realm)
 */
class QueueAndDigitMockTest extends TestCase
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

    // ------------------------------------------------------------------
    // queueLeave
    // ------------------------------------------------------------------

    #[Test]
    public function queueLeaveNoArgsJournalsBareCalling(): void
    {
        // Legacy no-arg shape is still callable but the relay schema rejects
        // a queue.leave with no control_id; capture and assert the error.
        $call = $this->answeredInboundCall('call-q-1');
        $threw = false;
        try {
            $call->queueLeave();
        } catch (\SignalWire\Relay\RelayError $e) {
            $threw = true;
            $this->assertStringContainsString('control_id', $e->getMessage());
        }
        $this->assertTrue($threw, 'bare queueLeave() must surface schema rejection');
    }

    #[Test]
    public function queueLeaveWithQueueNameSendsControlIdAndName(): void
    {
        $call = $this->answeredInboundCall('call-q-2');
        $call->queueLeave(queue_name: 'support');

        $entries = $this->mock->journal()->recv('calling.queue.leave');
        $this->assertNotEmpty($entries);
        $params = $entries[count($entries) - 1]->frame['params'] ?? [];
        $this->assertSame('support', $params['queue_name']);
        // Auto-generated control_id present and looks like 32 hex chars.
        $this->assertArrayHasKey('control_id', $params);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $params['control_id']);
    }

    #[Test]
    public function queueLeaveWithExplicitControlIdAndQueueId(): void
    {
        $call = $this->answeredInboundCall('call-q-3');
        $call->queueLeave(
            queue_name: 'sales',
            control_id: 'ctrl-explicit-42',
            queue_id: 'q-uuid-1',
            status_url: 'https://hooks/leave'
        );

        $entries = $this->mock->journal()->recv('calling.queue.leave');
        $this->assertNotEmpty($entries);
        $params = $entries[count($entries) - 1]->frame['params'] ?? [];
        $this->assertSame('ctrl-explicit-42', $params['control_id']);
        $this->assertSame('sales', $params['queue_name']);
        $this->assertSame('q-uuid-1', $params['queue_id']);
        $this->assertSame('https://hooks/leave', $params['status_url']);
    }

    #[Test]
    public function queueLeaveExtraKwargsForwardedToWire(): void
    {
        $call = $this->answeredInboundCall('call-q-4');
        $call->queueLeave(
            queue_name: 'q',
            control_id: 'c',
            kwargs: ['custom_field' => 'X']
        );

        $entries = $this->mock->journal()->recv('calling.queue.leave');
        $this->assertNotEmpty($entries);
        $params = $entries[count($entries) - 1]->frame['params'] ?? [];
        $this->assertSame('X', $params['custom_field']);
    }

    // ------------------------------------------------------------------
    // clearDigitBindings
    // ------------------------------------------------------------------

    #[Test]
    public function clearDigitBindingsNoArgsSendsEmptyParams(): void
    {
        $call = $this->answeredInboundCall('call-cdb-1');
        $call->clearDigitBindings();

        $entries = $this->mock->journal()->recv('calling.clear_digit_bindings');
        $this->assertNotEmpty($entries);
        $params = $entries[count($entries) - 1]->frame['params'] ?? [];
        $this->assertArrayNotHasKey('realm', $params);
    }

    #[Test]
    public function clearDigitBindingsWithRealmSendsRealm(): void
    {
        $call = $this->answeredInboundCall('call-cdb-2');
        $call->clearDigitBindings(realm: 'menu1');

        $entries = $this->mock->journal()->recv('calling.clear_digit_bindings');
        $this->assertNotEmpty($entries);
        $params = $entries[count($entries) - 1]->frame['params'] ?? [];
        $this->assertSame('menu1', $params['realm']);
    }

    #[Test]
    public function clearDigitBindingsKwargsForwardedToWire(): void
    {
        $call = $this->answeredInboundCall('call-cdb-3');
        $call->clearDigitBindings(
            realm: 'r1',
            kwargs: ['extra_flag' => true]
        );

        $entries = $this->mock->journal()->recv('calling.clear_digit_bindings');
        $this->assertNotEmpty($entries);
        $params = $entries[count($entries) - 1]->frame['params'] ?? [];
        $this->assertSame('r1', $params['realm']);
        $this->assertTrue($params['extra_flag']);
    }
}
