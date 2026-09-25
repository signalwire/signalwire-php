<?php

declare(strict_types=1);
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
    return dataRows($response)[0] ?? [];
}

// 1. Search for available phone numbers
echo "Searching available numbers...\n";
$available = safe(
    'Search',
    fn () =>
    $client->phoneNumbers()->search(['areacode' => '512', 'max_results' => 3])
);
if ($available) {
    foreach (dataRows($available) as $num) {
        echo '  - ' . fieldAny($num, 'e164', 'number', 'unknown') . "\n";
    }
}

// 2. Purchase a number
echo "\nPurchasing a phone number...\n";
$numId = null;
$number = safe('Purchase', function () use ($client, $available) {
    $first = firstRow($available);
    return $client->phoneNumbers()->create(['number' => field($first, 'e164', '+15125551234')]);
});
$numId = field($number, 'id');

// 3. List and get owned numbers
echo "\nListing owned numbers...\n";
$owned = safe('List', fn () => $client->phoneNumbers()->list());
if ($owned) {
    foreach (array_slice(dataRows($owned), 0, 5) as $n) {
        echo '  - ' . field($n, 'number', 'unknown') . ' (' . field($n, 'id') . ")\n";
    }
}
if ($numId) {
    $detail = safe('Get', fn () => $client->phoneNumbers()->get($numId));
    if ($detail) {
        echo '  Detail: ' . field($detail, 'number', 'N/A') . "\n";
    }
}

// 4. Update a number
if ($numId) {
    echo "\nUpdating number {$numId}...\n";
    safe('Update', fn () => $client->phoneNumbers()->update($numId, ['name' => 'Main Line']));
}

// 5. Create a number group
echo "\nCreating number group...\n";
$groupId = null;
$group = safe('Create group', fn () => $client->numberGroups()->create(['name' => 'Sales Pool']));
$groupId = field($group, 'id');

// 6. Add a membership
if ($groupId && $numId) {
    echo "\nAdding number to group...\n";
    safe('Add membership', function () use ($client, $groupId, $numId) {
        $membership = $client->numberGroups()->addMembership($groupId, $numId);
        $memId = field($membership, 'id');
        if ($memId) {
            echo "  Membership: {$memId}\n";
        }

        $memberships = $client->numberGroups()->listMemberships($groupId);
        foreach (dataRows($memberships) as $m) {
            echo '  - Member: ' . field($m, 'id', 'unknown') . "\n";
        }
    });
}

// 7. Lookup carrier info
echo "\nLooking up carrier info...\n";
safe('Lookup', function () use ($client) {
    $info = $client->lookup()->phoneNumber('+15125551234');
    echo '  Carrier: ' . field($info['carrier'] ?? null, 'name', 'unknown') . "\n";
});

// 8. Create a verified caller
echo "\nCreating verified caller...\n";
$callerId = null;
safe('Verified caller', function () use ($client, &$callerId) {
    $caller = $client->verifiedCallers()->create(['number' => '+15125559999']);
    $callerId = field($caller, 'id');
    echo "  Created verified caller: {$callerId}\n";
    if ($callerId) {
        $client->verifiedCallers()->submitVerification($callerId, '123456');
        echo "  Verification code submitted\n";
    }
});

// 9. Get and update SIP profile
echo "\nGetting SIP profile...\n";
safe('SIP profile', function () use ($client) {
    $profile = $client->sipProfile()->get();
    echo "  SIP profile: OK\n";
    $client->sipProfile()->update(defaultCodecs: ['PCMU', 'PCMA']);
    echo "  Updated SIP codecs\n";
});

// 10. List short codes
echo "\nListing short codes...\n";
safe('Short codes', function () use ($client) {
    $codes = $client->shortCodes()->list();
    foreach (dataRows($codes) as $sc) {
        echo '  - ' . field($sc, 'short_code', 'unknown') . "\n";
    }
});

// 11. Create an address
echo "\nCreating address...\n";
$addrId = null;
safe('Address', function () use ($client, &$addrId) {
    $addr = $client->addresses()->create(
        label:        'HQ Address',
        country:      'US',
        firstName:    'Acme',
        lastName:     'Corp',
        streetNumber: '123',
        streetName:   'Main St',
        city:         'Austin',
        state:        'TX',
        postalCode:   '78701',
    );
    $addrId = field($addr, 'id');
    echo "  Created address: {$addrId}\n";
});

// 12. Clean up
echo "\nCleaning up...\n";
if ($addrId) {
    safe('Delete address', fn () => $client->addresses()->delete($addrId));
}
if ($callerId) {
    safe('Delete verified caller', fn () => $client->verifiedCallers()->delete($callerId));
}
if ($groupId) {
    safe('Delete number group', fn () => $client->numberGroups()->delete($groupId));
}
if ($numId) {
    safe('Release number', fn () => $client->phoneNumbers()->delete($numId));
}
