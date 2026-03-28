<?php
/**
 * Example: Conference infrastructure, cXML resources, generic routing, and tokens.
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

// 1. Create a conference room
echo "Creating conference room...\n";
$room = $client->fabric->conferenceRooms->create(name: 'team-standup');
$roomId = $room['id'];
echo "  Created conference room: {$roomId}\n";

// 2. List conference room addresses
echo "\nListing conference room addresses...\n";
safe('List addresses', function () use ($client, $roomId) {
    $addrs = $client->fabric->conferenceRooms->listAddresses($roomId);
    foreach (($addrs['data'] ?? []) as $a) {
        echo "  - " . ($a['display_name'] ?? $a['id'] ?? 'unknown') . "\n";
    }
});

// 3. Create a cXML script
echo "\nCreating cXML script...\n";
$cxml = $client->fabric->cxmlScripts->create(
    name:     'Hold Music Script',
    contents: '<Response><Say>Please hold.</Say><Play>https://example.com/hold.mp3</Play></Response>',
);
$cxmlId = $cxml['id'];
echo "  Created cXML script: {$cxmlId}\n";

// 4. Create a cXML webhook
echo "\nCreating cXML webhook...\n";
$cxmlWh = $client->fabric->cxmlWebhooks->create(
    name:              'External cXML Handler',
    primaryRequestUrl: 'https://example.com/cxml-handler',
);
$cxmlWhId = $cxmlWh['id'];
echo "  Created cXML webhook: {$cxmlWhId}\n";

// 5. Create a relay application
echo "\nCreating relay application...\n";
$relayApp = $client->fabric->relayApplications->create(
    name:  'Inbound Handler',
    topic: 'office',
);
$relayId = $relayApp['id'];
echo "  Created relay application: {$relayId}\n";

// 6. List all fabric resources
echo "\nListing all fabric resources...\n";
$resources = safe('List resources', fn() => $client->fabric->resources->list());
if ($resources) {
    foreach (array_slice($resources['data'] ?? [], 0, 5) as $r) {
        echo "  - " . ($r['type'] ?? 'unknown') . ": "
            . ($r['display_name'] ?? $r['id'] ?? 'unknown') . "\n";
    }
}

// 7. Get a specific resource
if ($resources && !empty($resources['data'])) {
    $first = $resources['data'][0];
    if (!empty($first['id'])) {
        $detail = safe('Get resource', fn() => $client->fabric->resources->get($first['id']));
        if ($detail) {
            echo "  Resource detail: " . ($detail['display_name'] ?? 'N/A')
                . " (" . ($detail['type'] ?? 'N/A') . ")\n";
        }
    }
}

// 8. Assign a phone route (demo)
echo "\nAssigning phone route (demo)...\n";
safe('Phone route', fn() =>
    $client->fabric->resources->assignPhoneRoute($relayId, phoneNumber: '+15551234567')
);

// 9. Assign a domain application (demo)
echo "\nAssigning domain application (demo)...\n";
safe('Domain app', fn() =>
    $client->fabric->resources->assignDomainApplication($relayId, domain: 'app.example.com')
);

// 10. Generate tokens
echo "\nGenerating tokens...\n";
safe('Guest token', function () use ($client, $relayId) {
    $guest = $client->fabric->tokens->createGuestToken(resourceId: $relayId);
    $t = $guest['token'] ?? '';
    if ($t) echo "  Guest token: " . substr($t, 0, 40) . "...\n";
});
safe('Invite token', function () use ($client, $relayId) {
    $invite = $client->fabric->tokens->createInviteToken(resourceId: $relayId);
    $t = $invite['token'] ?? '';
    if ($t) echo "  Invite token: " . substr($t, 0, 40) . "...\n";
});
safe('Embed token', function () use ($client, $relayId) {
    $embed = $client->fabric->tokens->createEmbedToken(resourceId: $relayId);
    $t = $embed['token'] ?? '';
    if ($t) echo "  Embed token: " . substr($t, 0, 40) . "...\n";
});

// 11. Clean up
echo "\nCleaning up...\n";
$client->fabric->relayApplications->delete($relayId);
echo "  Deleted relay application {$relayId}\n";
$client->fabric->cxmlWebhooks->delete($cxmlWhId);
echo "  Deleted cXML webhook {$cxmlWhId}\n";
$client->fabric->cxmlScripts->delete($cxmlId);
echo "  Deleted cXML script {$cxmlId}\n";
$client->fabric->conferenceRooms->delete($roomId);
echo "  Deleted conference room {$roomId}\n";
