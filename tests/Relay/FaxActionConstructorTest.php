<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\Relay\FaxAction;
use SignalWire\Relay\Client as RelayClient;

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
        MockTest::harness()->reset();
        $this->client = MockTest::client();
    }

    protected function tearDown(): void
    {
        try {
            $this->client->disconnect();
        } catch (\Throwable) {
        }
    }

    #[Test]
    public function constructDefaultFaxTypeIsSend(): void
    {
        $fax = new FaxAction('ctl-1', 'call-1', 'node-1', $this->client);
        $this->assertSame('send', $fax->getFaxType());
        $this->assertSame('calling.send_fax.stop', $fax->getStopMethod());
    }

    #[Test]
    public function constructWithExplicitSendFaxType(): void
    {
        $fax = new FaxAction('ctl-2', 'call-2', 'node-2', $this->client, 'send');
        $this->assertSame('send', $fax->getFaxType());
        $this->assertSame('calling.send_fax.stop', $fax->getStopMethod());
    }

    #[Test]
    public function constructWithReceiveFaxType(): void
    {
        $fax = new FaxAction('ctl-3', 'call-3', 'node-3', $this->client, 'receive');
        $this->assertSame('receive', $fax->getFaxType());
        $this->assertSame('calling.receive_fax.stop', $fax->getStopMethod());
    }

    #[Test]
    public function constructPreservesControlIds(): void
    {
        $fax = new FaxAction('ctl-fax-42', 'call-XYZ', 'node-A', $this->client);
        // Inherited from Action — control_id and call_id round-trip.
        $this->assertSame('ctl-fax-42', $fax->getControlId());
        $this->assertSame('call-XYZ', $fax->getCallId());
    }
}
