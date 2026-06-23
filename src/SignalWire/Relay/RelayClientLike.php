<?php

declare(strict_types=1);

namespace SignalWire\Relay;

/**
 * INTERNAL duck-type contract for the RELAY client that Call and Action handles
 * drive. Mirrors the TypeScript port's `RelayClientLike` pattern: it is NOT part
 * of the public surface and is consumed only internally. It declares EXACTLY the
 * methods Call/Action invoke on the client (with Client's real signatures), so
 * the duck-typed `$client` receiver can be typed precisely without coupling the
 * action handles to the concrete Client.
 *
 * @internal
 */
interface RelayClientLike
{
    /**
     * Send a JSON-RPC request and return the "result" portion of the response.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(string $method, array $params = []): array;

    /**
     * Pump a single inbound frame through the transport (event-loop step).
     */
    public function readOnce(): void;
}
