<?php
/**
 * Example: Twilio-compatible LAML migration -- phone numbers, messaging, calls,
 * conferences, queues, recordings, project tokens, PubSub/Chat, and logs.
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

// --- Compat Phone Numbers ---

// 1. Search available numbers
echo "Searching compat phone numbers...\n";
safe('Search local',     fn() => $client->compat->phoneNumbers->searchLocal('US', AreaCode: '512'));
safe('Search toll-free', fn() => $client->compat->phoneNumbers->searchTollFree('US'));
safe('List countries',   fn() => $client->compat->phoneNumbers->listAvailableCountries());

// 2. Purchase a number (demo)
echo "\nPurchasing compat number...\n";
$num = safe('Purchase', fn() =>
    $client->compat->phoneNumbers->purchase(PhoneNumber: '+15125551234')
);
$numSid = $num ? $num['sid'] : null;

// --- LaML Bin & Application ---

// 3. Create a LaML bin and application
echo "\nCreating LaML resources...\n";
$laml = safe('LaML bin', fn() => $client->compat->lamlBins->create(
    Name:     'Hold Music',
    Contents: '<Response><Say>Please hold.</Say></Response>',
));
$lamlSid = $laml ? $laml['sid'] : null;

$app = safe('Application', fn() => $client->compat->applications->create(
    FriendlyName: 'Demo App',
    VoiceUrl:     'https://example.com/voice',
));
$appSid = $app ? $app['sid'] : null;

// --- Messaging ---

// 4. Send an SMS
echo "\nMessaging operations...\n";
$msg = safe('Send SMS', fn() => $client->compat->messages->create(
    From: '+15559876543',
    To:   '+15551234567',
    Body: 'Hello from SignalWire!',
));
$msgSid = $msg ? $msg['sid'] : null;

// 5. List and get messages
safe('List messages', fn() => $client->compat->messages->list());
if ($msgSid) {
    safe('Get message', fn() => $client->compat->messages->get($msgSid));
    safe('List media',  fn() => $client->compat->messages->listMedia($msgSid));
}

// --- Calls ---

// 6. Outbound call
echo "\nCall operations...\n";
$call = safe('Create call', fn() => $client->compat->calls->create(
    From: '+15559876543',
    To:   '+15551234567',
    Url:  'https://example.com/voice-handler',
));
$callSid = $call ? $call['sid'] : null;

if ($callSid) {
    safe('Start recording', fn() => $client->compat->calls->startRecording($callSid));
    safe('Start stream', fn() =>
        $client->compat->calls->startStream($callSid, Url: 'wss://example.com/stream')
    );
}

// --- Conferences ---

// 7. Conference operations
echo "\nConference operations...\n";
$confs = safe('List conferences', fn() => $client->compat->conferences->list());
$confSid = null;
if ($confs && !empty($confs['data'])) {
    $confSid = $confs['data'][0]['sid'];
}
if ($confSid) {
    safe('Get conference',      fn() => $client->compat->conferences->get($confSid));
    safe('List participants',   fn() => $client->compat->conferences->listParticipants($confSid));
    safe('List conf recordings', fn() => $client->compat->conferences->listRecordings($confSid));
}

// --- Queues ---

// 8. Queue operations
echo "\nQueue operations...\n";
$queue = safe('Create queue', fn() =>
    $client->compat->queues->create(FriendlyName: 'compat-support-queue')
);
$qSid = $queue ? $queue['sid'] : null;

if ($qSid) {
    safe('List queue members', fn() => $client->compat->queues->listMembers($qSid));
}

// --- Recordings & Transcriptions ---

// 9. Recordings
echo "\nRecordings and transcriptions...\n";
$recs = safe('List recordings', fn() => $client->compat->recordings->list());
$firstRecSid = null;
if ($recs && !empty($recs['data'])) {
    $firstRecSid = $recs['data'][0]['sid'];
}
if ($firstRecSid) {
    safe('Get recording', fn() => $client->compat->recordings->get($firstRecSid));
}

$trans = safe('List transcriptions', fn() => $client->compat->transcriptions->list());
$firstTransSid = null;
if ($trans && !empty($trans['data'])) {
    $firstTransSid = $trans['data'][0]['sid'];
}
if ($firstTransSid) {
    safe('Get transcription', fn() => $client->compat->transcriptions->get($firstTransSid));
}

// --- Faxes ---

// 10. Fax operations
echo "\nFax operations...\n";
$fax = safe('Create fax', fn() => $client->compat->faxes->create(
    From:     '+15559876543',
    To:       '+15551234567',
    MediaUrl: 'https://example.com/document.pdf',
));
$faxSid = $fax ? $fax['sid'] : null;
if ($faxSid) {
    safe('Get fax', fn() => $client->compat->faxes->get($faxSid));
}

// --- Compat Accounts & Tokens ---

// 11. Accounts and tokens
echo "\nAccounts and compat tokens...\n";
safe('List accounts', fn() => $client->compat->accounts->list());
$compatToken = safe('Create compat token', fn() => $client->compat->tokens->create(name: 'demo-token'));
if ($compatToken && isset($compatToken['id'])) {
    safe('Delete compat token', fn() => $client->compat->tokens->delete($compatToken['id']));
}

// --- Project Tokens ---

// 12. Project token management
echo "\nProject tokens...\n";
$projToken = safe('Create project token', fn() => $client->projectNs->tokens->create(
    name:        'CI Token',
    permissions: ['calling', 'messaging', 'video'],
));
if ($projToken && isset($projToken['id'])) {
    safe('Update project token', fn() =>
        $client->projectNs->tokens->update($projToken['id'], name: 'CI Token (updated)')
    );
    safe('Delete project token', fn() => $client->projectNs->tokens->delete($projToken['id']));
}

// --- PubSub & Chat Tokens ---

// 13. PubSub and Chat tokens
echo "\nPubSub and Chat tokens...\n";
safe('PubSub token', fn() => $client->pubsub->createToken(
    channels: ['notifications' => ['read' => true, 'write' => true]],
    ttl:      3600,
));
safe('Chat token', fn() => $client->chat->createToken(
    memberId: 'user-alice',
    channels: ['general' => ['read' => true, 'write' => true]],
    ttl:      3600,
));

// --- Logs ---

// 14. Log queries
echo "\nQuerying logs...\n";
safe('Message logs',    fn() => $client->logs->messages->list());
safe('Voice logs',      fn() => $client->logs->voice->list());
safe('Fax logs',        fn() => $client->logs->fax->list());
safe('Conference logs', fn() => $client->logs->conferences->list());

$voiceLogs = safe('Voice log list', fn() => $client->logs->voice->list()) ?? [];
$firstVoice = ($voiceLogs['data'] ?? [null])[0] ?? [];
if (!empty($firstVoice['id'])) {
    safe('Voice log detail', fn() => $client->logs->voice->get($firstVoice['id']));
    safe('Voice log events', fn() => $client->logs->voice->listEvents($firstVoice['id']));
}

// --- Clean up ---
echo "\nCleaning up...\n";
if ($qSid)    safe('Delete queue',       fn() => $client->compat->queues->delete($qSid));
if ($appSid)  safe('Delete application', fn() => $client->compat->applications->delete($appSid));
if ($lamlSid) safe('Delete LaML bin',    fn() => $client->compat->lamlBins->delete($lamlSid));
if ($numSid)  safe('Delete number',      fn() => $client->compat->phoneNumbers->delete($numSid));
