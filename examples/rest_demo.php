<?php

declare(strict_types=1);
/**
 * REST Client Demo
 *
 * Shows how to use the REST client to manage SignalWire resources.
 *
 * Set these env vars:
 *   SIGNALWIRE_PROJECT_ID
 *   SIGNALWIRE_API_TOKEN
 *   SIGNALWIRE_SPACE
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
 * Run an SDK call, reporting OK/FAILED instead of aborting the demo.
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
        echo "  {$label}: FAILED - {$e->getMessage()}\n";
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

// 1. List phone numbers
echo "Listing phone numbers...\n";
$numbers = safe('List numbers', fn () => $client->phoneNumbers->list());
if ($numbers) {
    foreach (array_slice(dataRows($numbers), 0, 5) as $n) {
        echo '    - ' . field($n, 'number', 'unknown') . "\n";
    }
}

// 2. Search available numbers
echo "\nSearching available numbers...\n";
safe('Search 512', function () use ($client) {
    $avail = $client->phoneNumbers->search(['areacode' => '512', 'max_results' => 3]);
    foreach (dataRows($avail) as $n) {
        echo '    - ' . ($n['e164'] ?? $n['number'] ?? 'unknown') . "\n";
    }
});

// 3. List AI agents
echo "\nListing AI agents...\n";
safe('List agents', function () use ($client) {
    $agents = $client->fabric->aiAgents->list();
    foreach (dataRows($agents) as $a) {
        echo '    - ' . field($a, 'id') . ': ' . field($a, 'name', 'unnamed') . "\n";
    }
});

// 4. Datasphere documents
echo "\nListing Datasphere documents...\n";
safe('List documents', function () use ($client) {
    $docs = $client->datasphere->documents->list();
    foreach (dataRows($docs) as $d) {
        echo '    - ' . field($d, 'id') . ': ' . field($d, 'status', 'unknown') . "\n";
    }
});

// 5. Video rooms
echo "\nListing video rooms...\n";
safe('List rooms', function () use ($client) {
    $rooms = $client->video->rooms->list();
    foreach (dataRows($rooms) as $r) {
        echo '    - ' . field($r, 'id') . ': ' . field($r, 'name', 'unnamed') . "\n";
    }
});

echo "\nREST Demo complete.\n";
