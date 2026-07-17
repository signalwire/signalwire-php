<?php

/**
 * emit_error_envelope.php — the PHP port's ENVELOPE-DUMP program for the
 * cross-port REST error-ENVELOPE behavioral differ
 * (porting-sdk/scripts/diff_port_envelope.py).
 *
 * A wire-shape check (REST-COVERAGE) proves a route's SUCCESS body and that AN
 * error status surfaces; it cannot express HOW the REST client handles an error
 * envelope, a 429/503, a malformed body, or a connection refused. This program
 * pins that behavior: it drives the SAME corpus the differ's Python oracle runs
 * (porting-sdk/scripts/envelope_corpus.py — the single source of truth) against
 * a live mock_signalwire, and for each case observes the RAISED typed error
 * reduced to the shared cross-port artifact:
 *
 *     {
 *       "raised": bool,            # a typed error was raised (vs a success)
 *       "error_kind": "typed"|"bare:<name>"|null,
 *                                  # "typed" == a member of the SignalWireRestError
 *                                  #   family; "bare:<Class>" == a leaked exception
 *       "status_code": int|null,   # the HTTP status the client decoded (null for
 *                                  #   a transport failure — no response reached)
 *       "body_error_code": string|null,  # errors[0].code decoded from the body
 *       "request_count": int       # journal hits for the path (1 == no retry,
 *                                  #   0 == transport: nothing reached the server)
 *     }
 *
 * It prints ONE JSON object mapping corpus-id -> artifact to stdout; the differ
 * byte-compares each entry against Python's golden oracle. Mirrors the emission
 * dump (scripts/emit_corpus.php) and the differ's per-port dump contract.
 *
 * The corpus is duplicated here (there is no cross-language corpus loader) — it
 * MUST stay in lock-step with porting-sdk/scripts/envelope_corpus.py. The differ
 * is tolerant of pending ids, but a stale/skewed local corpus would mask a real
 * diff, so keep the CASES table below identical to the oracle's.
 *
 * A ``transport`` case exercises the connection-refused path: the client is
 * pointed at a DEAD port (a free port bound then released, nothing listening),
 * so no mock scenario is armed and request_count is 0. A correct client raises
 * its TYPED transport error (SignalWireRestTransportError, a member of the
 * SignalWireRestError family, status 0 -> reported as null); a client leaking a
 * bare cURL exception would report "bare:<name>" and fail the byte-compare.
 *
 * Run from the signalwire-php repo root:
 *
 *     php scripts/emit_error_envelope.php
 *
 * The mock server is booted/reused via the test Harness (probe-or-spawn on
 * MOCK_SIGNALWIRE_PORT, or a dynamically-picked free port).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\REST\HttpClient;
use SignalWire\REST\SignalWireRestError;
use SignalWire\Tests\Rest\MockTest;

// The endpoint every mock-armed case targets: a list route in all 10 ports'
// REST clients. operationId is the mock's scenario/journal dispatch key.
const ENDPOINT = 'fabric.list_fabric_addresses';
const CALL_METHOD = 'GET';
const CALL_PATH = '/api/fabric/addresses';

/**
 * The PHP-native mirror of porting-sdk/scripts/envelope_corpus.py CORPUS. Each
 * entry: id => [scenario|null, transport]. scenario is the mock override
 * {status, response} (response may be a JSON object or a raw string for the
 * malformed-body case); null => no override (the mock synthesizes a 200).
 * transport=true => the connection-refused path (no mock; dead port).
 *
 * @return array<string, array{scenario: array{status:int, response:mixed}|null, transport: bool}>
 */
function corpus(): array
{
    return [
        // 200 success baseline: no error, nothing raised.
        'envelope_200_success' => ['scenario' => null, 'transport' => false],

        // 404 well-formed errors[] envelope: typed error, decoded NOT_FOUND.
        'envelope_404_typed' => [
            'scenario' => ['status' => 404, 'response' => ['errors' => [
                ['code' => 'NOT_FOUND', 'message' => 'no such address'],
            ]]],
            'transport' => false,
        ],

        // 429 + Retry-After: NO port retries — typed error on the first response.
        // (The Retry-After header is not in the observed artifact, so arming
        // status+body is sufficient to reproduce the golden values.)
        'envelope_429_retry_after' => [
            'scenario' => ['status' => 429, 'response' => ['errors' => [
                ['code' => 'RATE_LIMITED', 'message' => 'slow down'],
            ]]],
            'transport' => false,
        ],

        // 503 service-unavailable: typed error immediately, no backoff/retry.
        'envelope_503_unavailable' => [
            'scenario' => ['status' => 503, 'response' => ['errors' => [
                ['code' => 'UNAVAILABLE', 'message' => 'maintenance'],
            ]]],
            'transport' => false,
        ],

        // 500 with a NON-JSON body: still raise the typed error (status 500),
        // do not crash decoding; body_error_code is null (no errors[]).
        'envelope_500_malformed_body' => [
            'scenario' => ['status' => 500, 'response' => 'not-json-at-all <garbage'],
            'transport' => false,
        ],

        // 200 whose body carries errors[]: 2xx == success, so NOTHING is raised.
        'envelope_200_with_error_body' => [
            'scenario' => ['status' => 200, 'response' => ['errors' => [
                ['code' => 'SOFT_FAIL', 'message' => 'ignored on 2xx'],
            ]]],
            'transport' => false,
        ],

        // A 503 (the differ oracle delays it 200ms; the delay is not in the
        // artifact, so an un-delayed 503 reproduces the same golden values):
        // one typed 503 error, no retry.
        'envelope_503_delayed' => [
            'scenario' => ['status' => 503, 'response' => ['errors' => [
                ['code' => 'UNAVAILABLE', 'message' => 'slow-fail'],
            ]]],
            'transport' => false,
        ],

        // Connection refused (dead port): the client must raise its TYPED
        // transport error, NOT a bare cURL exception. request_count == 0.
        'envelope_transport_refused' => ['scenario' => null, 'transport' => true],
    ];
}

/**
 * Pick a free TCP port (bind :0 on loopback, read it back, release) — a DEAD
 * port for the connection-refused case: nothing is listening once released.
 */
function deadPort(): int
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        fwrite(STDERR, "emit_error_envelope: cannot bind a probe socket: $errstr\n");
        exit(1);
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    if (!is_string($name)) {
        fwrite(STDERR, "emit_error_envelope: cannot read probe socket name\n");
        exit(1);
    }
    return (int) substr($name, strrpos($name, ':') + 1);
}

/**
 * Decode errors[0].code out of a raw response body (JSON string), or null when
 * the body is non-JSON / has no errors[]. Mirrors the differ's
 * _decode_body_error_code so the artifact is the same denominator everywhere.
 */
function decodeBodyErrorCode(string $body): ?string
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return null;
    }
    $errs = $decoded['errors'] ?? null;
    if (!is_array($errs) || $errs === []) {
        return null;
    }
    $first = $errs[0] ?? null;
    if (!is_array($first)) {
        return null;
    }
    $code = $first['code'] ?? null;
    return is_string($code) ? $code : null;
}

// --- boot the mock, then run every case -------------------------------------

$harness = MockTest::harness();
$mockUrl = $harness->url();

// A unique project => a unique Basic-Auth header, so the mock's scenario store
// and journal are session-scoped to THIS run (no cross-run contamination, exact
// per-case request_count under a shared mock).
$project = 'envelope_php_' . bin2hex(random_bytes(6));
$token = 'envelope_tok';
$authHeader = 'Basic ' . base64_encode($project . ':' . $token);

$scenarios = $harness->scenarios();
$scenarios->scopeTo($authHeader);
$journal = $harness->journal();
$journal->scopeTo($authHeader);

/** @var array<string, array{raised:bool, error_kind:?string, status_code:?int, body_error_code:?string, request_count:int}> $out */
$out = [];

foreach (corpus() as $cid => $case) {
    // Fresh scenarios + journal per case so request_count is exact.
    $scenarios->reset();
    resetJournal($mockUrl); // global journal reset (scoped reset is a no-op)

    if ($case['transport']) {
        $baseUrl = 'http://127.0.0.1:' . deadPort();
    } else {
        $baseUrl = $mockUrl;
        if ($case['scenario'] !== null) {
            armScenario($mockUrl, ENDPOINT, $authHeader, $case['scenario']);
        }
    }
    // Carry the non-empty invariant to HttpClient's non-empty-string $baseUrl:
    // the mock url is non-empty and the dead-port url always has a host:port.
    assert($baseUrl !== '');

    $client = new HttpClient($project, $token, $baseUrl);

    $artifact = [
        'raised' => false,
        'error_kind' => null,
        'status_code' => null,
        'body_error_code' => null,
        'request_count' => 0,
    ];

    try {
        $client->get(CALL_PATH);
    } catch (SignalWireRestError $e) {
        // A member of the typed error family (HTTP error OR transport error).
        $artifact['raised'] = true;
        $artifact['error_kind'] = 'typed';
        $status = $e->getStatusCode();
        // status 0 == a transport failure (no HTTP response); report null so the
        // artifact matches the oracle (python raises status_code=None there).
        $artifact['status_code'] = $status === 0 ? null : $status;
        $artifact['body_error_code'] = decodeBodyErrorCode($e->getBody());
    } catch (\Throwable $e) {
        // A leaked, non-family exception — the contract violation the gate catches.
        $artifact['raised'] = true;
        $artifact['error_kind'] = 'bare:' . (new \ReflectionClass($e))->getShortName();
    }

    // Count journal hits for the path (retry check: 1 == no retry, 0 == the
    // transport case never reached the server).
    $count = 0;
    foreach ($journal->all() as $entry) {
        if ($entry->path === CALL_PATH) {
            $count++;
        }
    }
    $artifact['request_count'] = $count;

    $out[$cid] = $artifact;
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

// --- mock control helpers (session-scoped scenario + global journal reset) ---

/**
 * Arm a one-shot response override for the operation, scoped to this run's
 * auth header (server-side ?session_id=), matching the differ oracle.
 *
 * @param array{status:int, response:mixed} $scenario
 */
function armScenario(string $mockUrl, string $operationId, string $authHeader, array $scenario): void
{
    $payload = json_encode(
        ['status' => $scenario['status'], 'response' => $scenario['response']],
        JSON_THROW_ON_ERROR
    );
    postJson(
        $mockUrl . '/__mock__/scenarios/' . rawurlencode($operationId)
        . '?session_id=' . rawurlencode($authHeader),
        $payload
    );
}

function resetJournal(string $mockUrl): void
{
    post($mockUrl . '/__mock__/journal/reset');
}

function post(string $url): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function postJson(string $url, string $json): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    curl_exec($ch);
    curl_close($ch);
}
