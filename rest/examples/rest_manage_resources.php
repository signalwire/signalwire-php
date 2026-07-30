<?php

declare(strict_types=1);
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
        echo "  {$label}: failed ({$e->getMessage()})\n";
        return null;
    }
}

// 1. Create an AI agent
echo "Creating AI agent...\n";
$agent = $client->fabric()->aiAgents()->create([
    'name'   => 'Demo Support Bot',
    'prompt' => ['text' => 'You are a friendly support agent for Acme Corp.'],
]);
$agentId = $agent['id'] ?? 'demo-agent-id';
echo "  Created agent: {$agentId}\n";

// 2. List all AI agents
echo "\nListing AI agents...\n";
$agents = $client->fabric()->aiAgents()->list();
foreach (($agents['data'] ?? []) as $a) {
    echo "  - {$a['id']}: " . ($a['name'] ?? 'unnamed') . "\n";
}

// 3. Search for a phone number
echo "\nSearching for available phone numbers...\n";
$available = safe(
    'Search numbers',
    fn () =>
    $client->phoneNumbers()->search(['areacode' => '512', 'max_results' => 3])
);
if ($available) {
    foreach (($available['data'] ?? []) as $num) {
        echo '  - ' . ($num['e164'] ?? $num['number'] ?? 'unknown') . "\n";
    }
}

// 4. Place a test call (requires valid numbers)
echo "\nPlacing a test call...\n";
safe(
    'Dial',
    fn () =>
    $client->calling()->dial(
        from_: '+15559876543',
        to:    '+15551234567',
        url:   'https://example.com/call-handler',
    )
);

// 5. Clean up
echo "\nDeleting agent {$agentId}...\n";
$client->fabric()->aiAgents()->delete($agentId);
echo "  Deleted.\n";
