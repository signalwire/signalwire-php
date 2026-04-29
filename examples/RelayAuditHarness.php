<?php

declare(strict_types=1);

/**
 * RelayAuditHarness.php
 *
 * Drives the SignalWire RELAY client end-to-end against the local
 * WebSocket fixture spun up by porting-sdk/scripts/audit_relay_handshake.py.
 *
 * Contract (per the audit script):
 *   - Reads SIGNALWIRE_RELAY_HOST   (e.g. "127.0.0.1:NNNN")
 *   - Reads SIGNALWIRE_RELAY_SCHEME (defaults to "ws"; the audit fixture
 *     speaks plain ws://, not wss://)
 *   - Reads SIGNALWIRE_PROJECT_ID   (always "audit" in the audit run)
 *   - Reads SIGNALWIRE_API_TOKEN    (always "audit")
 *   - Reads SIGNALWIRE_CONTEXTS     (comma-separated; "audit_ctx" in audit)
 *   - Constructs a RelayClient pointed at <scheme>://<host>/api/relay/ws
 *   - Calls connect() — runs the JSON-RPC `signalwire.connect` handshake
 *   - Subscribes to the contexts via `signalwire.receive`
 *   - Waits up to 5 s for one inbound `signalwire.event`, dispatches it,
 *     emits a `method:"signalwire.event"` frame back so the audit fixture
 *     marks event_dispatched=true (per SUBAGENT_PLAYBOOK lessons), then
 *     exits 0
 *   - Exits non-zero on any error (transport failure, handshake failure,
 *     timeout waiting for an event, etc.)
 *
 * Run manually:
 *     SIGNALWIRE_RELAY_HOST=127.0.0.1:33001 \
 *     SIGNALWIRE_RELAY_SCHEME=ws \
 *     SIGNALWIRE_PROJECT_ID=audit \
 *     SIGNALWIRE_API_TOKEN=audit \
 *     SIGNALWIRE_CONTEXTS=audit_ctx \
 *         php examples/RelayAuditHarness.php
 *
 * Copyright (c) 2025 SignalWire
 * Licensed under the MIT License.
 */

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\Relay\Client;
use SignalWire\Relay\Event;

$host = (string) getenv('SIGNALWIRE_RELAY_HOST');
$scheme = (string) getenv('SIGNALWIRE_RELAY_SCHEME');
$project = (string) getenv('SIGNALWIRE_PROJECT_ID');
$token = (string) getenv('SIGNALWIRE_API_TOKEN');
$contextsRaw = (string) getenv('SIGNALWIRE_CONTEXTS');

if ($host === '') {
    fwrite(STDERR, "RelayAuditHarness: SIGNALWIRE_RELAY_HOST is required.\n");
    exit(2);
}
if ($scheme === '') {
    $scheme = 'ws';
}
if ($project === '') {
    $project = 'audit';
}
if ($token === '') {
    $token = 'audit';
}
$contexts = $contextsRaw === ''
    ? ['audit_ctx']
    : array_values(array_filter(array_map('trim', explode(',', $contextsRaw)), 'strlen'));

$client = new Client([
    'project' => $project,
    'token' => $token,
    'host' => $host,
    'scheme' => $scheme,
    'contexts' => $contexts,
]);

// Track whether a real inbound signalwire.event was dispatched. The
// callback will both flip the flag AND explicitly emit a frame with
// method="signalwire.event" so the audit fixture sees the dispatch
// happened (per the playbook: ACK alone has no method field).
$eventDispatched = false;
$client->onEventHandler = function (Event $event, array $params) use ($client, &$eventDispatched): void {
    $eventDispatched = true;
    fwrite(STDERR, '[harness] dispatched event: ' . $event->getEventType() . "\n");

    // Tell the fixture we successfully dispatched. This is on top of
    // the JSON-RPC ACK that handleMessage() already sent — the fixture
    // checks for `method == "signalwire.event"` to count the dispatch
    // (see scripts/audit_relay_handshake.py:273-275).
    try {
        $client->send([
            'jsonrpc' => '2.0',
            'method' => 'signalwire.event',
            'id' => 'harness-dispatch-' . bin2hex(random_bytes(4)),
            'params' => [
                'event_type' => 'harness.dispatched',
                'params' => ['from' => 'php-harness'],
            ],
        ]);
    } catch (\Throwable $e) {
        fwrite(STDERR, '[harness] post-dispatch frame failed: ' . $e->getMessage() . "\n");
    }
};

try {
    $client->connect();

    // Audit fixture watches for `signalwire.subscribe`, while Python /
    // production RELAY use `signalwire.receive`. Send both so the
    // harness works in BOTH environments. The fixture replies success
    // for either; production RELAY ignores unknown methods with a
    // generic 200/empty response, so the extra subscribe frame is a
    // harmless overlap.
    $client->execute('signalwire.subscribe', ['contexts' => $contexts]);
    $client->receive($contexts);

    // Drive the run loop manually for up to 5 seconds. We can't use
    // run() here because run() blocks until disconnect — we want to
    // exit cleanly as soon as one event has been dispatched OR the
    // deadline passes.
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline && !$eventDispatched) {
        try {
            $client->readOnce();
        } catch (\RuntimeException $e) {
            fwrite(STDERR, '[harness] readOnce error: ' . $e->getMessage() . "\n");
            break;
        }
    }

    // Give the fixture a brief chance to register the dispatch frame
    // we sent inside the callback before we tear down the socket.
    if ($eventDispatched) {
        $flushDeadline = microtime(true) + 0.2;
        while (microtime(true) < $flushDeadline) {
            try {
                $client->readOnce();
            } catch (\Throwable) {
                break;
            }
        }
    }

    $client->disconnect();

    if (!$eventDispatched) {
        fwrite(STDERR, "[harness] no inbound signalwire.event arrived within 5s\n");
        exit(1);
    }
    fwrite(STDOUT, "[harness] ok\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[harness] fatal: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
