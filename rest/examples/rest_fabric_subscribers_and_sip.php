<?php
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

// 1. Create a subscriber
echo "Creating subscriber...\n";
$subscriber = $client->fabric->subscribers->create(
    name:  'Alice Johnson',
    email: 'alice@example.com',
);
$subId      = $subscriber['id'];
$innerSubId = ($subscriber['subscriber'] ?? [])['id'] ?? $subId;
echo "  Created subscriber: {$subId}\n";

// 2. Add a SIP endpoint
echo "\nCreating SIP endpoint on subscriber...\n";
$endpoint = $client->fabric->subscribers->createSipEndpoint($subId,
    username: 'alice_sip',
    password: 'SecurePass123!',
);
$epId = $endpoint['id'];
echo "  Created SIP endpoint: {$epId}\n";

// 3. List SIP endpoints
echo "\nListing subscriber SIP endpoints...\n";
$endpoints = $client->fabric->subscribers->listSipEndpoints($subId);
foreach (($endpoints['data'] ?? []) as $ep) {
    echo "  - {$ep['id']}: " . ($ep['username'] ?? 'unknown') . "\n";
}

// 4. Get specific endpoint details
echo "\nGetting SIP endpoint {$epId}...\n";
$epDetail = $client->fabric->subscribers->getSipEndpoint($subId, $epId);
echo "  Username: " . ($epDetail['username'] ?? 'N/A') . "\n";

// 5. Create a standalone SIP gateway
echo "\nCreating SIP gateway...\n";
$gateway = $client->fabric->sipGateways->create(
    name:       'Office PBX Gateway',
    uri:        'sip:pbx.example.com',
    encryption: 'required',
    ciphers:    ['AES_256_CM_HMAC_SHA1_80'],
    codecs:     ['PCMU', 'PCMA'],
);
$gwId = $gateway['id'];
echo "  Created SIP gateway: {$gwId}\n";

// 6. List fabric addresses
echo "\nListing fabric addresses...\n";
safe('List addresses', function () use ($client) {
    $addresses = $client->fabric->addresses->list();
    foreach (array_slice($addresses['data'] ?? [], 0, 5) as $addr) {
        echo "  - " . ($addr['display_name'] ?? $addr['id'] ?? 'unknown') . "\n";
    }

    // 7. Get a specific address
    if (!empty($addresses['data']) && !empty($addresses['data'][0]['id'])) {
        $addrDetail = $client->fabric->addresses->get($addresses['data'][0]['id']);
        echo "  Address detail: " . ($addrDetail['display_name'] ?? 'N/A') . "\n";
    }
});

// 8. Generate a subscriber token
echo "\nGenerating subscriber token...\n";
safe('Subscriber token', function () use ($client, $innerSubId) {
    $token = $client->fabric->tokens->createSubscriberToken(
        subscriberId: $innerSubId,
        reference:    $innerSubId,
    );
    $t = $token['token'] ?? '';
    if ($t) echo "  Token: " . substr($t, 0, 40) . "...\n";
});

// 9. Clean up
echo "\nCleaning up...\n";
$client->fabric->subscribers->deleteSipEndpoint($subId, $epId);
echo "  Deleted SIP endpoint {$epId}\n";
$client->fabric->subscribers->delete($subId);
echo "  Deleted subscriber {$subId}\n";
$client->fabric->sipGateways->delete($gwId);
echo "  Deleted SIP gateway {$gwId}\n";
