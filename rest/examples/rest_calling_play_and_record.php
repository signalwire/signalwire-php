<?php

declare(strict_types=1);
/**
 * Example: Control an active call with media operations (play, record, transcribe, denoise).
 *
 * NOTE: These commands require an active call. The call_id used here is
 * illustrative -- in production you would obtain it from a dial response.
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

// 1. Dial an outbound call
echo "Dialing outbound call...\n";
$call = safe('Dial', fn () => $client->calling()->dial(
    from_: '+15559876543',
    to:    '+15551234567',
    url:   'https://example.com/call-handler',
));
$callId = ($call && isset($call['id'])) ? $call['id'] : 'demo-call-id';
echo "  Call initiated: {$callId}\n";

// 2. Play TTS audio
echo "\nPlaying TTS on call...\n";
safe('Play', fn () => $client->calling()->play($callId, [
    ['type' => 'tts', 'params' => ['text' => 'Welcome to SignalWire.']],
], controlId: 'play-1'));

// 3. Pause, resume, adjust volume, stop playback
echo "\nControlling playback...\n";
safe('Pause', fn () => $client->calling()->playPause($callId, 'play-1'));
safe('Resume', fn () => $client->calling()->playResume($callId, 'play-1'));
safe('Volume +2dB', fn () => $client->calling()->playVolume($callId, 'play-1', 2.0));
safe('Stop', fn () => $client->calling()->playStop($callId, 'play-1'));

// 4. Record the call
echo "\nRecording call...\n";
safe('Record', fn () => $client->calling()->record($callId, controlId: 'record-1', audio: ['beep' => true, 'format' => 'mp3']));

// 5. Pause, resume, stop recording
echo "\nControlling recording...\n";
safe('Pause', fn () => $client->calling()->recordPause($callId, 'record-1'));
safe('Resume', fn () => $client->calling()->recordResume($callId, 'record-1'));
safe('Stop', fn () => $client->calling()->recordStop($callId, 'record-1'));

// 6. Transcribe the call
echo "\nTranscribing call...\n";
safe('Start transcribe', fn () => $client->calling()->transcribe($callId, controlId: 'transcribe-1'));
safe('Stop transcribe', fn () => $client->calling()->transcribeStop($callId, 'transcribe-1'));

// 7. Denoise the call
echo "\nEnabling denoise...\n";
safe('Start denoise', fn () => $client->calling()->denoise($callId));
safe('Stop denoise', fn () => $client->calling()->denoiseStop($callId));

// 8. End the call
echo "\nEnding call...\n";
safe('End call', fn () => $client->calling()->end($callId, reason: 'hangup'));
