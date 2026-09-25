<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\Relay\Call;
use SignalWire\Relay\Client as RelayClient;
use SignalWire\Relay\FaxAction;

/**
 * FaxAction is normally constructed indirectly via Call::sendFax /
 * Call::receiveFax. Python's reference exposes a direct __construct
 * (call, control_id, method_prefix), so PHP must allow direct
 * construction too — these tests assert the constructor works for
 * both 'send' and 'receive' fax types, the type round-trips, and the
 * stop-method dispatch maps correctly.
 *
 * Mirrors signalwire-python/tests/unit/relay/test_actions.py
 * (TestFaxActionInit).
 */
class FaxActionConstructorTest extends TestCase
{
    private RelayClient $client;

    protected function setUp(): void
    {
        // Bring up a real (mock-backed) client so we have something to
        // pass as the $client param. We never actually drive the wire
        // here — these are unit-shape tests on the Action class itself.
        // Use a scoped client (no global reset) so this is parallel-safe.
        [$this->client] = MockTest::scopedClient();
    }

    protected function tearDown(): void
    {
        try {
            $this->client->disconnect();
        } catch (\Throwable) {
        }
    }

    /** The owning Call every Action requires (a bare handle; never driven here). */
    private function makeCall(): Call
    {
        return new Call([
            'call_id' => 'call-1',
            'node_id' => 'node-1',
            'tag' => 'tag-1',
            'device' => ['type' => 'phone', 'params' => ['to_number' => '+15551234567']],
            'context' => 'default',
        ], $this->client);
    }

    #[Test]
    public function constructDefaultFaxTypeIsSend(): void
    {
        $fax = new FaxAction('ctl-1', 'call-1', 'node-1', $this->client, $this->makeCall());
        $this->assertSame('send', $fax->getFaxType());
        $this->assertSame('calling.send_fax.stop', $fax->getStopMethod());
    }

    #[Test]
    public function constructWithExplicitSendFaxType(): void
    {
        $fax = new FaxAction('ctl-2', 'call-2', 'node-2', $this->client, $this->makeCall(), 'send');
        $this->assertSame('send', $fax->getFaxType());
        $this->assertSame('calling.send_fax.stop', $fax->getStopMethod());
    }

    #[Test]
    public function constructWithReceiveFaxType(): void
    {
        $fax = new FaxAction('ctl-3', 'call-3', 'node-3', $this->client, $this->makeCall(), 'receive');
        $this->assertSame('receive', $fax->getFaxType());
        $this->assertSame('calling.receive_fax.stop', $fax->getStopMethod());
    }

    #[Test]
    public function constructPreservesControlIds(): void
    {
        $fax = new FaxAction('ctl-fax-42', 'call-XYZ', 'node-A', $this->client, $this->makeCall());
        // Inherited from Action — control_id and call_id round-trip.
        $this->assertSame('ctl-fax-42', $fax->getControlId());
        $this->assertSame('call-XYZ', $fax->getCallId());
    }
}
