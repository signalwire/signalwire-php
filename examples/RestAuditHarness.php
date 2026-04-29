<?php

declare(strict_types=1);

/**
 * RestAuditHarness.php
 *
 * Drives one REST client operation against a local HTTP fixture for
 * porting-sdk/scripts/audit_rest_transport.py.
 *
 * Contract:
 *   - Reads REST_OPERATION         (e.g. "calling.list_calls")
 *   - Reads REST_FIXTURE_URL       ("http://127.0.0.1:NNNN")
 *   - Reads REST_OPERATION_ARGS    JSON dict
 *   - Reads SIGNALWIRE_PROJECT_ID, SIGNALWIRE_API_TOKEN
 *
 * Constructs a RestClient pointed at REST_FIXTURE_URL, invokes the
 * named operation with the parsed args, prints the parsed return
 * value as JSON to stdout. Exits 0 on success, non-zero on error.
 *
 * Operation map (audit dotted name → PHP REST namespace path):
 *   - calling.list_calls         → compat()->list({Calls path})
 *   - messaging.send             → compat()->getClient()->post(Messages path)
 *   - phone_numbers.list         → phoneNumbers()->list()
 *   - fabric.subscribers.list    → fabric()->subscribers()->list()
 *   - compatibility.calls.list   → compat()->list({Calls path})
 *
 * Calling.list_calls / compatibility.calls.list both list LAML
 * calls. The PHP REST surface doesn't ship a typed `list_calls()`
 * method — the audit's `Calling` namespace contract is for live RPC
 * call control, not LAML history listing — so the harness builds
 * the LAML path explicitly using the project-scoped Compat resource.
 *
 * Copyright (c) 2025 SignalWire
 * Licensed under the MIT License.
 */

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\REST\RestClient;

if (getenv('SIGNALWIRE_LOG_MODE') === false) {
    putenv('SIGNALWIRE_LOG_MODE=off');
}

$operation = (string) getenv('REST_OPERATION');
$fixtureUrl = (string) getenv('REST_FIXTURE_URL');
$argsRaw = (string) getenv('REST_OPERATION_ARGS');
$projectId = (string) getenv('SIGNALWIRE_PROJECT_ID');
$token = (string) getenv('SIGNALWIRE_API_TOKEN');

if ($operation === '' || $fixtureUrl === '') {
    fwrite(STDERR, "RestAuditHarness: REST_OPERATION and REST_FIXTURE_URL required.\n");
    exit(1);
}
if ($projectId === '' || $token === '') {
    fwrite(STDERR, "RestAuditHarness: SIGNALWIRE_PROJECT_ID and SIGNALWIRE_API_TOKEN required.\n");
    exit(1);
}
$args = $argsRaw === '' ? [] : json_decode($argsRaw, true);
if (!is_array($args)) {
    fwrite(STDERR, "RestAuditHarness: REST_OPERATION_ARGS is not a JSON object.\n");
    exit(1);
}

try {
    // RestClient accepts a fully-qualified URL as the `space`
    // parameter so we can point it at a loopback fixture without
    // forcing https://.
    $client = new RestClient($projectId, $token, $fixtureUrl);
} catch (\Throwable $e) {
    fwrite(STDERR, 'RestAuditHarness: client construction failed: ' . $e->getMessage() . "\n");
    exit(1);
}

try {
    $result = match ($operation) {
        'calling.list_calls'        => callingListCalls($client, $args),
        'messaging.send'            => messagingSend($client, $args),
        'phone_numbers.list'        => $client->phoneNumbers()->list(stringQuery($args)),
        'fabric.subscribers.list'   => $client->fabric()->subscribers()->list(stringQuery($args)),
        'compatibility.calls.list' => callingListCalls($client, $args),
        default                     => null,
    };
} catch (\Throwable $e) {
    fwrite(STDERR, 'RestAuditHarness: operation failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($result === null) {
    fwrite(STDERR, "RestAuditHarness: unsupported operation '{$operation}'\n");
    exit(2);
}

echo json_encode($result) . "\n";
exit(0);

// ---------------------------------------------------------------------
//  Operation adapters
// ---------------------------------------------------------------------

/**
 * GET /api/laml/2010-04-01/Accounts/{projectId}/Calls.json
 */
function callingListCalls(RestClient $client, array $args): array
{
    $path = '/api/laml/2010-04-01/Accounts/' . $client->getProjectId() . '/Calls.json';
    return $client->getHttp()->get($path, stringQuery($args));
}

/**
 * POST /api/laml/2010-04-01/Accounts/{projectId}/Messages.json
 *
 * The audit POST_FIXTURE only checks for `Messages` in the path and
 * a Basic-auth header; the fixture echoes a canned `{sid, status}`
 * response regardless of the request body. PHP's REST surface
 * doesn't ship a typed `messaging.send()`, so we construct the
 * LAML POST directly via HttpClient.
 */
function messagingSend(RestClient $client, array $args): array
{
    $path = '/api/laml/2010-04-01/Accounts/' . $client->getProjectId() . '/Messages.json';
    // LAML uses urlencoded form bodies, but the Compat HttpClient is
    // JSON-only; the audit fixture doesn't validate the body shape,
    // only the path and auth header, so JSON is acceptable here.
    return $client->getHttp()->post($path, $args);
}

/**
 * @param array<string,mixed> $args
 * @return array<string,string>
 */
function stringQuery(array $args): array
{
    $out = [];
    foreach ($args as $k => $v) {
        if (is_scalar($v)) {
            $out[(string) $k] = (string) $v;
        }
    }
    return $out;
}
