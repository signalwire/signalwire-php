<?php

declare(strict_types=1);
/**
 * Example: Deploy a voice application end-to-end with SWML and call flows.
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

// 1. Create a SWML script
echo "Creating SWML script...\n";
$swml = $client->fabric()->swmlScripts()->create([
    'name'     => 'Greeting Script',
    'contents' => [
        'sections' => [
            'main' => [['play' => ['url' => 'say:Hello from SignalWire']]],
        ],
    ],
]);
$swmlId = field($swml, 'id', 'demo-swml-id');
echo "  Created SWML script: {$swmlId}\n";

// 2. List SWML scripts
echo "\nListing SWML scripts...\n";
$scripts = $client->fabric()->swmlScripts()->list();
foreach (dataRows($scripts) as $s) {
    echo '  - ' . field($s, 'id') . ': ' . field($s, 'display_name', 'unnamed') . "\n";
}

// 3. Create a call flow
echo "\nCreating call flow...\n";
$flow = $client->fabric()->callFlows()->create(['title' => 'Main IVR Flow']);
$flowId = field($flow, 'id', 'demo-flow-id');
echo "  Created call flow: {$flowId}\n";

// 4. Deploy a version
echo "\nDeploying call flow version...\n";
// Deploying snapshots the current call-flow definition as a new version; the
// deploy request itself carries no body fields (the version is server-assigned).
safe(
    'Deploy version',
    fn () =>
    $client->fabric()->callFlows()->deployVersion($flowId, [])
);

// 5. List call flow versions
echo "\nListing call flow versions...\n";
safe('List versions', function () use ($client, $flowId) {
    $versions = $client->fabric()->callFlows()->listVersions($flowId);
    foreach (dataRows($versions) as $v) {
        echo '  - Version: ' . fieldAny($v, 'label', 'id', 'unknown') . "\n";
    }
});

// 6. List addresses for the call flow
echo "\nListing call flow addresses...\n";
safe('List addresses', function () use ($client, $flowId) {
    $addrs = $client->fabric()->callFlows()->listAddresses($flowId);
    foreach (dataRows($addrs) as $a) {
        echo '  - ' . fieldAny($a, 'display_name', 'id', 'unknown') . "\n";
    }
});

// 7. Create a SWML webhook
echo "\nCreating SWML webhook...\n";
$webhook = $client->fabric()->swmlWebhooks()->create([
    'name'                => 'External Handler',
    'primary_request_url' => 'https://example.com/swml-handler',
]);
$webhookId = field($webhook, 'id', 'demo-webhook-id');
echo "  Created webhook: {$webhookId}\n";

// 8. Clean up
echo "\nCleaning up...\n";
$client->fabric()->swmlWebhooks()->delete($webhookId);
echo "  Deleted webhook {$webhookId}\n";
$client->fabric()->callFlows()->delete($flowId);
echo "  Deleted call flow {$flowId}\n";
$client->fabric()->swmlScripts()->delete($swmlId);
echo "  Deleted SWML script {$swmlId}\n";
