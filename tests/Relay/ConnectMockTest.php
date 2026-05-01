<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\Relay\Client as RelayClient;
use SignalWire\Relay\Constants;
use SignalWire\Relay\RelayError;

/**
 * Real-mock-backed tests for ``SignalWire\Relay\Client::connect()``.
 *
 * Ported from signalwire-python/tests/unit/relay/test_connect_mock.py. Each
 * test asserts both behaviour (what the SDK exposed back to the caller)
 * AND wire shape (what the mock journaled).
 */
class ConnectMockTest extends TestCase
{
    private RelayHarness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
    }

    // ------------------------------------------------------------------
    // Connect — happy path
    // ------------------------------------------------------------------

    #[Test]
    public function connectReturnsProtocolString(): void
    {
        $client = MockTest::client();
        try {
            $this->assertTrue($client->connected);
            $this->assertNotNull($client->protocol);
            $this->assertStringStartsWith(
                'signalwire_',
                (string) $client->protocol,
                'unexpected protocol: ' . var_export($client->protocol, true),
            );
        } finally {
            $client->disconnect();
        }

        // Journal assertion — the connect frame was recorded.
        $entries = $this->mock->journal()->recv('signalwire.connect');
        $this->assertCount(1, $entries, 'exactly one signalwire.connect was journaled');
    }

    #[Test]
    public function connectJournalRecordsSignalwireConnect(): void
    {
        $client = MockTest::client();
        try {
            $entries = $this->mock->journal()->recv('signalwire.connect');
            $this->assertCount(1, $entries);
        } finally {
            $client->disconnect();
        }
    }

    #[Test]
    public function connectJournalCarriesProjectAndToken(): void
    {
        $client = MockTest::client();
        try {
            $entries = $this->mock->journal()->recv('signalwire.connect');
            $this->assertCount(1, $entries);
            $auth = $entries[0]->frame['params']['authentication'] ?? null;
            $this->assertIsArray($auth);
            $this->assertSame('test_proj', $auth['project'] ?? null);
            $this->assertSame('test_tok', $auth['token'] ?? null);
        } finally {
            $client->disconnect();
        }
    }

    #[Test]
    public function connectJournalCarriesContexts(): void
    {
        $client = MockTest::client();
        try {
            $entries = $this->mock->journal()->recv('signalwire.connect');
            $this->assertCount(1, $entries);
            $this->assertSame(['default'], $entries[0]->frame['params']['contexts'] ?? null);
        } finally {
            $client->disconnect();
        }
    }

    #[Test]
    public function connectJournalCarriesAgentAndVersion(): void
    {
        $client = MockTest::client();
        try {
            $entries = $this->mock->journal()->recv('signalwire.connect');
            $this->assertCount(1, $entries);
            $p = $entries[0]->frame['params'] ?? [];
            $this->assertIsArray($p);
            $this->assertSame($client->agent, $p['agent'] ?? null);
            $this->assertSame(Constants::PROTOCOL_VERSION, $p['version'] ?? null);
        } finally {
            $client->disconnect();
        }
    }

    #[Test]
    public function connectJournalEventAcksTrue(): void
    {
        $client = MockTest::client();
        try {
            $entries = $this->mock->journal()->recv('signalwire.connect');
            $this->assertCount(1, $entries);
            $this->assertTrue($entries[0]->frame['params']['event_acks'] ?? null);
        } finally {
            $client->disconnect();
        }
    }

    // ------------------------------------------------------------------
    // Reconnect with stored protocol
    // ------------------------------------------------------------------

    #[Test]
    public function reconnectWithProtocolStringIncludesProtocolInFrame(): void
    {
        $issuedProtocol = null;

        $c1 = MockTest::client();
        try {
            $issuedProtocol = $c1->protocol;
            $this->assertNotNull($issuedProtocol);
        } finally {
            $c1->disconnect();
        }

        // Build a second client carrying the same protocol on the wire.
        $c2 = new RelayClient([
            'project'  => 'test_proj',
            'token'    => 'test_tok',
            'host'     => $this->mock->relayHost(),
            'scheme'   => 'ws',
            'contexts' => ['default'],
        ]);
        $c2->protocol = $issuedProtocol;
        try {
            $c2->connect();
        } finally {
            $c2->disconnect();
        }

        // Find a connect frame that carries the resumed protocol.
        $matches = [];
        foreach ($this->mock->journal()->recv('signalwire.connect') as $entry) {
            if (($entry->frame['params']['protocol'] ?? null) === $issuedProtocol) {
                $matches[] = $entry;
            }
        }
        $this->assertNotEmpty($matches, 'no resume connect carried the issued protocol');
    }

    #[Test]
    public function reconnectWithProtocolPreservesProtocolValue(): void
    {
        $c1 = MockTest::client();
        try {
            $issuedProtocol = $c1->protocol;
            $this->assertNotNull($issuedProtocol);
        } finally {
            $c1->disconnect();
        }

        $c2 = new RelayClient([
            'project' => 'test_proj',
            'token'   => 'test_tok',
            'host'    => $this->mock->relayHost(),
            'scheme'  => 'ws',
        ]);
        $c2->protocol = $issuedProtocol;
        try {
            $c2->connect();
            $this->assertSame($issuedProtocol, $c2->protocol);
        } finally {
            $c2->disconnect();
        }

        // Confirm at least one journaled connect carries that protocol.
        $found = false;
        foreach ($this->mock->journal()->recv('signalwire.connect') as $entry) {
            if (($entry->frame['params']['protocol'] ?? null) === $issuedProtocol) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'no journal entry carried the resumed protocol');
    }

    // ------------------------------------------------------------------
    // Auth failure paths
    // ------------------------------------------------------------------

    #[Test]
    public function connectRejectsEmptyCredsAtConstructor(): void
    {
        // Reset journal so we can also assert that nothing was sent.
        $this->mock->reset();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/project and token are required/');
        new RelayClient(['project' => '', 'token' => '', 'host' => 'anywhere']);
    }

    #[Test]
    public function unauthenticatedRawConnectRejectedByMock(): void
    {
        // The SDK refuses to construct a client with empty creds. To prove
        // the mock's auth-failure path is reachable (a port whose SDK does
        // allow empty creds receives the same error), drive the wire
        // directly.
        $sock = new RawWsClient();
        $sock->connect($this->mock->wsUrl());
        try {
            $reqId = bin2hex(random_bytes(8));
            $sock->sendText(json_encode([
                'jsonrpc' => '2.0',
                'id'      => $reqId,
                'method'  => 'signalwire.connect',
                'params'  => [
                    'version' => Constants::PROTOCOL_VERSION,
                    'agent'   => 'test-agent',
                    'authentication' => ['project' => '', 'token' => ''],
                ],
            ], JSON_THROW_ON_ERROR));
            $resp = json_decode($sock->receive(2.0), true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($resp);
            $this->assertArrayHasKey('error', $resp);
            $err = $resp['error'];
            $this->assertSame(
                'AUTH_REQUIRED',
                $err['data']['signalwire_error_code'] ?? null,
            );
        } finally {
            $sock->close();
        }

        // Wire-level frame is also visible in the journal.
        $entries = $this->mock->journal()->recv('signalwire.connect');
        $this->assertNotEmpty($entries);
    }

    // ------------------------------------------------------------------
    // JWT path
    // ------------------------------------------------------------------

    #[Test]
    public function connectWithJwtCarriesJwtOnWire(): void
    {
        $client = new RelayClient([
            'jwt_token' => 'fake-jwt-eyJ.AaaA.BbB',
            'host'      => $this->mock->relayHost(),
            'scheme'    => 'ws',
        ]);
        try {
            $client->connect();
            $this->assertTrue($client->connected);
        } finally {
            $client->disconnect();
        }

        $entries = $this->mock->journal()->recv('signalwire.connect');
        $this->assertCount(1, $entries);
        $auth = $entries[0]->frame['params']['authentication'] ?? null;
        $this->assertIsArray($auth);
        $this->assertSame('fake-jwt-eyJ.AaaA.BbB', $auth['jwt_token'] ?? null);
        // JWT path: project/token must NOT be on the wire.
        $this->assertArrayNotHasKey('project', $auth);
        $this->assertArrayNotHasKey('token', $auth);
    }
}

/**
 * Tiny raw WebSocket client used by the auth-failure test to bypass the
 * SDK and dial the mock directly. Lives in this file because the only
 * caller is the auth-failure test; not promoted to ``src/`` because
 * production code uses the SDK's hardened transport.
 *
 * @internal
 */
final class RawWsClient
{
    /** @var resource|null */
    private $stream = null;
    private string $readBuffer = '';
    private const MAGIC_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public function connect(string $url, float $timeout = 5.0): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['port'])) {
            throw new \InvalidArgumentException("RawWsClient: bad url {$url}");
        }
        $address = sprintf('tcp://%s:%d', $parts['host'], $parts['port']);
        $this->stream = @stream_socket_client($address, $errno, $errstr, $timeout);
        if (!is_resource($this->stream)) {
            throw new \RuntimeException("connect failed: {$errstr}");
        }
        stream_set_blocking($this->stream, true);

        $key = base64_encode(random_bytes(16));
        $expected = base64_encode(sha1($key . self::MAGIC_GUID, true));
        $req = "GET / HTTP/1.1\r\n"
            . "Host: " . $parts['host'] . ':' . $parts['port'] . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
        fwrite($this->stream, $req);

        $resp = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $chunk = fread($this->stream, 4096);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            $resp .= $chunk;
            if (str_contains($resp, "\r\n\r\n")) {
                break;
            }
        }
        $headerEnd = strpos($resp, "\r\n\r\n");
        if ($headerEnd === false || !str_contains(substr($resp, 0, $headerEnd), '101')) {
            throw new \RuntimeException('handshake failed: ' . substr($resp, 0, 200));
        }
        $this->readBuffer .= substr($resp, $headerEnd + 4);
        stream_set_blocking($this->stream, false);
    }

    public function sendText(string $payload): void
    {
        $b0 = chr(0x81);
        $len = strlen($payload);
        $mask = random_bytes(4);
        if ($len < 126) {
            $hdr = $b0 . chr(0x80 | $len) . $mask;
        } elseif ($len < 0x10000) {
            $hdr = $b0 . chr(0x80 | 126) . pack('n', $len) . $mask;
        } else {
            $hdr = $b0 . chr(0x80 | 127) . pack('J', $len) . $mask;
        }
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }
        fwrite($this->stream, $hdr . $masked);
    }

    public function receive(float $timeout = 2.0): string
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $frame = $this->tryDecodeFrame();
            if ($frame !== null) {
                return $frame;
            }
            $r = [$this->stream];
            $w = $e = null;
            $remaining = max(0.0, $deadline - microtime(true));
            $secs = (int) floor($remaining);
            $usecs = (int) (($remaining - $secs) * 1_000_000);
            @stream_select($r, $w, $e, $secs, $usecs);
            $chunk = @fread($this->stream, 8192);
            if (is_string($chunk) && $chunk !== '') {
                $this->readBuffer .= $chunk;
            }
        }
        throw new \RuntimeException('RawWsClient: receive timed out');
    }

    private function tryDecodeFrame(): ?string
    {
        $buf = $this->readBuffer;
        $len = strlen($buf);
        if ($len < 2) {
            return null;
        }
        $b1 = ord($buf[1]);
        $plen = $b1 & 0x7F;
        $offset = 2;
        if ($plen === 126) {
            if ($len < $offset + 2) {
                return null;
            }
            $unpacked = unpack('n', substr($buf, $offset, 2));
            $plen = (int) ($unpacked[1] ?? 0);
            $offset += 2;
        } elseif ($plen === 127) {
            if ($len < $offset + 8) {
                return null;
            }
            $unpacked = unpack('J', substr($buf, $offset, 8));
            $plen = (int) ($unpacked[1] ?? 0);
            $offset += 8;
        }
        if ($len < $offset + $plen) {
            return null;
        }
        $payload = substr($buf, $offset, $plen);
        $this->readBuffer = substr($buf, $offset + $plen);
        return $payload;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }
}
