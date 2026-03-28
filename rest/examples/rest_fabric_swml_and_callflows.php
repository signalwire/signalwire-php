<?php
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

// 1. Create a SWML script
echo "Creating SWML script...\n";
$swml = $client->fabric->swmlScripts->create(
    name:     'Greeting Script',
    contents: [
        'sections' => [
            'main' => [['play' => ['url' => 'say:Hello from SignalWire']]],
        ],
    ],
);
$swmlId = $swml['id'];
echo "  Created SWML script: {$swmlId}\n";

// 2. List SWML scripts
echo "\nListing SWML scripts...\n";
$scripts = $client->fabric->swmlScripts->list();
foreach (($scripts['data'] ?? []) as $s) {
    echo "  - {$s['id']}: " . ($s['display_name'] ?? 'unnamed') . "\n";
}

// 3. Create a call flow
echo "\nCreating call flow...\n";
$flow = $client->fabric->callFlows->create(title: 'Main IVR Flow');
$flowId = $flow['id'];
echo "  Created call flow: {$flowId}\n";

// 4. Deploy a version
echo "\nDeploying call flow version...\n";
safe('Deploy version', fn() =>
    $client->fabric->callFlows->deployVersion($flowId, label: 'v1')
);

// 5. List call flow versions
echo "\nListing call flow versions...\n";
safe('List versions', function () use ($client, $flowId) {
    $versions = $client->fabric->callFlows->listVersions($flowId);
    foreach (($versions['data'] ?? []) as $v) {
        echo "  - Version: " . ($v['label'] ?? $v['id'] ?? 'unknown') . "\n";
    }
});

// 6. List addresses for the call flow
echo "\nListing call flow addresses...\n";
safe('List addresses', function () use ($client, $flowId) {
    $addrs = $client->fabric->callFlows->listAddresses($flowId);
    foreach (($addrs['data'] ?? []) as $a) {
        echo "  - " . ($a['display_name'] ?? $a['id'] ?? 'unknown') . "\n";
    }
});

// 7. Create a SWML webhook
echo "\nCreating SWML webhook...\n";
$webhook = $client->fabric->swmlWebhooks->create(
    name:              'External Handler',
    primaryRequestUrl: 'https://example.com/swml-handler',
);
$webhookId = $webhook['id'];
echo "  Created webhook: {$webhookId}\n";

// 8. Clean up
echo "\nCleaning up...\n";
$client->fabric->swmlWebhooks->delete($webhookId);
echo "  Deleted webhook {$webhookId}\n";
$client->fabric->callFlows->delete($flowId);
echo "  Deleted call flow {$flowId}\n";
$client->fabric->swmlScripts->delete($swmlId);
echo "  Deleted SWML script {$swmlId}\n";
