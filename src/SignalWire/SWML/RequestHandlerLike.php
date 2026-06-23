<?php

declare(strict_types=1);

namespace SignalWire\SWML;

/**
 * INTERNAL duck-type contract for the HTTP request handler that the serverless
 * Adapter drives. The Adapter is the PHP analogue of the TypeScript port's
 * adapter that takes the converted app/router (`{ fetch }`) rather than an
 * AgentBase: it invokes only the request-serving surface — handleRequest() and
 * run() — never agent-config methods. This interface declares exactly those two
 * methods (with Service's real signatures) so the Adapter's `$agent` receiver
 * can be typed precisely.
 *
 * Not part of the public surface; consumed only internally.
 *
 * @internal
 */
interface RequestHandlerLike
{
    /**
     * Handle an HTTP request. Returns [status, headers, body].
     *
     * @param array<string, string> $headers
     * @return array{int, array<string, string>, string}
     */
    public function handleRequest(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
    ): array;

    /**
     * Run the service (blocking serve loop).
     */
    public function run(): void;
}
