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
}
