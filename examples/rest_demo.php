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
 * @param callable(): array<string,mixed> $fn
 * @return array<string,mixed>|null
 */
function safe(string $label, callable $fn): ?array
{
    try {
        $result = $fn();
        echo "  {$label}: OK\n";
        return $result;
    } catch (\Exception $e) {
        echo "  {$label}: FAILED - {$e->getMessage()}\n";
        return null;
    }
}

// 1. List phone numbers
echo "Listing phone numbers...\n";
$numbers = safe('List numbers', fn () => $client->phoneNumbers->list());
if ($numbers) {
    foreach (array_slice($numbers['data'] ?? [], 0, 5) as $n) {
        echo '    - ' . ($n['number'] ?? 'unknown') . "\n";
    }
}

// 2. Search available numbers
echo "\nSearching available numbers...\n";
safe('Search 512', function () use ($client) {
    $avail = $client->phoneNumbers->search(['areacode' => '512', 'max_results' => 3]);
    foreach (($avail['data'] ?? []) as $n) {
        echo '    - ' . ($n['e164'] ?? $n['number'] ?? 'unknown') . "\n";
    }
});

// 3. List AI agents
echo "\nListing AI agents...\n";
safe('List agents', function () use ($client) {
    $agents = $client->fabric->aiAgents->list();
    foreach (($agents['data'] ?? []) as $a) {
        echo "    - {$a['id']}: " . ($a['name'] ?? 'unnamed') . "\n";
    }
});

// 4. Datasphere documents
echo "\nListing Datasphere documents...\n";
safe('List documents', function () use ($client) {
    $docs = $client->datasphere->documents->list();
    foreach (($docs['data'] ?? []) as $d) {
        echo "    - {$d['id']}: " . ($d['status'] ?? 'unknown') . "\n";
    }
});

// 5. Video rooms
echo "\nListing video rooms...\n";
safe('List rooms', function () use ($client) {
    $rooms = $client->video->rooms->list();
    foreach (($rooms['data'] ?? []) as $r) {
        echo "    - {$r['id']}: " . ($r['name'] ?? 'unnamed') . "\n";
    }
});

echo "\nREST Demo complete.\n";
