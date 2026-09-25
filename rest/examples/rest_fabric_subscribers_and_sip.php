<?php

declare(strict_types=1);
/**
 * Example: Provision a SIP-enabled user on Fabric.
 *
 * Set these env vars:
 *   SIGNALWIRE_PROJECT_ID   - your SignalWire project ID
 *   SIGNALWIRE_API_TOKEN    - your SignalWire API token
 *   SIGNALWIRE_SPACE        - your SignalWire space
 */

require 'vendor/autoload.php';

use SignalWire\REST\RestClient;

/** Read a required setting from the environment, or stop with a clear message. */
function env(string $name): string
{
    $value = $_ENV[$name] ?? null;
    if (!is_string($value) || $value === '') {
        exit("Set {$name}\n");
    }
    return $value;
}

$client = new RestClient(
    project: env('SIGNALWIRE_PROJECT_ID'),
    token:   env('SIGNALWIRE_API_TOKEN'),
    host:    env('SIGNALWIRE_SPACE'),
);

/**
 * Run an SDK call, reporting OK/failed instead of aborting the demo.
 *
 * Every REST method returns the decoded JSON body as array<string,mixed>, so
 * that is what a success yields; a failure yields null.
 *
 * @param callable(): mixed $fn
 * @return array<array-key,mixed>|null
 */
function safe(string $label, callable $fn): ?array
{
    try {
        $result = $fn();
        $result = is_array($result) ? $result : null;
        echo "  {$label}: OK\n";
        return $result;
    } catch (\Exception $e) {
        echo "  {$label}: failed ({$e->getMessage()})\n";
        return null;
    }
}

/**
 * Read a string field out of a decoded response row.
 *
 * REST bodies are array<string,mixed> — the server decides the shape — so a
 * field is `mixed` until checked. Numbers are stringified (an id may arrive as
 * either); anything else yields $default.
 */
function field(mixed $row, string $key, string $default = ''): string
{
    if (!is_array($row)) {
        return $default;
    }
    $value = $row[$key] ?? null;
    if (is_string($value)) {
        return $value;
    }

    return is_int($value) || is_float($value) ? (string) $value : $default;
}

/**
 * Narrow a `mixed` to a list that is safe to foreach/count/array_slice.
 * A missing or non-array value yields an empty list.
 *
 * @return list<mixed>
 */
function rows(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * The `data` collection of a list response, narrowed to a list.
 *
 * @return list<mixed>
 */
function dataRows(mixed $response): array
{
    return is_array($response) ? rows($response['data'] ?? []) : [];
}

/**
 * The first present string field, in order — for responses where the same
 * value travels under more than one name (e.g. `e164` or `number`).
 */
function fieldAny(mixed $row, string $first, string $second, string $default = ''): string
{
    $value = field($row, $first, '');

    return $value !== '' ? $value : field($row, $second, $default);
}

/** The first row of a list response, or an empty array. */
function firstRow(mixed $response): mixed
{
    return dataRows($response)[0] ?? [];
}

// 1. Create a subscriber
echo "Creating subscriber...\n";
$subscriber = $client->fabric()->subscribers()->create([
    'email'      => 'alice@example.com',
    'first_name' => 'Alice',
    'last_name'  => 'Johnson',
]);
$subId      = field($subscriber, 'id', 'demo-subscriber-id');
$innerSubId = field($subscriber['subscriber'] ?? null, 'id', $subId);
echo "  Created subscriber: {$subId}\n";

// 2. Add a SIP endpoint
echo "\nCreating SIP endpoint on subscriber...\n";
$endpoint = $client->fabric()->subscribers()->createSipEndpoint(
    $subId,
    username: 'alice_sip',
    password: 'SecurePass123!',
);
$epId = field($endpoint, 'id', 'demo-endpoint-id');
echo "  Created SIP endpoint: {$epId}\n";

// 3. List SIP endpoints
echo "\nListing subscriber SIP endpoints...\n";
$endpoints = $client->fabric()->subscribers()->listSipEndpoints($subId);
foreach (dataRows($endpoints) as $ep) {
    echo '  - ' . field($ep, 'id') . ': ' . field($ep, 'username', 'unknown') . "\n";
}

// 4. Get specific endpoint details
echo "\nGetting SIP endpoint {$epId}...\n";
$epDetail = $client->fabric()->subscribers()->getSipEndpoint($subId, $epId);
echo '  Username: ' . field($epDetail, 'username', 'N/A') . "\n";

// 5. Create a standalone SIP gateway
echo "\nCreating SIP gateway...\n";
$gateway = $client->fabric()->sipGateways()->create([
    'name'       => 'Office PBX Gateway',
    'uri'        => 'sip:pbx.example.com',
    'encryption' => 'required',
    'ciphers'    => ['AES_256_CM_HMAC_SHA1_80'],
    'codecs'     => ['PCMU', 'PCMA'],
]);
$gwId = field($gateway, 'id', 'demo-gateway-id');
echo "  Created SIP gateway: {$gwId}\n";

// 6. List fabric addresses
echo "\nListing fabric addresses...\n";
safe('List addresses', function () use ($client) {
    $addresses = $client->fabric()->addresses()->list();
    foreach (array_slice(dataRows($addresses), 0, 5) as $addr) {
        echo '  - ' . fieldAny($addr, 'display_name', 'id', 'unknown') . "\n";
    }

    // 7. Get a specific address
    $firstAddrId = field(firstRow($addresses), 'id');
    if ($firstAddrId !== '') {
        $addrDetail = $client->fabric()->addresses()->get($firstAddrId);
        echo '  Address detail: ' . field($addrDetail, 'display_name', 'N/A') . "\n";
    }
});

// 8. Generate a subscriber token
echo "\nGenerating subscriber token...\n";
safe('Subscriber token', function () use ($client, $innerSubId) {
    $token = $client->fabric()->tokens()->createSubscriberToken(
        reference: $innerSubId,
    );
    $t = field($token, 'token', '');
    if ($t) {
        echo '  Token: ' . substr($t, 0, 40) . "...\n";
    }
});

// 9. Clean up
echo "\nCleaning up...\n";
$client->fabric()->subscribers()->deleteSipEndpoint($subId, $epId);
echo "  Deleted SIP endpoint {$epId}\n";
$client->fabric()->subscribers()->delete($subId);
echo "  Deleted subscriber {$subId}\n";
$client->fabric()->sipGateways()->delete($gwId);
echo "  Deleted SIP gateway {$gwId}\n";
