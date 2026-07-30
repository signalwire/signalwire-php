<?php

declare(strict_types=1);
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
    return firstRow($response);
}

// 1. Create a conference room
echo "Creating conference room...\n";
$room = $client->fabric()->conferenceRooms()->create(['name' => 'team-standup']);
$roomId = field($room, 'id', 'demo-room-id');
echo "  Created conference room: {$roomId}\n";

// 2. List conference room addresses
echo "\nListing conference room addresses...\n";
safe('List addresses', function () use ($client, $roomId) {
    $addrs = $client->fabric()->conferenceRooms()->listAddresses($roomId);
    foreach (dataRows($addrs) as $a) {
        echo '  - ' . fieldAny($a, 'display_name', 'id', 'unknown') . "\n";
    }
});

// 3. Create a cXML script
echo "\nCreating cXML script...\n";
$cxml = $client->fabric()->cxmlScripts()->create([
    'display_name' => 'Hold Music Script',
    'contents'     => '<Response><Say>Please hold.</Say><Play>https://example.com/hold.mp3</Play></Response>',
]);
$cxmlId = field($cxml, 'id', 'demo-cxml-id');
echo "  Created cXML script: {$cxmlId}\n";

// 4. Create a cXML webhook
echo "\nCreating cXML webhook...\n";
$cxmlWh = $client->fabric()->cxmlWebhooks()->create([
    'name'                => 'External cXML Handler',
    'primary_request_url' => 'https://example.com/cxml-handler',
]);
$cxmlWhId = field($cxmlWh, 'id', 'demo-cxml-webhook-id');
echo "  Created cXML webhook: {$cxmlWhId}\n";

// 5. Create a relay application
echo "\nCreating relay application...\n";
$relayApp = $client->fabric()->relayApplications()->create([
    'name'  => 'Inbound Handler',
    'topic' => 'office',
]);
$relayId = field($relayApp, 'id', 'demo-relay-id');
echo "  Created relay application: {$relayId}\n";

// 6. List all fabric resources
echo "\nListing all fabric resources...\n";
$resources = safe('List resources', fn () => $client->fabric()->resources()->list());
if ($resources) {
    foreach (array_slice(dataRows($resources), 0, 5) as $r) {
        echo '  - ' . field($r, 'type', 'unknown') . ': '
            . fieldAny($r, 'display_name', 'id', 'unknown') . "\n";
    }
}

// 7. Get a specific resource
if ($resources && !empty($resources['data'])) {
    $firstId = field(firstRow($resources), 'id');
    if ($firstId !== '') {
        $detail = safe('Get resource', fn () => $client->fabric()->resources()->get($firstId));
        if ($detail) {
            echo '  Resource detail: ' . field($detail, 'display_name', 'N/A')
                . ' (' . field($detail, 'type', 'N/A') . ")\n";
        }
    }
}

// 8. Assign a phone route (demo)
echo "\nAssigning phone route (demo)...\n";
safe(
    'Phone route',
    fn () =>
    $client->fabric()->resources()->assignPhoneRoute($relayId, phoneRouteId: 'route-1', handler: 'relay_application')
);

// 9. Assign a domain application (demo)
echo "\nAssigning domain application (demo)...\n";
safe(
    'Domain app',
    fn () =>
    $client->fabric()->resources()->assignDomainApplication($relayId, domainApplicationId: 'app-1')
);

// 10. Generate tokens
echo "\nGenerating tokens...\n";
safe('Guest token', function () use ($client, $relayId) {
    $guest = $client->fabric()->tokens()->createGuestToken(allowedAddresses: [$relayId]);
    $t = field($guest, 'token', '');
    if ($t) {
        echo '  Guest token: ' . substr($t, 0, 40) . "...\n";
    }
});
safe('Invite token', function () use ($client, $relayId) {
    $invite = $client->fabric()->tokens()->createInviteToken(addressId: $relayId);
    $t = field($invite, 'token', '');
    if ($t) {
        echo '  Invite token: ' . substr($t, 0, 40) . "...\n";
    }
});
safe('Embed token', function () use ($client) {
    $embed = $client->fabric()->tokens()->createEmbedToken(token: 'guest-token-value');
    $t = field($embed, 'token', '');
    if ($t) {
        echo '  Embed token: ' . substr($t, 0, 40) . "...\n";
    }
});

// 11. Clean up
echo "\nCleaning up...\n";
$client->fabric()->relayApplications()->delete($relayId);
echo "  Deleted relay application {$relayId}\n";
$client->fabric()->cxmlWebhooks()->delete($cxmlWhId);
echo "  Deleted cXML webhook {$cxmlWhId}\n";
$client->fabric()->cxmlScripts()->delete($cxmlId);
echo "  Deleted cXML script {$cxmlId}\n";
$client->fabric()->conferenceRooms()->delete($roomId);
echo "  Deleted conference room {$roomId}\n";
