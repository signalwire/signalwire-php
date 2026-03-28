<?php
/**
 * Example: Full phone number inventory lifecycle.
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

// 1. Search for available phone numbers
echo "Searching available numbers...\n";
$available = safe('Search', fn() =>
    $client->phoneNumbers->search(areaCode: '512', maxResults: 3)
);
if ($available) {
    foreach (($available['data'] ?? []) as $num) {
        echo "  - " . ($num['e164'] ?? $num['number'] ?? 'unknown') . "\n";
    }
}

// 2. Purchase a number
echo "\nPurchasing a phone number...\n";
$numId = null;
$number = safe('Purchase', function () use ($client, $available) {
    $first = ($available['data'] ?? [null])[0] ?? [];
    return $client->phoneNumbers->create(number: ($first['e164'] ?? '+15125551234'));
});
$numId = $number ? $number['id'] : null;

// 3. List and get owned numbers
echo "\nListing owned numbers...\n";
$owned = safe('List', fn() => $client->phoneNumbers->list());
if ($owned) {
    foreach (array_slice($owned['data'] ?? [], 0, 5) as $n) {
        echo "  - " . ($n['number'] ?? 'unknown') . " ({$n['id']})\n";
    }
}
if ($numId) {
    $detail = safe('Get', fn() => $client->phoneNumbers->get($numId));
    if ($detail) echo "  Detail: " . ($detail['number'] ?? 'N/A') . "\n";
}

// 4. Update a number
if ($numId) {
    echo "\nUpdating number {$numId}...\n";
    safe('Update', fn() => $client->phoneNumbers->update($numId, name: 'Main Line'));
}

// 5. Create a number group
echo "\nCreating number group...\n";
$groupId = null;
$group = safe('Create group', fn() => $client->numberGroups->create(name: 'Sales Pool'));
$groupId = $group ? $group['id'] : null;

// 6. Add a membership
if ($groupId && $numId) {
    echo "\nAdding number to group...\n";
    safe('Add membership', function () use ($client, $groupId, $numId) {
        $membership = $client->numberGroups->addMembership($groupId, phoneNumberId: $numId);
        $memId = $membership['id'] ?? null;
        if ($memId) echo "  Membership: {$memId}\n";

        $memberships = $client->numberGroups->listMemberships($groupId);
        foreach (($memberships['data'] ?? []) as $m) {
            echo "  - Member: " . ($m['id'] ?? 'unknown') . "\n";
        }
    });
}

// 7. Lookup carrier info
echo "\nLooking up carrier info...\n";
safe('Lookup', function () use ($client) {
    $info = $client->lookup->phoneNumber('+15125551234');
    echo "  Carrier: " . (($info['carrier'] ?? [])['name'] ?? 'unknown') . "\n";
});

// 8. Create a verified caller
echo "\nCreating verified caller...\n";
$callerId = null;
safe('Verified caller', function () use ($client, &$callerId) {
    $caller = $client->verifiedCallers->create(phoneNumber: '+15125559999');
    $callerId = $caller['id'];
    echo "  Created verified caller: {$callerId}\n";
    $client->verifiedCallers->submitVerification($callerId, verificationCode: '123456');
    echo "  Verification code submitted\n";
});

// 9. Get and update SIP profile
echo "\nGetting SIP profile...\n";
safe('SIP profile', function () use ($client) {
    $profile = $client->sipProfile->get();
    echo "  SIP profile: " . (is_array($profile) ? 'OK' : $profile) . "\n";
    $client->sipProfile->update(defaultCodecs: ['PCMU', 'PCMA']);
    echo "  Updated SIP codecs\n";
});

// 10. List short codes
echo "\nListing short codes...\n";
safe('Short codes', function () use ($client) {
    $codes = $client->shortCodes->list();
    foreach (($codes['data'] ?? []) as $sc) {
        echo "  - " . ($sc['short_code'] ?? 'unknown') . "\n";
    }
});

// 11. Create an address
echo "\nCreating address...\n";
$addrId = null;
safe('Address', function () use ($client, &$addrId) {
    $addr = $client->addresses->create(
        friendlyName: 'HQ Address',
        street:       '123 Main St',
        city:         'Austin',
        region:       'TX',
        postalCode:   '78701',
        isoCountry:   'US',
    );
    $addrId = $addr['id'];
    echo "  Created address: {$addrId}\n";
});

// 12. Clean up
echo "\nCleaning up...\n";
if ($addrId)    safe('Delete address',         fn() => $client->addresses->delete($addrId));
if ($callerId)  safe('Delete verified caller', fn() => $client->verifiedCallers->delete($callerId));
if ($groupId)   safe('Delete number group',    fn() => $client->numberGroups->delete($groupId));
if ($numId)     safe('Release number',         fn() => $client->phoneNumbers->delete($numId));
