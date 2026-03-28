<?php
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

$client = new RestClient(
    project: $_ENV['SIGNALWIRE_PROJECT_ID'] ?? die("Set SIGNALWIRE_PROJECT_ID\n"),
    token:   $_ENV['SIGNALWIRE_API_TOKEN']  ?? die("Set SIGNALWIRE_API_TOKEN\n"),
    host:    $_ENV['SIGNALWIRE_SPACE']      ?? die("Set SIGNALWIRE_SPACE\n"),
);

function safe(string $label, callable $fn): mixed
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
$numbers = safe('List numbers', fn() => $client->phoneNumbers->list());
if ($numbers) {
    foreach (array_slice($numbers['data'] ?? [], 0, 5) as $n) {
        echo "    - " . ($n['number'] ?? 'unknown') . "\n";
    }
}

// 2. Search available numbers
echo "\nSearching available numbers...\n";
safe('Search 512', function () use ($client) {
    $avail = $client->phoneNumbers->search(areaCode: '512', maxResults: 3);
    foreach (($avail['data'] ?? []) as $n) {
        echo "    - " . ($n['e164'] ?? $n['number'] ?? 'unknown') . "\n";
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
