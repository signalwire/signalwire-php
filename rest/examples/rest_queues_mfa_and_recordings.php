<?php

declare(strict_types=1);
/**
 * Example: Call queues, recording review, and MFA verification.
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

// --- Queues ---

// 1. Create a queue
echo "Creating call queue...\n";
$queueId = null;
$queue = safe(
    'Create queue',
    fn () =>
    $client->queues()->create(['name' => 'Support Queue', 'max_size' => 50])
);
$queueId = field($queue, 'id');

// 2. List queues
echo "\nListing queues...\n";
$queues = safe('List queues', fn () => $client->queues()->list());
if ($queues) {
    foreach (dataRows($queues) as $q) {
        echo '  - ' . field($q, 'id') . ': ' . fieldAny($q, 'friendly_name', 'name', 'unnamed') . "\n";
    }
}

// 3. Get and update queue
if ($queueId) {
    $detail = safe('Get queue', fn () => $client->queues()->get($queueId));
    if ($detail) {
        echo "\nQueue detail: " . field($detail, 'friendly_name', 'N/A')
            . ' (max: ' . field($detail, 'max_size', 'N/A') . ")\n";
    }
    safe(
        'Update queue',
        fn () =>
        $client->queues()->update($queueId, ['name' => 'Priority Support Queue'])
    );
}

// 4. Queue members
if ($queueId) {
    echo "\nListing queue members...\n";
    safe('List members', function () use ($client, $queueId) {
        $members = $client->queues()->listMembers($queueId);
        foreach (dataRows($members) as $m) {
            echo '  - Member: ' . fieldAny($m, 'call_id', 'id', 'unknown') . "\n";
        }
    });
    safe('Next member', function () use ($client, $queueId) {
        $next = $client->queues()->getNextMember($queueId);
        echo '  Next member: ' . (is_array($next) ? 'found' : $next) . "\n";
    });
}

// --- Recordings ---

// 5. List recordings
echo "\nListing recordings...\n";
$recordings = safe('List recordings', fn () => $client->recordings()->list());
if ($recordings) {
    foreach (array_slice(dataRows($recordings), 0, 5) as $r) {
        echo '  - ' . field($r, 'id') . ': ' . field($r, 'duration', 'N/A') . "s\n";
    }
}

// 6. Get recording details
if ($recordings && !empty($recordings['data'])) {
    $firstRec = firstRow($recordings);
    if (!empty($firstRec['id'])) {
        $recDetail = safe('Get recording', fn () => $client->recordings()->get($firstRec['id']));
        if ($recDetail) {
            echo '  Recording: ' . field($recDetail, 'duration', 'N/A')
                . 's, ' . field($recDetail, 'format', 'N/A') . "\n";
        }
    }
}

// --- MFA ---

// 7. Send MFA via SMS
echo "\nSending MFA SMS code...\n";
$requestId = null;
safe('MFA SMS', function () use ($client, &$requestId) {
    $smsResult = $client->mfa()->sms(
        to:          '+15551234567',
        from_:       '+15559876543',
        message:     'Your code is {{code}}',
        tokenLength: 6,
    );
    $requestId = $smsResult['id'] ?? $smsResult['request_id'] ?? null;
    if ($requestId) {
        echo "  MFA SMS sent: {$requestId}\n";
    }
});

// 8. Send MFA via voice call
echo "\nSending MFA voice code...\n";
safe('MFA call', function () use ($client) {
    $voiceResult = $client->mfa()->call(
        to:          '+15551234567',
        from_:       '+15559876543',
        message:     'Your verification code is {{code}}',
        tokenLength: 6,
    );
    echo '  MFA call sent: ' . fieldAny($voiceResult, 'id', 'request_id', 'unknown') . "\n";
});

// 9. Verify MFA token
if ($requestId) {
    echo "\nVerifying MFA token...\n";
    safe('Verify', function () use ($client, $requestId) {
        $verify = $client->mfa()->verify($requestId, '123456');
        echo '  Verification result: ' . (is_array($verify) ? 'response received' : $verify) . "\n";
    });
}

// 10. Clean up
echo "\nCleaning up...\n";
if ($queueId) {
    safe('Delete queue', fn () => $client->queues()->delete($queueId));
}
