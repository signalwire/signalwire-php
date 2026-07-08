<?php

declare(strict_types=1);

namespace SignalWire\Relay;

/**
 * Exception type for RELAY-protocol-level failures.
 *
 * Mirrors signalwire.relay.client.RelayError in the Python SDK: raised
 * when a JSON-RPC response carries an ``error`` field, when a dial
 * resolves to a failure outcome, or when an SDK-side timeout fires.
 *
 * The numeric code field carries the JSON-RPC error code where
 * applicable (e.g. -32601 method-not-found from the server) or an
 * SDK-defined sentinel (e.g. 408 for client-side timeout).
 */
class RelayError extends \RuntimeException
{
    /** JSON-RPC / SDK error code (e.g. -32601, or 408 for a client timeout). */
    public readonly int $relayCode;

    /** The RELAY error message (without the "RELAY error <code>:" prefix). */
    public readonly string $relayMessage;

    /**
     * Construct a RELAY error.
     *
     * Mirrors Python `RelayError.__init__(code, message)` — code first, then
     * message — building the "RELAY error <code>: <message>" description that
     * the base exception message carries. The numeric code is exposed via
     * {@see $relayCode} (and forwarded to the SPL exception code).
     *
     * @param int    $code    JSON-RPC error code or SDK sentinel.
     * @param string $message Human-readable RELAY error message.
     */
    public function __construct(int $code, string $message)
    {
        $this->relayCode = $code;
        $this->relayMessage = $message;
        parent::__construct("RELAY error {$code}: {$message}", $code);
    }
}
