<?php

declare(strict_types=1);

namespace SignalWire\Relay;

/**
 * Minimal RFC 6455 WebSocket client built on PHP streams.
 *
 * Why this isn't a third-party dep:
 * - signalwire-php has no composer.lock and avoids transitive deps
 *   beyond ext-json / ext-openssl / ext-mbstring (see composer.json).
 * - The RELAY protocol only needs text frames (≤ 64 KiB JSON-RPC
 *   messages) and close frames; binary frames, fragmentation,
 *   continuation, and per-message-deflate are not used.
 * - We need both blocking reads (during handshake) and non-blocking
 *   poll reads (during the run loop) — straightforward via
 *   stream_set_blocking()/stream_select().
 *
 * Scope:
 * - Supports ws:// and wss:// URIs.
 * - Handshakes per RFC 6455: GET upgrade with random Sec-WebSocket-Key,
 *   verifies Sec-WebSocket-Accept against the magic-GUID SHA-1.
 * - Encodes outbound text frames with FIN=1, opcode=0x1, mask bit set
 *   (mandatory for client → server) and a fresh 4-byte mask per frame.
 * - Decodes inbound frames: text (0x1), binary (0x2 — surfaced as
 *   bytes), ping (0x9 — auto-replies with pong), pong (0xA — ignored),
 *   close (0x8 — surfaces a sentinel and drains).
 * - Honors the 7-bit / 16-bit / 64-bit length encodings.
 *
 * NOT IMPLEMENTED (deliberate):
 * - Permessage-deflate: RELAY does not negotiate it.
 * - Fragmented messages: RELAY sends one JSON-RPC message per text
 *   frame.
 * - WSS certificate pinning beyond stream context defaults.
 */
class WebSocket
{
    private const MAGIC_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var resource|null */
    private $stream = null;

    private string $host = '';
    private int $port = 0;
    private string $path = '/';
    private string $scheme = 'wss';

    private bool $connected = false;
    private string $readBuffer = '';
    /** Special marker the read loop uses to surface a remote close. */
    public const CLOSE_FRAME = "\x00__CLOSE__\x00";

    /**
     * Open a WebSocket connection to the given URI.
     *
     * @param string $uri        ws:// or wss:// URI (e.g. wss://example.signalwire.com/api/relay/ws).
     * @param array<string,string> $headers Additional HTTP headers for the upgrade request.
     * @param float $timeout     TCP-connect timeout in seconds.
     * @throws \RuntimeException on connection or handshake failure.
     */
    public function connect(string $uri, array $headers = [], float $timeout = 10.0): void
    {
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException("Invalid WebSocket URI: {$uri}");
        }
        $this->scheme = strtolower($parts['scheme']);
        if (!in_array($this->scheme, ['ws', 'wss'], true)) {
            throw new \InvalidArgumentException(
                "Unsupported scheme '{$this->scheme}' (must be ws or wss)"
            );
        }
        $this->host = $parts['host'];
        $this->port = $parts['port'] ?? ($this->scheme === 'wss' ? 443 : 80);
        $this->path = ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $transport = $this->scheme === 'wss' ? 'tls' : 'tcp';
        $address = sprintf('%s://%s:%d', $transport, $this->host, $this->port);

        $errno = 0;
        $errstr = '';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);
        $stream = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($stream === false) {
            throw new \RuntimeException(
                "Failed to connect to {$address}: ({$errno}) {$errstr}"
            );
        }
        // Blocking + a generous overall timeout for the upgrade.
        stream_set_blocking($stream, true);
        stream_set_timeout($stream, (int) max(1, $timeout));
        $this->stream = $stream;

        $this->performHandshake($headers, $timeout);
        $this->connected = true;
        // Switch to non-blocking for the post-handshake run loop.
        stream_set_blocking($this->stream, false);
    }

    /**
     * Send a UTF-8 text frame (FIN=1, opcode=0x1, masked).
     *
     * @throws \RuntimeException if the socket is closed or write fails.
     */
    public function sendText(string $payload): void
    {
        if (!$this->connected || $this->stream === null) {
            throw new \RuntimeException('WebSocket: send on closed socket');
        }
        $frame = $this->encodeFrame(0x1, $payload);
        $this->writeAll($frame);
    }

    /**
     * Send a close frame and tear down the socket.
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        if (!$this->connected || $this->stream === null) {
            return;
        }
        try {
            $payload = pack('n', $code) . $reason;
            $frame = $this->encodeFrame(0x8, $payload);
            @fwrite($this->stream, $frame);
        } catch (\Throwable) {
            // best-effort
        }
        $this->shutdown();
    }

    /**
     * Read one complete inbound frame, blocking up to $timeout seconds.
     *
     * Returns:
     *   - the decoded payload string for a text/binary frame
     *   - WebSocket::CLOSE_FRAME for a close
     *   - null on read timeout (socket still healthy)
     * Auto-handles ping (replies with pong) and pong (silently consumed)
     * and re-attempts to deliver the next non-control frame.
     *
     * @throws \RuntimeException on malformed frames or socket errors.
     */
    public function receive(float $timeout = 5.0): ?string
    {
        if (!$this->connected || $this->stream === null) {
            throw new \RuntimeException('WebSocket: receive on closed socket');
        }
        $deadline = microtime(true) + $timeout;

        while (true) {
            $frame = $this->tryDecodeBufferedFrame();
            if ($frame !== null) {
                [$opcode, $payload] = $frame;

                if ($opcode === 0x9) { // ping → pong
                    $pong = $this->encodeFrame(0xA, $payload);
                    $this->writeAll($pong);
                    continue;
                }
                if ($opcode === 0xA) { // pong (ignore)
                    continue;
                }
                if ($opcode === 0x8) { // close
                    return self::CLOSE_FRAME;
                }
                // Text (0x1) or binary (0x2)
                return $payload;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }

            $read = [$this->stream];
            $write = $except = null;
            $secs = (int) floor($remaining);
            $usecs = (int) (($remaining - $secs) * 1_000_000);
            $count = @stream_select($read, $write, $except, $secs, $usecs);
            if ($count === false) {
                // Interrupted system call — retry until deadline.
                continue;
            }
            if ($count === 0) {
                return null;
            }

            $chunk = @fread($this->stream, 8192);
            if ($chunk === false) {
                throw new \RuntimeException('WebSocket: fread failed');
            }
            if ($chunk === '') {
                // EOF — peer closed without a close frame.
                $this->shutdown();
                return self::CLOSE_FRAME;
            }
            $this->readBuffer .= $chunk;
        }
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    // ---- private helpers ----

    /**
     * @param array<string,string> $headers
     */
    private function performHandshake(array $headers, float $timeout): void
    {
        if ($this->stream === null) {
            throw new \RuntimeException('WebSocket: handshake on null stream');
        }
        $key = base64_encode(random_bytes(16));
        $expectedAccept = base64_encode(
            sha1($key . self::MAGIC_GUID, true)
        );

        $hostHeader = $this->host;
        if (($this->scheme === 'wss' && $this->port !== 443)
            || ($this->scheme === 'ws' && $this->port !== 80)
        ) {
            $hostHeader .= ':' . $this->port;
        }

        $reqLines = [
            "GET {$this->path} HTTP/1.1",
            "Host: {$hostHeader}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
        ];
        foreach ($headers as $name => $value) {
            $reqLines[] = "{$name}: {$value}";
        }
        $req = implode("\r\n", $reqLines) . "\r\n\r\n";
        $this->writeAll($req);

        // Read the response headers (blocking, line-buffered).
        $resp = '';
        $deadline = microtime(true) + max(1.0, $timeout);
        while (microtime(true) < $deadline) {
            $chunk = @fread($this->stream, 4096);
            if ($chunk === false || $chunk === '') {
                $info = stream_get_meta_data($this->stream);
                if (!empty($info['timed_out'])) {
                    throw new \RuntimeException('WebSocket: handshake read timed out');
                }
                if ($chunk === '') {
                    // EOF before completion
                    break;
                }
            } else {
                $resp .= $chunk;
                if (str_contains($resp, "\r\n\r\n")) {
                    break;
                }
            }
        }
        $headerEnd = strpos($resp, "\r\n\r\n");
        if ($headerEnd === false) {
            throw new \RuntimeException(
                'WebSocket: incomplete handshake response: '
                . substr($resp, 0, 200)
            );
        }
        $headBlock = substr($resp, 0, $headerEnd);
        $bodyTail = substr($resp, $headerEnd + 4);
        if ($bodyTail !== '') {
            $this->readBuffer .= $bodyTail;
        }

        $lines = preg_split('/\r\n/', $headBlock) ?: [];
        $statusLine = array_shift($lines) ?? '';
        if (!preg_match('#^HTTP/1\.1\s+101\b#i', $statusLine)) {
            throw new \RuntimeException(
                "WebSocket: handshake failed: {$statusLine}"
            );
        }
        $headersLower = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headersLower[strtolower(trim($k))] = trim($v);
            }
        }
        if (strtolower($headersLower['upgrade'] ?? '') !== 'websocket') {
            throw new \RuntimeException('WebSocket: missing Upgrade: websocket');
        }
        if (stripos($headersLower['connection'] ?? '', 'upgrade') === false) {
            throw new \RuntimeException('WebSocket: missing Connection: Upgrade');
        }
        $accept = $headersLower['sec-websocket-accept'] ?? '';
        if (!hash_equals($expectedAccept, $accept)) {
            throw new \RuntimeException(
                "WebSocket: Sec-WebSocket-Accept mismatch (got '{$accept}')"
            );
        }
    }

    /**
     * Try to decode one complete frame from the read buffer.
     *
     * @return array{int, string}|null [opcode, payload] or null if buffer is short.
     */
    private function tryDecodeBufferedFrame(): ?array
    {
        $buf = $this->readBuffer;
        $len = strlen($buf);
        if ($len < 2) {
            return null;
        }
        $b0 = ord($buf[0]);
        $b1 = ord($buf[1]);
        $fin = ($b0 & 0x80) !== 0;
        $opcode = $b0 & 0x0F;
        $masked = ($b1 & 0x80) !== 0;
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
        $maskKey = '';
        if ($masked) {
            if ($len < $offset + 4) {
                return null;
            }
            $maskKey = substr($buf, $offset, 4);
            $offset += 4;
        }
        if ($len < $offset + $plen) {
            return null;
        }
        $payload = substr($buf, $offset, $plen);
        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < $plen; $i++) {
                $unmasked .= chr(ord($payload[$i]) ^ ord($maskKey[$i % 4]));
            }
            $payload = $unmasked;
        }
        $this->readBuffer = substr($buf, $offset + $plen);

        // RELAY only sends complete (fin=1) text/control frames. If we
        // ever see a continuation, surface it as a hard error so we
        // don't silently drop bytes.
        if (!$fin && $opcode !== 0x0) {
            throw new \RuntimeException(
                'WebSocket: fragmented message received (fin=0, opcode='
                . $opcode . ') — unsupported on this transport.'
            );
        }
        return [$opcode, $payload];
    }

    /**
     * Encode a single masked client → server frame.
     */
    private function encodeFrame(int $opcode, string $payload): string
    {
        $b0 = chr(0x80 | ($opcode & 0x0F)); // FIN=1
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
        return $hdr . $masked;
    }

    /**
     * Write all of $data to the socket, looping over partial writes.
     */
    private function writeAll(string $data): void
    {
        if ($this->stream === null) {
            throw new \RuntimeException('WebSocket: write on null stream');
        }
        $remaining = strlen($data);
        $offset = 0;
        $deadline = microtime(true) + 10.0;
        while ($remaining > 0 && microtime(true) < $deadline) {
            $written = @fwrite($this->stream, substr($data, $offset, $remaining));
            if ($written === false) {
                throw new \RuntimeException('WebSocket: write failed');
            }
            if ($written === 0) {
                // Socket buffer full or briefly blocked: wait for writability.
                $w = [$this->stream];
                $r = $e = null;
                @stream_select($r, $w, $e, 0, 100_000);
                continue;
            }
            $offset += $written;
            $remaining -= $written;
        }
        if ($remaining > 0) {
            throw new \RuntimeException(
                "WebSocket: write timed out with {$remaining} bytes pending"
            );
        }
    }

    private function shutdown(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
        $this->connected = false;
        $this->readBuffer = '';
    }
}
