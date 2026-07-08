<?php

/**
 * http_dump.php — the PHP port's HTTP dump program for the cross-port HTTP
 * differ (porting-sdk/scripts/diff_port_http.py).
 *
 * For each http_corpus case it feeds a synthetic request into the PHP SDK's
 * framework-free dispatch core (Service::handleRequest, Service::extractSipUsername,
 * WebhookMiddleware::validate, and the serverless lambda adapter) and prints ONE
 * JSON object mapping
 *
 *     case-id -> reduced-artifact
 *
 * to stdout, reduced to the same shape the python oracle emits. Only stdout
 * carries JSON; logs go to stderr.
 * Mirrors the Go reference dump (signalwire-go/cmd/http-dump/main.go).
 *
 * The corpus sentinels (__AUTH__/__AUTH_BAD__ Basic headers, __SIG__ webhook
 * signature, __REDIRECT_CB__ routing callback, __HELLO_HANDLER__ SWAIG handler,
 * __JSON__: lambda body prefix) are materialized here as the oracle materializes
 * them, so the interop cases are reproducible.
 *
 * Run from the signalwire-php repo root:
 *
 *     php scripts/http_dump.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\Security\WebhookMiddleware;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\SWML\Service;

const HTTP_USER = 'user';
const HTTP_PASS = 'pass';
const SIGNING_KEY = 'PSK-fixed-signing-key';
const WH_URL = 'https://agent.example.com/webhook';
const WH_BODY = '{"event":"call.created","id":"abc"}';

function basicAuth(string $u, string $p): string
{
    return 'Basic ' . base64_encode("{$u}:{$p}");
}

function webhookSig(string $url, string $body, string $key): string
{
    return hash_hmac('sha1', $url . $body, $key);
}

/** Extract the path portion of a URL (Python's handle_request takes the full URL; PHP takes a path). */
function urlPath(string $url): string
{
    $p = parse_url($url, PHP_URL_PATH);
    return is_string($p) && $p !== '' ? $p : '/';
}

/**
 * observeResponse reduces a (status, headers, body) triple to a comparable
 * artifact — the PHP mirror of diff_port_http._observe_response.
 *
 * @param array<string,string> $headers
 * @return array<string,mixed>
 */
function observeResponse(int $status, array $headers, string $bodyStr, string $kind): array
{
    $keys = array_keys($headers);
    sort($keys);
    $out = ['status' => $status, 'header_keys' => $keys];
    if (isset($headers['Location'])) {
        $out['location'] = $headers['Location'];
    }
    if (isset($headers['WWW-Authenticate'])) {
        $out['www_authenticate'] = $headers['WWW-Authenticate'];
    }
    if ($kind === 'response_full') {
        if ($bodyStr === '') {
            $out['body'] = '';
        } else {
            $parsed = json_decode($bodyStr, true);
            $out['body'] = $parsed === null && json_last_error() !== JSON_ERROR_NONE ? $bodyStr : $parsed;
        }
    }
    return $out;
}

function newService(): Service
{
    return new Service(name: 'demo', route: '/swml', basicAuthUser: HTTP_USER, basicAuthPassword: HTTP_PASS);
}

/**
 * redirectCB redirects one specific 'to', else passes through (null).
 *
 * @param array<string,mixed> $body
 * @param array<string,mixed> $headers
 */
function redirectCB(array $body, array $headers): ?string
{
    $call = $body['call'] ?? null;
    $to = is_array($call) ? ($call['to'] ?? null) : null;
    return $to === 'sip:redirect-me@space' ? '/other-route' : null;
}

$out = [];

// ---- handle_request: 200 SWML happy path ----
$svc = newService();
[$s, $h, $b] = $svc->handleRequest('GET', urlPath('http://localhost:3000/swml'), ['Authorization' => basicAuth(HTTP_USER, HTTP_PASS)], null);
$out['http_handle_request_200_swml'] = observeResponse($s, $h, $b, 'response_full');

// ---- handle_request: 401 no auth ----
$svc = newService();
[$s, $h, $b] = $svc->handleRequest('GET', urlPath('http://localhost:3000/swml'), [], null);
$out['http_handle_request_401_no_auth'] = observeResponse($s, $h, $b, 'response_full');

// ---- handle_request: 401 bad password (status+headers only) ----
$svc = newService();
[$s, $h, $b] = $svc->handleRequest('GET', urlPath('http://localhost:3000/swml'), ['Authorization' => basicAuth(HTTP_USER, 'wrong')], null);
$out['http_handle_request_401_bad_password'] = observeResponse($s, $h, $b, 'response_status_headers');

// ---- handle_request: 307 redirect via routing callback ----
$svc = newService();
$svc->registerRoutingCallback('/sip', 'redirectCB');
[$s, $h, $b] = $svc->handleRequest(
    'POST',
    urlPath('http://localhost:3000/swml/sip'),
    ['Authorization' => basicAuth(HTTP_USER, HTTP_PASS)],
    (string) json_encode(['call' => ['to' => 'sip:redirect-me@space']]),
);
$out['http_handle_request_307_redirect'] = observeResponse($s, $h, $b, 'response_full');

// ---- handle_request: callback returns null -> normal 200 SWML ----
$svc = newService();
$svc->registerRoutingCallback('/sip', 'redirectCB');
[$s, $h, $b] = $svc->handleRequest(
    'POST',
    urlPath('http://localhost:3000/swml/sip'),
    ['Authorization' => basicAuth(HTTP_USER, HTTP_PASS)],
    (string) json_encode(['call' => ['to' => 'sip:keep@space']]),
);
$out['http_handle_request_callback_passthrough_200'] = observeResponse($s, $h, $b, 'response_full');

// ---- extract_sip_username: pure extractor ----
$extract = static function (array $body): array {
    $u = Service::extractSipUsername($body);
    return ['username' => $u];
};
$out['http_extract_sip_username_sip'] = $extract(['call' => ['to' => 'sip:alice@agents.signalwire.com']]);
$out['http_extract_sip_username_tel'] = $extract(['call' => ['to' => 'tel:+15551234567']]);
$out['http_extract_sip_username_plain'] = $extract(['call' => ['to' => 'support']]);
$out['http_extract_sip_username_missing'] = $extract(['vars' => []]);

// ---- webhook validate ----
$decision = static function (string $method, string $url, string $body, array $headers, string $key): array {
    $rej = WebhookMiddleware::validate($method, $url, $headers, $body, $key);
    return $rej === null ? ['decision' => 'pass'] : ['decision' => 'reject', 'status' => $rej[0]];
};
$out['http_webhook_validate_ok'] = $decision('POST', WH_URL, WH_BODY, ['x-signalwire-signature' => webhookSig(WH_URL, WH_BODY, SIGNING_KEY)], SIGNING_KEY);
$out['http_webhook_validate_bad_sig'] = $decision('POST', WH_URL, WH_BODY, ['x-signalwire-signature' => str_repeat('deadbeef', 5)], SIGNING_KEY);
$out['http_webhook_validate_missing_sig'] = $decision('POST', WH_URL, WH_BODY, [], SIGNING_KEY);
$out['http_webhook_validate_twilio_alias'] = $decision('POST', WH_URL, WH_BODY, ['x-twilio-signature' => webhookSig(WH_URL, WH_BODY, SIGNING_KEY)], SIGNING_KEY);

// ---- serverless (lambda) ----
// Build the agent at route "/" so the event's root-relative "/swaig" path routes
// (mirrors the Go reference dump; Python's serverless dispatch strips the route).
$out['http_serverless_lambda_swaig'] = serverlessSwaig();
$out['http_serverless_lambda_noauth_401'] = serverlessNoAuth();

/** @return array<string,mixed> */
function serverlessSwaig(): array
{
    $a = new AgentBase(name: 'demo', route: '/', basicAuthUser: HTTP_USER, basicAuthPassword: HTTP_PASS);
    $a->defineTool(
        name: 'say_hello',
        description: 'greet',
        parameters: [],
        handler: static fn (array $args, array $rawData): FunctionResult => new FunctionResult('hello there'),
    );
    $event = [
        'rawPath' => '/swaig',
        'headers' => [
            'authorization' => basicAuth(HTTP_USER, HTTP_PASS),
            'content-type' => 'application/json',
        ],
        'body' => '{"function":"say_hello","argument":{"parsed":[{}]},"call_id":"c1"}',
        'requestContext' => ['http' => ['method' => 'POST']],
    ];
    return reduceLambda($a->handleServerlessRequest(event: $event, mode: 'lambda'));
}

/** @return array<string,mixed> */
function serverlessNoAuth(): array
{
    $a = new AgentBase(name: 'demo', route: '/', basicAuthUser: HTTP_USER, basicAuthPassword: HTTP_PASS);
    $event = [
        'rawPath' => '/',
        'headers' => [],
        'body' => null,
        'requestContext' => ['http' => ['method' => 'GET']],
    ];
    return reduceLambda($a->handleServerlessRequest(event: $event, mode: 'lambda'));
}

/**
 * reduceLambda reduces a lambda response to {status, body} with the body parsed
 * as JSON — mirroring the oracle's serverless_result observer.
 *
 * @param array<string,mixed>|null $resp
 * @return array<string,mixed>
 */
function reduceLambda(?array $resp): array
{
    $resp ??= [];
    $bodyStr = is_string($resp['body'] ?? null) ? $resp['body'] : '';
    $body = $bodyStr;
    if ($bodyStr !== '') {
        $parsed = json_decode($bodyStr, true);
        if (!($parsed === null && json_last_error() !== JSON_ERROR_NONE)) {
            $body = $parsed;
        }
    }
    return ['status' => $resp['statusCode'] ?? null, 'body' => $body];
}

$encoded = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    fwrite(STDERR, 'http-dump: json_encode failed: ' . json_last_error_msg() . "\n");
    exit(1);
}
echo $encoded, "\n";
