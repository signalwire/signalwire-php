<?php
/**
 * Example: Create an AI agent, assign a phone number, and place a test call.
 *
 * Set these env vars:
 *   SIGNALWIRE_PROJECT_ID   - your SignalWire project ID
 *   SIGNALWIRE_API_TOKEN    - your SignalWire API token
 *   SIGNALWIRE_SPACE        - your SignalWire space
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
        echo "  {$label}: failed ({$e->getMessage()})\n";
        return null;
    }
}

// 1. Create an AI agent
echo "Creating AI agent...\n";
$agent = $client->fabric->aiAgents->create(
    name:   'Demo Support Bot',
    prompt: ['text' => 'You are a friendly support agent for Acme Corp.'],
);
$agentId = $agent['id'];
echo "  Created agent: {$agentId}\n";

// 2. List all AI agents
echo "\nListing AI agents...\n";
$agents = $client->fabric->aiAgents->list();
foreach (($agents['data'] ?? []) as $a) {
    echo "  - {$a['id']}: " . ($a['name'] ?? 'unnamed') . "\n";
}

// 3. Search for a phone number
echo "\nSearching for available phone numbers...\n";
$available = safe('Search numbers', fn() =>
    $client->phoneNumbers->search(areaCode: '512', maxResults: 3)
);
if ($available) {
    foreach (($available['data'] ?? []) as $num) {
        echo "  - " . ($num['e164'] ?? $num['number'] ?? 'unknown') . "\n";
    }
}

// 4. Place a test call (requires valid numbers)
echo "\nPlacing a test call...\n";
safe('Dial', fn() =>
    $client->calling->dial(
        from: '+15559876543',
        to:   '+15551234567',
        url:  'https://example.com/call-handler',
    )
);

// 5. Clean up
echo "\nDeleting agent {$agentId}...\n";
$client->fabric->aiAgents->delete($agentId);
echo "  Deleted.\n";
