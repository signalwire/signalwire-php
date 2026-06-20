<?php

declare(strict_types=1);

namespace SignalWire\Relay;

use Phrity\Net\Context;
use WebSocket\Client as PhpWebSocketClient;
use WebSocket\Configuration as PhpWebSocketConfiguration;
use WebSocket\Exception\ConnectionClosedException;
use WebSocket\Exception\ConnectionLevelInterface;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Message\Binary;
use WebSocket\Message\Close;
use WebSocket\Message\Text;

/**
 * RELAY WebSocket transport.
 *
 * This is a thin adapter over the maintained {@link \WebSocket\Client}
 * (phrity/websocket) library. It deliberately keeps the same small public
 * surface the rest of the SDK depends on — connect / sendText / receive /
 * close / isConnected, plus the {@see self::CLOSE_FRAME} sentinel — so that
 * {@see Client} (and its test subclasses overriding createSocket()) need no
 * changes. The previous implementation hand-rolled RFC 6455 framing on raw
 * PHP streams; that responsibility now belongs to phrity/websocket.
 *
 * Why phrity/websocket:
 * - It performs a *real*, verified TLS handshake for ``wss://`` URIs. A
 *   private CA (e.g. the porting-sdk test CA) is trusted by passing its
 *   ``cafile`` through the stream {@see \Phrity\Net\Context} — peer
 *   verification stays ON (no allow_self_signed, no verify_peer=false).
 * - It owns frame masking/decoding, the upgrade handshake, the
 *   Sec-WebSocket-Accept check, ping/pong, and close framing.
 *
 * Transport contract preserved for {@see Client}:
 * - ``receive()`` returns the decoded text payload of the next data frame,
 *   {@see self::CLOSE_FRAME} when the peer closed, or ``null`` on read
 *   timeout (socket still healthy). Ping/pong are consumed internally.
 * - Outbound application messages are always text frames (JSON-RPC).
 */
class WebSocket
{
    /** Special marker the read loop uses to surface a remote close. */
    public const CLOSE_FRAME = "\x00__CLOSE__\x00";

    private ?PhpWebSocketClient $client = null;
    private bool $connected = false;

    /**
     * Optional path to a CA bundle (PEM) used to verify the server cert on
     * ``wss://`` connections. When null, the system trust store is used.
     * Peer verification is always enabled.
     */
    private ?string $caFile;

    public function __construct(?string $caFile = null)
    {
        $this->caFile = $caFile;
    }

    /**
     * Open a WebSocket connection to the given URI.
     *
     * @param string               $uri     ws:// or wss:// URI.
     * @param array<string,string> $headers Additional handshake headers.
     * @param float                $timeout TCP-connect / read timeout (seconds).
     * @param string|null          $caFile  Optional CA bundle (PEM) for wss://
     *                                       peer verification; overrides the
     *                                       value passed to the constructor.
     * @throws \InvalidArgumentException on an invalid/unsupported URI.
     * @throws \RuntimeException         on connection or handshake failure.
     */
    public function connect(
        string $uri,
        array $headers = [],
        float $timeout = 10.0,
        ?string $caFile = null,
    ): void {
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException("Invalid WebSocket URI: {$uri}");
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['ws', 'wss'], true)) {
            throw new \InvalidArgumentException(
                "Unsupported scheme '{$scheme}' (must be ws or wss)"
            );
        }

        $ca = $caFile ?? $this->caFile;

        // Build the stream context. For wss:// we REQUIRE genuine peer
        // verification; the only knob is *which* CA to trust (system store
        // by default, or an explicit cafile for a private CA). No
        // allow_self_signed, no verify_peer=false.
        $context = new Context();
        if ($scheme === 'wss') {
            $context->setOption('ssl', 'verify_peer', true);
            $context->setOption('ssl', 'verify_peer_name', true);
            $context->setOption('ssl', 'SNI_enabled', true);
            if ($ca !== null && $ca !== '') {
                $context->setOption('ssl', 'cafile', $ca);
            }
        }

        $configuration = new PhpWebSocketConfiguration(
            context: $context,
            timeout: (int) max(1, (int) ceil($timeout)),
        );

        $client = new PhpWebSocketClient($uri, $configuration);
        foreach ($headers as $name => $value) {
            $client->addHeader($name, $value);
        }

        try {
            $client->connect();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Failed to connect to {$uri}: " . $e->getMessage(),
                0,
                $e
            );
        }

        $this->client = $client;
        $this->connected = true;
    }

    /**
     * Send a UTF-8 text frame (the only application frame type RELAY uses).
     *
     * @throws \RuntimeException if the socket is closed or the write fails.
     */
    public function sendText(string $payload): void
    {
        if (!$this->connected || $this->client === null) {
            throw new \RuntimeException('WebSocket: send on closed socket');
        }
        try {
            $this->client->send(new Text($payload));
        } catch (\Throwable $e) {
            $this->connected = false;
            throw new \RuntimeException('WebSocket: write failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Send a close frame and tear down the socket. Best-effort.
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        if ($this->client !== null) {
            try {
                // Send a proper close frame, then tear the socket down.
                $this->client->send(new Close($code, $reason));
            } catch (\Throwable) {
                // best-effort
            }
            try {
                $this->client->disconnect();
            } catch (\Throwable) {
                // best-effort
            }
        }
        $this->client = null;
        $this->connected = false;
    }

    /**
     * Read one complete inbound data frame, blocking up to $timeout seconds.
     *
     * Returns:
     *   - the decoded payload string for a text/binary frame
     *   - {@see self::CLOSE_FRAME} for a close (or peer EOF)
     *   - null on read timeout (socket still healthy)
     *
     * Ping frames are answered with a pong and skipped; pong frames are
     * consumed; the next non-control frame (within the deadline) is returned.
     *
     * @throws \RuntimeException on a fatal socket error.
     */
    public function receive(float $timeout = 5.0): ?string
    {
        if (!$this->connected || $this->client === null) {
            throw new \RuntimeException('WebSocket: receive on closed socket');
        }

        // phrity's receive() blocks until a whole message arrives or the
        // configured stream timeout elapses (then it throws
        // ConnectionTimeoutException). Drive it with the caller's deadline so
        // the synchronous relay run-loop keeps its non-blocking-poll feel.
        $this->client->setTimeout((int) max(1, (int) ceil($timeout)));
        $deadline = microtime(true) + $timeout;

        while (true) {
            try {
                $message = $this->client->receive();
            } catch (ConnectionTimeoutException) {
                // No frame within the window — socket still healthy.
                if (microtime(true) >= $deadline) {
                    return null;
                }
                continue;
            } catch (ConnectionClosedException) {
                $this->connected = false;
                return self::CLOSE_FRAME;
            } catch (ConnectionLevelInterface $e) {
                // Any other connection-fatal error: surface to the run loop.
                $this->connected = false;
                throw new \RuntimeException('WebSocket: ' . $e->getMessage(), 0, $e);
            }

            if ($message instanceof Close) {
                $this->connected = false;
                return self::CLOSE_FRAME;
            }
            // Ping/Pong are control frames; phrity auto-replies to ping when
            // its PingInterval/CloseHandler middleware is active, but we don't
            // rely on that — just skip control frames and keep reading.
            if ($message instanceof Text || $message instanceof Binary) {
                return $message->getContent();
            }
            // Control frame (ping/pong): consume and continue until deadline.
            if (microtime(true) >= $deadline) {
                return null;
            }
        }
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->client !== null && $this->client->isConnected();
    }
}
