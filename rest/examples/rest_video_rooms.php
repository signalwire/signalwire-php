<?php

declare(strict_types=1);
/**
 * Example: Video rooms for team standup and conference streaming.
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

// --- Video Rooms ---

// 1. Create a video room
echo "Creating video room...\n";
$room = $client->video()->rooms()->create([
    'name'         => 'daily-standup',
    'display_name' => 'Daily Standup',
    'max_members'  => 10,
    'layout'       => 'grid-responsive',
]);
$roomId = field($room, 'id', 'demo-room-id');
echo "  Created room: {$roomId}\n";

// 2. List video rooms
echo "\nListing video rooms...\n";
$rooms = safe('List rooms', fn () => $client->video()->rooms()->list());
if ($rooms) {
    foreach (array_slice(dataRows($rooms), 0, 5) as $r) {
        echo '  - ' . field($r, 'id') . ': ' . field($r, 'name', 'unnamed') . "\n";
    }
}

// 3. Generate a join token
echo "\nGenerating room token...\n";
safe('Room token', function () use ($client) {
    $token = $client->video()->roomTokens()->create(
        roomName:    'daily-standup',
        userName:    'alice',
        permissions: ['room.self.audio_mute', 'room.self.video_mute'],
    );
    $t = field($token, 'token', '');
    if ($t) {
        echo '  Token: ' . substr($t, 0, 40) . "...\n";
    }
});

// --- Sessions ---

// 4. List room sessions
echo "\nListing room sessions...\n";
$sessions = safe('List sessions', fn () => $client->video()->roomSessions()->list());
if ($sessions) {
    foreach (array_slice(dataRows($sessions), 0, 3) as $s) {
        echo '  - Session ' . field($s, 'id') . ': ' . field($s, 'status', 'unknown') . "\n";
    }
}

// 5. Get session details
if ($sessions && !empty($sessions['data'])) {
    $first = $sessions['data'][0];
    if (!empty($first['id'])) {
        $sid = $first['id'];
        safe('Session detail', function () use ($client, $sid) {
            $detail = $client->video()->roomSessions()->get($sid);
            echo '  Session: ' . field($detail, 'name', 'N/A')
                . ' (' . field($detail, 'status', 'N/A') . ")\n";
        });
        safe('Session members', function () use ($client, $sid) {
            $members = $client->video()->roomSessions()->listMembers($sid);
            echo '  Members: ' . count(dataRows($members)) . "\n";
        });
        safe('Session events', function () use ($client, $sid) {
            $events = $client->video()->roomSessions()->listEvents($sid);
            echo '  Events: ' . count(dataRows($events)) . "\n";
        });
        safe('Session recordings', function () use ($client, $sid) {
            $recs = $client->video()->roomSessions()->listRecordings($sid);
            echo '  Recordings: ' . count(dataRows($recs)) . "\n";
        });
    }
}

// --- Room Recordings ---

// 6. List room recordings
echo "\nListing room recordings...\n";
$roomRecs = safe('List recordings', fn () => $client->video()->roomRecordings()->list());
if ($roomRecs && !empty($roomRecs['data'])) {
    foreach (array_slice(dataRows($roomRecs), 0, 3) as $rr) {
        echo '  - Recording ' . field($rr, 'id') . ': ' . field($rr, 'duration', 'N/A') . "s\n";
    }

    if (!empty($roomRecs['data'][0]['id'])) {
        safe('Get recording', function () use ($client, $roomRecs) {
            $recDetail = $client->video()->roomRecordings()->get($roomRecs['data'][0]['id']);
            echo '  Recording detail: ' . field($recDetail, 'duration', 'N/A') . "s\n";
        });
        safe('Recording events', function () use ($client, $roomRecs) {
            $recEvents = $client->video()->roomRecordings()->listEvents($roomRecs['data'][0]['id']);
            echo '  Recording events: ' . count(dataRows($recEvents)) . "\n";
        });
    }
}

// --- Video Conferences ---

// 7. Create a video conference
echo "\nCreating video conference...\n";
$confId = null;
$conf = safe('Create conference', fn () => $client->video()->conferences()->create([
    'name'         => 'all-hands-stream',
    'display_name' => 'All Hands Meeting',
]));
$confId = $conf ? ($conf['id'] ?? null) : null;

// 8. List conference tokens
if ($confId) {
    echo "\nListing conference tokens...\n";
    safe('Conference tokens', function () use ($client, $confId) {
        $tokens = $client->video()->conferences()->listConferenceTokens($confId);
        foreach (dataRows($tokens) as $t) {
            echo '  - Token: ' . field($t, 'id', 'unknown') . "\n";
        }
    });
}

// 9. Create a stream
$streamId = null;
if ($confId) {
    echo "\nCreating stream on conference...\n";
    $stream = safe(
        'Create stream',
        fn () =>
        $client->video()->conferences()->createStream($confId, 'rtmp://live.example.com/stream-key')
    );
    $streamId = $stream ? ($stream['id'] ?? null) : null;
}

// 10. Get and update stream
if ($streamId) {
    echo "\nManaging stream {$streamId}...\n";
    safe('Get stream', function () use ($client, $streamId) {
        $sDetail = $client->video()->streams()->get($streamId);
        echo '  Stream URL: ' . field($sDetail, 'url', 'N/A') . "\n";
    });
    safe(
        'Update stream',
        fn () =>
        $client->video()->streams()->update($streamId, 'rtmp://backup.example.com/stream-key')
    );
}

// 11. Clean up
echo "\nCleaning up...\n";
if ($streamId) {
    safe('Delete stream', fn () => $client->video()->streams()->delete($streamId));
}
if ($confId) {
    safe('Delete conference', fn () => $client->video()->conferences()->delete($confId));
}
$client->video()->rooms()->delete($roomId);
echo "  Deleted room {$roomId}\n";
