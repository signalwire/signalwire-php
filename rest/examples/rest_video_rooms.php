<?php
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

// --- Video Rooms ---

// 1. Create a video room
echo "Creating video room...\n";
$room = $client->video->rooms->create(
    name:        'daily-standup',
    displayName: 'Daily Standup',
    maxMembers:  10,
    layout:      'grid-responsive',
);
$roomId = $room['id'];
echo "  Created room: {$roomId}\n";

// 2. List video rooms
echo "\nListing video rooms...\n";
$rooms = safe('List rooms', fn() => $client->video->rooms->list());
if ($rooms) {
    foreach (array_slice($rooms['data'] ?? [], 0, 5) as $r) {
        echo "  - {$r['id']}: " . ($r['name'] ?? 'unnamed') . "\n";
    }
}

// 3. Generate a join token
echo "\nGenerating room token...\n";
safe('Room token', function () use ($client) {
    $token = $client->video->roomTokens->create(
        roomName:    'daily-standup',
        userName:    'alice',
        permissions: ['room.self.audio_mute', 'room.self.video_mute'],
    );
    $t = $token['token'] ?? '';
    if ($t) echo "  Token: " . substr($t, 0, 40) . "...\n";
});

// --- Sessions ---

// 4. List room sessions
echo "\nListing room sessions...\n";
$sessions = safe('List sessions', fn() => $client->video->roomSessions->list());
if ($sessions) {
    foreach (array_slice($sessions['data'] ?? [], 0, 3) as $s) {
        echo "  - Session {$s['id']}: " . ($s['status'] ?? 'unknown') . "\n";
    }
}

// 5. Get session details
if ($sessions && !empty($sessions['data'])) {
    $first = $sessions['data'][0];
    if (!empty($first['id'])) {
        $sid = $first['id'];
        safe('Session detail', function () use ($client, $sid) {
            $detail = $client->video->roomSessions->get($sid);
            echo "  Session: " . ($detail['name'] ?? 'N/A')
                . " (" . ($detail['status'] ?? 'N/A') . ")\n";
        });
        safe('Session members', function () use ($client, $sid) {
            $members = $client->video->roomSessions->listMembers($sid);
            echo "  Members: " . count($members['data'] ?? []) . "\n";
        });
        safe('Session events', function () use ($client, $sid) {
            $events = $client->video->roomSessions->listEvents($sid);
            echo "  Events: " . count($events['data'] ?? []) . "\n";
        });
        safe('Session recordings', function () use ($client, $sid) {
            $recs = $client->video->roomSessions->listRecordings($sid);
            echo "  Recordings: " . count($recs['data'] ?? []) . "\n";
        });
    }
}

// --- Room Recordings ---

// 6. List room recordings
echo "\nListing room recordings...\n";
$roomRecs = safe('List recordings', fn() => $client->video->roomRecordings->list());
if ($roomRecs && !empty($roomRecs['data'])) {
    foreach (array_slice($roomRecs['data'], 0, 3) as $rr) {
        echo "  - Recording {$rr['id']}: " . ($rr['duration'] ?? 'N/A') . "s\n";
    }

    if (!empty($roomRecs['data'][0]['id'])) {
        safe('Get recording', function () use ($client, $roomRecs) {
            $recDetail = $client->video->roomRecordings->get($roomRecs['data'][0]['id']);
            echo "  Recording detail: " . ($recDetail['duration'] ?? 'N/A') . "s\n";
        });
        safe('Recording events', function () use ($client, $roomRecs) {
            $recEvents = $client->video->roomRecordings->listEvents($roomRecs['data'][0]['id']);
            echo "  Recording events: " . count($recEvents['data'] ?? []) . "\n";
        });
    }
}

// --- Video Conferences ---

// 7. Create a video conference
echo "\nCreating video conference...\n";
$confId = null;
$conf = safe('Create conference', fn() => $client->video->conferences->create(
    name:        'all-hands-stream',
    displayName: 'All Hands Meeting',
));
$confId = $conf ? $conf['id'] : null;

// 8. List conference tokens
if ($confId) {
    echo "\nListing conference tokens...\n";
    safe('Conference tokens', function () use ($client, $confId) {
        $tokens = $client->video->conferences->listConferenceTokens($confId);
        foreach (($tokens['data'] ?? []) as $t) {
            echo "  - Token: " . ($t['id'] ?? 'unknown') . "\n";
        }
    });
}

// 9. Create a stream
$streamId = null;
if ($confId) {
    echo "\nCreating stream on conference...\n";
    $stream = safe('Create stream', fn() =>
        $client->video->conferences->createStream($confId, url: 'rtmp://live.example.com/stream-key')
    );
    $streamId = $stream ? $stream['id'] : null;
}

// 10. Get and update stream
if ($streamId) {
    echo "\nManaging stream {$streamId}...\n";
    safe('Get stream', function () use ($client, $streamId) {
        $sDetail = $client->video->streams->get($streamId);
        echo "  Stream URL: " . ($sDetail['url'] ?? 'N/A') . "\n";
    });
    safe('Update stream', fn() =>
        $client->video->streams->update($streamId, url: 'rtmp://backup.example.com/stream-key')
    );
}

// 11. Clean up
echo "\nCleaning up...\n";
if ($streamId) safe('Delete stream',     fn() => $client->video->streams->delete($streamId));
if ($confId)   safe('Delete conference', fn() => $client->video->conferences->delete($confId));
$client->video->rooms->delete($roomId);
echo "  Deleted room {$roomId}\n";
