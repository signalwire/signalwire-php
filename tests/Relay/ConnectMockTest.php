<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\Relay\Client as RelayClient;
use SignalWire\Relay\Constants;
use SignalWire\Tests\Support\Shape;

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
        // Unscoped shared harness — used only for relayHost()/wsUrl() and for
        // building hand-rolled clients. Tests that assert on journaled connect
        // frames re-scope a harness to their own client's session via
        // scopedFor() so they see ONLY their own frames and run parallel-safe.
        // No global reset here: a global wipe would race a concurrent test.
        $this->mock = MockTest::harness();
    }

    /**
     * Return a harness view scoped to a connected client's RELAY session, so
     * journal reads see only that client's frames. Pass several clients to
     * cover a reconnect pair (the second connect reuses the first's session
     * id when it resumes a protocol, so scoping to either id surfaces both).
     */
    private function scopedFor(RelayClient $client): RelayHarness
    {
        $h = MockTest::harness();
        $scoped = new RelayHarness($h->httpUrl(), $h->wsPort(), $h->httpPort());
        $scoped->scopeTo((string) $client->sessionId);
        return $scoped;
    }

    // ------------------------------------------------------------------
    // Connect — happy path
    // ------------------------------------------------------------------

    #[Test]
    public function connectReturnsProtocolString(): void
    {
        [$client, $mock] = MockTest::scopedClient();
        try {
            $this->assertTrue($client->connected);
            $this->assertNotNull($client->protocol);
            $this->assertStringStartsWith(
                'signalwire_',
                (string) $client->protocol,
                'unexpected protocol: ' . var_export($client->protocol, true),
            );

            // Journal assertion — the connect frame was recorded (scoped to
            // this client's session, so exactly one even under parallelism).
            $entries = $mock->journal()->recv('signalwire.connect');
            $this->assertCount(1, $entries, 'exactly one signalwire.connect was journaled');
        } finally {
            $client->disconnect();
        }
    }

    #[Test]
    public function connectJournalRecordsSignalwireConnect(): void
    {
        [$client, $mock] = MockTest::scopedClient();
        try {
            $entries = $mock->journal()->recv('signalwire.connect');
            $this->assertCount(1, $entries);
        } finally {
            $client->disconnect();
        }
    }

    #[Test]
    public function connectJournalCarriesProjectAndToken(): void
    {
        [$client, $mock] = MockTest::scopedClient();
        try {
            $entries = $mock->journal()->recv('signalwire.connect');
            $this->assertCount(1, $entries);
            $auth = Shape::sub($entries[0]->frame, 'params', 'authentication');
            $this->assertSame('test_proj', $auth['project'] ?? null);
            $this->assertSame('test_tok', $auth['token'] ?? null);
        } finally {
            $client->disconnect();
        }
    }

    #[Test]
    public function connectJournalCarriesContexts(): void
    {
        [$client, $mock] = MockTest::scopedClient();
        try {
            $entries = $mock->journal()->recv('signalwire.connect');
            $this->assertCount(1, $entries);
            $this->assertSame(['default'], Shape::at($entries[0]->frame, 'params', 'contexts'));
        } finally {
            $client->disconnect();
        }
    }

    #[Test]
    public function connectJournalCarriesAgentAndVersion(): void
    {
        [$client, $mock] = MockTest::scopedClient();
        try {
            $entries = $mock->journal()->recv('signalwire.connect');
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
        [$client, $mock] = MockTest::scopedClient();
        try {
            $entries = $mock->journal()->recv('signalwire.connect');
            $this->assertCount(1, $entries);
            $this->assertTrue(Shape::at($entries[0]->frame, 'params', 'event_acks'));
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

        // scopedClient() (NOT the legacy client(), which does a GLOBAL journal
        // reset that would wipe concurrent workers' journals under parallelism).
        [$c1] = MockTest::scopedClient();
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
            // The resume connect carries the protocol and is journaled under
            // c2's own session — scope to it so we read only this test's frame.
            $matches = [];
            foreach ($this->scopedFor($c2)->journal()->recv('signalwire.connect') as $entry) {
                $params = $entry->frame['params'] ?? null;
                if (is_array($params) && ($params['protocol'] ?? null) === $issuedProtocol) {
                    $matches[] = $entry;
                }
            }
            $this->assertNotEmpty($matches, 'no resume connect carried the issued protocol');
        } finally {
            $c2->disconnect();
        }
    }

    #[Test]
    public function reconnectWithProtocolPreservesProtocolValue(): void
    {
        // scopedClient() (NOT the legacy client(), which does a GLOBAL journal
        // reset that would wipe concurrent workers' journals under parallelism).
        [$c1] = MockTest::scopedClient();
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

            // Confirm c2's own journaled connect carries that protocol.
            $found = false;
            foreach ($this->scopedFor($c2)->journal()->recv('signalwire.connect') as $entry) {
                $params = $entry->frame['params'] ?? null;
                if (is_array($params) && ($params['protocol'] ?? null) === $issuedProtocol) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'no journal entry carried the resumed protocol');
        } finally {
            $c2->disconnect();
        }
    }

    // ------------------------------------------------------------------
    // Auth failure paths
    // ------------------------------------------------------------------

    #[Test]
    public function connectRejectsEmptyCredsAtConstructor(): void
    {
        // No journal reset needed: the constructor throws before any wire I/O,
        // and a global reset would race a concurrent test under parallelism.
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

            $entries = $this->scopedFor($client)->journal()->recv('signalwire.connect');
            $this->assertCount(1, $entries);
            $auth = Shape::sub($entries[0]->frame, 'params', 'authentication');
            $this->assertSame('fake-jwt-eyJ.AaaA.BbB', $auth['jwt_token'] ?? null);
            // JWT path: project/token must NOT be on the wire.
            $this->assertArrayNotHasKey('project', $auth);
            $this->assertArrayNotHasKey('token', $auth);
        } finally {
            $client->disconnect();
        }
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
        $stream = @stream_socket_client($address, $errno, $errstr, $timeout);
        if (!is_resource($stream)) {
            throw new \RuntimeException("connect failed: {$errstr}");
        }
        $this->stream = $stream;
        stream_set_blocking($this->stream, true);

        $key = base64_encode(random_bytes(16));
        $expected = base64_encode(sha1($key . self::MAGIC_GUID, true));
        $req = "GET / HTTP/1.1\r\n"
            . 'Host: ' . $parts['host'] . ':' . $parts['port'] . "\r\n"
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

    /**
     * The connected socket, asserting the client has been connect()-ed.
     *
     * @return resource
     */
    private function stream()
    {
        if (!is_resource($this->stream)) {
            throw new \RuntimeException('RawWsClient: not connected');
        }
        return $this->stream;
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
        fwrite($this->stream(), $hdr . $masked);
    }

    public function receive(float $timeout = 2.0): string
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $frame = $this->tryDecodeFrame();
            if ($frame !== null) {
                return $frame;
            }
            $r = [$this->stream()];
            $w = $e = null;
            $remaining = max(0.0, $deadline - microtime(true));
            $secs = (int) floor($remaining);
            $usecs = (int) (($remaining - $secs) * 1_000_000);
            @stream_select($r, $w, $e, $secs, $usecs);
            $chunk = @fread($this->stream(), 8192);
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
