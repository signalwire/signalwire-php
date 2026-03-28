<?php
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

// 1. Dial an outbound call
echo "Dialing outbound call...\n";
$call = safe('Dial', fn() => $client->calling->dial(
    from: '+15559876543',
    to:   '+15551234567',
    url:  'https://example.com/call-handler',
));
$callId = ($call && isset($call['id'])) ? $call['id'] : 'demo-call-id';
echo "  Call initiated: {$callId}\n";

// 2. Play TTS audio
echo "\nPlaying TTS on call...\n";
safe('Play', fn() => $client->calling->play($callId,
    play: [['type' => 'tts', 'text' => 'Welcome to SignalWire.']],
));

// 3. Pause, resume, adjust volume, stop playback
echo "\nControlling playback...\n";
safe('Pause',       fn() => $client->calling->playPause($callId));
safe('Resume',      fn() => $client->calling->playResume($callId));
safe('Volume +2dB', fn() => $client->calling->playVolume($callId, volume: 2.0));
safe('Stop',        fn() => $client->calling->playStop($callId));

// 4. Record the call
echo "\nRecording call...\n";
safe('Record', fn() => $client->calling->record($callId, beep: true, format: 'mp3'));

// 5. Pause, resume, stop recording
echo "\nControlling recording...\n";
safe('Pause',  fn() => $client->calling->recordPause($callId));
safe('Resume', fn() => $client->calling->recordResume($callId));
safe('Stop',   fn() => $client->calling->recordStop($callId));

// 6. Transcribe the call
echo "\nTranscribing call...\n";
safe('Start transcribe', fn() => $client->calling->transcribe($callId, language: 'en-US'));
safe('Stop transcribe',  fn() => $client->calling->transcribeStop($callId));

// 7. Denoise the call
echo "\nEnabling denoise...\n";
safe('Start denoise', fn() => $client->calling->denoise($callId));
safe('Stop denoise',  fn() => $client->calling->denoiseStop($callId));

// 8. End the call
echo "\nEnding call...\n";
safe('End call', fn() => $client->calling->end($callId, reason: 'hangup'));
