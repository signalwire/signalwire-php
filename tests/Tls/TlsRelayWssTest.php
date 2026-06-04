<?php

declare(strict_types=1);

namespace SignalWire\Tests\Tls;

use PHPUnit\Framework\TestCase;
use SignalWire\Relay\Client as RelayClient;

/**
 * TLS capability test (quadrant 1 of 3): the RELAY client performs a *real*
 * verified WSS handshake.
 *
 * Spawns the shared mock_relay in --tls mode (the WebSocket endpoint becomes
 * wss://, backed by the porting-sdk self-signed test CA), points the real
 * Relay\Client at wss://127.0.0.1:<port> trusting ca.crt, and drives the full
 * connect + authenticate handshake. CA trust flows through the phrity/websocket
 * stream context (cafile + verify_peer=true) — no allow_self_signed, no
 * verify_peer=false, no transport mock. The server only journals an inbound
 * signalwire.connect frame after a credential exchange that completed over the
 * genuinely-established TLS session, so the journal assertion is behavioral
 * proof the WSS link carried real traffic.
 *
 * A negative test points an otherwise-identical client (NO trusted CA) at the
 * same endpoint and asserts the handshake is rejected — proving the cert is
 * actually verified, not skipped.
 *
 * @group tls
 */
final class TlsRelayWssTest extends TestCase
{
    private static ?string $certs = null;
    private static ?string $httpBase = null;

    public static function setUpBeforeClass(): void
    {
        $certs = TlsSupport::certsDir();
        if ($certs === null) {
            self::markTestSkipped('porting-sdk/test_harness/tls not adjacent to repo');
        }
        // Do NOT export SSL_CERT_FILE: the negative subtest relies on an
        // untrusted default store, and the positive path passes the CA
        // explicitly via the ca_file option.
        self::$certs = $certs;
        try {
            self::$httpBase = TlsSupport::startTlsMockRelay();
        } catch (\Throwable $e) {
            self::markTestSkipped('mock_relay --tls unavailable: ' . $e->getMessage());
        }
    }

    public static function tearDownAfterClass(): void
    {
        TlsSupport::shutdownAll();
    }

    public function testRelayClientConnectsAndAuthenticatesOverWss(): void
    {
        TlsSupport::relayReset((string) self::$httpBase);

        $client = new RelayClient([
            'project'  => 'test_proj',
            'token'    => 'test_tok',
            'host'     => '127.0.0.1:' . TlsSupport::RELAY_WS_PORT,
            'scheme'   => 'wss',
            'contexts' => ['default'],
            'ca_file'  => TlsSupport::caCert((string) self::$certs),
        ]);

        $client->connect(); // performs signalwire.connect (authenticate) over WSS

        try {
            // Behavioral proof: connect() only returns after authenticate()
            // round-tripped over the TLS WebSocket.
            $this->assertTrue($client->connected, 'client not connected after WSS connect');

            // Wire proof: the mock journaled the inbound signalwire.connect
            // frame on the (TLS) WebSocket — fetched over the plain-HTTP
            // control plane.
            $this->assertTrue(
                TlsSupport::relaySawRecvMethod((string) self::$httpBase, 'signalwire.connect'),
                'mock journal has no recv signalwire.connect frame over the WSS connection'
            );
        } finally {
            $client->disconnect();
        }
    }

    public function testUntrustedClientIsRejected(): void
    {
        // Same wss:// endpoint, but NO trusted CA (no ca_file option, no
        // SSL_CERT_FILE env) — the private-CA server cert must fail
        // verification against the default trust store.
        $client = new RelayClient([
            'project'  => 'test_proj',
            'token'    => 'test_tok',
            'host'     => '127.0.0.1:' . TlsSupport::RELAY_WS_PORT,
            'scheme'   => 'wss',
            'contexts' => ['default'],
        ]);
        $this->assertNull($client->caFile, 'precondition: negative client must have no CA');

        $this->expectException(\RuntimeException::class);
        try {
            $client->connect();
            $this->fail('WSS connect with no trusted CA unexpectedly succeeded');
        } finally {
            $client->disconnect();
        }
    }
}
