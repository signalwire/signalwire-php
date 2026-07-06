<?php

/**
 * wire_dump.php — the PHP port's WIRE-CRYPTO dump program for the cross-port
 * wire differ (porting-sdk/scripts/diff_port_wire.py).
 *
 * It runs the shared wire_crypto corpus against the PHP SDK's native security
 * classes (SessionManager tokens, WebhookValidator, SecurityUtils redact/filter,
 * AuthHandler verify_*) and prints ONE JSON object mapping
 *
 *     case-id -> observable-artifact
 *
 * to stdout. The differ canonicalizes both sides and byte-compares each entry
 * against the python oracle. Only stdout carries JSON; logs go to stderr.
 *
 * The corpus sentinels (__ORACLE_FORMAT_TOKEN__, __TAMPERED_TOKEN__,
 * __ORACLE_SIG__) are materialized here from the fixed per-case SECRET exactly
 * as the oracle materializes them, so the interop/tamper cases are reproducible.
 * Mirrors the Go reference dump (signalwire-go/cmd/wire-dump/main.go).
 *
 * Run from the signalwire-php repo root:
 *
 *     php scripts/wire_dump.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\Security\AuthHandler;
use SignalWire\Security\SecurityUtils;
use SignalWire\Security\SessionManager;
use SignalWire\Security\WebhookValidator;

// SECRET mirrors wire_crypto_corpus.SECRET ("a" * 64).
const SECRET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const ORACLE_EXPIRY = 9999999999;         // fixed far-future expiry (deterministic)
const ORACLE_NONCE  = '0123456789abcdef'; // fixed 16-hex nonce (deterministic)

/**
 * base64url-encode without padding (RFC 4648) — matches Python's
 * base64.urlsafe_b64encode(...).decode() with the trailing '=' the oracle keeps.
 * The oracle does NOT strip padding (urlsafe_b64encode keeps it), so neither do we.
 */
function b64url(string $raw): string
{
    return strtr(base64_encode($raw), '+/', '-_');
}

function b64urlDecode(string $data): string
{
    $b64 = strtr($data, '-_', '+/');
    $mod = strlen($b64) % 4;
    if ($mod !== 0) {
        $b64 .= str_repeat('=', 4 - $mod);
    }
    return (string) base64_decode($b64, true);
}

/**
 * oracleToken builds a token in the SDK wire format
 * (call_id.fn.expiry.nonce.sig, base64url) from the fixed SECRET — the PHP mirror
 * of diff_port_wire._oracle_token.
 */
function oracleToken(string $callId, string $fn): string
{
    $msg = "{$callId}:{$fn}:" . ORACLE_EXPIRY . ':' . ORACLE_NONCE;
    $sig = hash_hmac('sha256', $msg, SECRET);
    $raw = "{$callId}.{$fn}." . ORACLE_EXPIRY . '.' . ORACLE_NONCE . ".{$sig}";
    return b64url($raw);
}

/** tamperedToken flips one signature character — the PHP mirror of _tampered_token. */
function tamperedToken(): string
{
    $raw = b64urlDecode(oracleToken('c', 'f'));
    $parts = explode('.', $raw);
    $sig = $parts[count($parts) - 1];
    $sig = ($sig[0] !== 'f' ? 'f' : 'e') . substr($sig, 1);
    $parts[count($parts) - 1] = $sig;
    return b64url(implode('.', $parts));
}

/** oracleSig computes the correct webhook signature: hex(HMAC-SHA1(key, url+body)). */
function oracleSig(string $url, string $body, string $key): string
{
    return hash_hmac('sha1', $url . $body, $key);
}

/**
 * observeTokenFields decodes a token and returns its wire-format shape.
 *
 * @return array<string,mixed>
 */
function observeTokenFields(string $token): array
{
    $raw = b64urlDecode($token);
    $parts = explode('.', $raw); // always >=1 element
    $n = count($parts);
    $nonce = $n > 3 ? $parts[3] : '';
    $isHex = $n > 3 && preg_match('/^[0-9a-f]*$/', $nonce) === 1;
    return [
        'n_fields' => $n,
        'call_id' => $parts[0],
        'function_name' => $n > 1 ? $parts[1] : null,
        'nonce_len' => strlen($nonce),
        'nonce_is_hex' => $isHex,
    ];
}

$out = [];

// token_format: generate a token via the SDK, decode its fields.
$sm = new SessionManager(9999999999, SECRET);
$out['token_format'] = observeTokenFields($sm->generateToken('my_func', 'call_1'));

// token_nonce_distinct: two generations must differ (random nonce).
$n1 = $sm->generateToken('f', 'c');
$n2 = $sm->generateToken('f', 'c');
$out['token_nonce_distinct'] = ['distinct' => $n1 !== $n2];

// token_interop: validate an oracle-format token built from SECRET.
$out['token_interop'] = [
    'valid' => $sm->validateToken('oracle_call', 'oracle_fn', oracleToken('oracle_call', 'oracle_fn')),
];

// token_tamper_rejected: a one-byte-flipped signature must fail.
$out['token_tamper_rejected'] = [
    'valid' => $sm->validateToken('c', 'f', tamperedToken()),
];

// wire_validate_webhook_signature: correct HMAC-SHA1 -> valid.
$whUrl = 'https://example.com/hook';
$whBody = '{"event":"call.created"}';
$out['wire_validate_webhook_signature'] = [
    'valid' => WebhookValidator::validateWebhookSignature(SECRET, oracleSig($whUrl, $whBody, SECRET), $whUrl, $whBody),
];
$out['wire_validate_webhook_signature_bad'] = [
    'valid' => WebhookValidator::validateWebhookSignature(SECRET, str_repeat('deadbeef', 8), $whUrl, $whBody),
];

// ---- auth verification (basic / bearer / api-key) — oracle marks _pending; ----
// the differ skips these, but we emit them so the surface is complete.
$out['wire_verify_basic_auth'] = [
    'valid' => (new AuthHandler(basicAuth: ['u', 'p']))->verifyBasicAuth('u', 'p'),
];
$out['wire_verify_basic_auth_bad'] = [
    'valid' => (new AuthHandler(basicAuth: ['u', 'p']))->verifyBasicAuth('u', 'wrong'),
];
$out['wire_verify_bearer_token'] = [
    'valid' => (new AuthHandler(bearerToken: 'secret-bearer'))->verifyBearerToken('secret-bearer'),
];
$out['wire_verify_api_key'] = [
    'valid' => (new AuthHandler(apiKey: 'sw-key-123'))->verifyApiKey('sw-key-123'),
];

// redact_url: credentials + token redacted, structure preserved.
$out['wire_redact_url'] = [
    'redacted' => SecurityUtils::redactUrl('https://user:s3cr3t@api.signalwire.com/path?token=abc'),
];

// filter_sensitive_headers: authorization + x-api-key dropped, content-type kept.
$out['wire_filter_sensitive_headers'] = [
    'filtered' => SecurityUtils::filterSensitiveHeaders([
        'Authorization' => 'Bearer x',
        'X-Api-Key' => 'y',
        'Content-Type' => 'application/json',
    ]),
];

$encoded = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    fwrite(STDERR, 'wire-dump: json_encode failed: ' . json_last_error_msg() . "\n");
    exit(1);
}
echo $encoded, "\n";
