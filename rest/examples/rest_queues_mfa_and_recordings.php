<?php
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

// --- Queues ---

// 1. Create a queue
echo "Creating call queue...\n";
$queueId = null;
$queue = safe('Create queue', fn() =>
    $client->queues->create(name: 'Support Queue', maxSize: 50)
);
$queueId = $queue ? $queue['id'] : null;

// 2. List queues
echo "\nListing queues...\n";
$queues = safe('List queues', fn() => $client->queues->list());
if ($queues) {
    foreach (($queues['data'] ?? []) as $q) {
        echo "  - {$q['id']}: " . ($q['friendly_name'] ?? $q['name'] ?? 'unnamed') . "\n";
    }
}

// 3. Get and update queue
if ($queueId) {
    $detail = safe('Get queue', fn() => $client->queues->get($queueId));
    if ($detail) {
        echo "\nQueue detail: " . ($detail['friendly_name'] ?? 'N/A')
            . " (max: " . ($detail['max_size'] ?? 'N/A') . ")\n";
    }
    safe('Update queue', fn() =>
        $client->queues->update($queueId, name: 'Priority Support Queue')
    );
}

// 4. Queue members
if ($queueId) {
    echo "\nListing queue members...\n";
    safe('List members', function () use ($client, $queueId) {
        $members = $client->queues->listMembers($queueId);
        foreach (($members['data'] ?? []) as $m) {
            echo "  - Member: " . ($m['call_id'] ?? $m['id'] ?? 'unknown') . "\n";
        }
    });
    safe('Next member', function () use ($client, $queueId) {
        $next = $client->queues->getNextMember($queueId);
        echo "  Next member: " . (is_array($next) ? 'found' : $next) . "\n";
    });
}

// --- Recordings ---

// 5. List recordings
echo "\nListing recordings...\n";
$recordings = safe('List recordings', fn() => $client->recordings->list());
if ($recordings) {
    foreach (array_slice($recordings['data'] ?? [], 0, 5) as $r) {
        echo "  - {$r['id']}: " . ($r['duration'] ?? 'N/A') . "s\n";
    }
}

// 6. Get recording details
if ($recordings && !empty($recordings['data'])) {
    $firstRec = $recordings['data'][0];
    if (!empty($firstRec['id'])) {
        $recDetail = safe('Get recording', fn() => $client->recordings->get($firstRec['id']));
        if ($recDetail) {
            echo "  Recording: " . ($recDetail['duration'] ?? 'N/A')
                . "s, " . ($recDetail['format'] ?? 'N/A') . "\n";
        }
    }
}

// --- MFA ---

// 7. Send MFA via SMS
echo "\nSending MFA SMS code...\n";
$requestId = null;
safe('MFA SMS', function () use ($client, &$requestId) {
    $smsResult = $client->mfa->sms(
        to:          '+15551234567',
        from:        '+15559876543',
        message:     'Your code is {{code}}',
        tokenLength: 6,
    );
    $requestId = $smsResult['id'] ?? $smsResult['request_id'] ?? null;
    if ($requestId) echo "  MFA SMS sent: {$requestId}\n";
});

// 8. Send MFA via voice call
echo "\nSending MFA voice code...\n";
safe('MFA call', function () use ($client) {
    $voiceResult = $client->mfa->call(
        to:          '+15551234567',
        from:        '+15559876543',
        message:     'Your verification code is {{code}}',
        tokenLength: 6,
    );
    echo "  MFA call sent: " . ($voiceResult['id'] ?? $voiceResult['request_id'] ?? 'unknown') . "\n";
});

// 9. Verify MFA token
if ($requestId) {
    echo "\nVerifying MFA token...\n";
    safe('Verify', function () use ($client, $requestId) {
        $verify = $client->mfa->verify($requestId, token: '123456');
        echo "  Verification result: " . (is_array($verify) ? 'response received' : $verify) . "\n";
    });
}

// 10. Clean up
echo "\nCleaning up...\n";
if ($queueId) safe('Delete queue', fn() => $client->queues->delete($queueId));
