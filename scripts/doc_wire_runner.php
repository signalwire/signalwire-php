<?php

declare(strict_types=1);

/**
 * doc_wire_runner.php — the DOC-WIRE fixture runner for signalwire-php.
 *
 * The DOC-WIRE gate (porting-sdk `scripts/doc_wire.py`) spawns `mock_signalwire`
 * in FLAG mode, exports `MOCK_SIGNALWIRE_PORT`, then runs THIS command; it reads
 * the mock journal afterward and fails on any `wire_violations`. Our only job is
 * to DRIVE the documented REST calls against the mock so the mock journals what
 * the documented fixtures actually put on the wire.
 *
 * We replay the wire-bearing REST calls the docs teach (rest/README.md,
 * rest/docs/getting-started.md, rest/docs/calling.md) — the exact argument
 * shapes shown — so a doc lie like `area_code` (spec: `areacode`) or a flat
 * `{type:'tts', text:...}` play item (spec: nested `params:{text}`) surfaces as
 * a journaled violation and fails the gate.
 *
 * The RestClient accepts a full base URL as its `host` argument, so we point it
 * straight at the mock (exactly as tests/Rest/MockTest.php does).
 */

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\REST\RestClient;

$port = getenv('MOCK_SIGNALWIRE_PORT');
if ($port === false || trim($port) === '') {
    fwrite(STDERR, "doc_wire_runner: MOCK_SIGNALWIRE_PORT not set\n");
    exit(2);
}
$baseUrl = getenv('SIGNALWIRE_MOCK_URL');
if ($baseUrl === false || trim($baseUrl) === '') {
    $baseUrl = 'http://127.0.0.1:' . trim($port);
}

$client = new RestClient('test_proj', 'test_tok', $baseUrl);

$callId = 'call-doc-wire';

// --- rest/README.md + rest/docs/getting-started.md -----------------------
// Create an AI agent (fabric().aiAgents().create([...]))
$client->fabric()->aiAgents()->create([
    'name'   => 'Support Bot',
    'prompt' => ['text' => 'You are a helpful support agent.'],
]);

// Phone-number search — spec query param is `areacode` (not `area_code`).
$client->phoneNumbers()->search(['areacode' => '512']);
$client->phoneNumbers()->search(['areacode' => '512', 'max_results' => 3]);

// --- rest/docs/calling.md play (nested params:{text}) --------------------
$client->calling()->play($callId, [
    ['type' => 'tts', 'params' => ['text' => 'Welcome to SignalWire.']],
]);
$client->calling()->play($callId, [
    ['type' => 'tts', 'params' => ['text' => 'Welcome to our service.']],
]);

// --- rest/examples datasphere search -------------------------------------
$client->datasphere()->documents()->search('billing policy');

fwrite(STDOUT, "doc_wire_runner: replayed documented REST fixtures against the mock\n");
exit(0);
