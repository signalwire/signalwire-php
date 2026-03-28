<?php
/**
 * Example: IVR input collection, AI operations, and advanced call control.
 *
 * NOTE: These commands require an active call. The call_id used here is
 * illustrative -- in production you would obtain it from a dial response or
 * inbound call event.
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

$CALL_ID = 'demo-call-id';

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

// 1. Collect DTMF input
echo "Collecting DTMF input...\n";
safe('Collect', fn() => $client->calling->collect($CALL_ID,
    digits: ['max' => 4, 'terminators' => '#'],
    play:   [['type' => 'tts', 'text' => 'Enter your PIN followed by pound.']],
));
safe('Start input timers', fn() => $client->calling->collectStartInputTimers($CALL_ID));
safe('Stop collect',       fn() => $client->calling->collectStop($CALL_ID));

// 2. Answering machine detection
echo "\nDetecting answering machine...\n";
safe('Detect',      fn() => $client->calling->detect($CALL_ID, type: 'machine'));
safe('Stop detect', fn() => $client->calling->detectStop($CALL_ID));

// 3. AI operations
echo "\nAI agent operations...\n";
safe('AI message', fn() => $client->calling->aiMessage($CALL_ID,
    message: 'The customer wants to check their balance.',
));
safe('AI hold',   fn() => $client->calling->aiHold($CALL_ID));
safe('AI unhold', fn() => $client->calling->aiUnhold($CALL_ID));
safe('AI stop',   fn() => $client->calling->aiStop($CALL_ID));

// 4. Live transcription and translation
echo "\nLive transcription and translation...\n";
safe('Live transcribe', fn() => $client->calling->liveTranscribe($CALL_ID, language: 'en-US'));
safe('Live translate',  fn() => $client->calling->liveTranslate($CALL_ID, language: 'es'));

// 5. Tap (media fork)
echo "\nTap (media fork)...\n";
safe('Tap start', fn() => $client->calling->tap($CALL_ID,
    tap:    ['type' => 'audio', 'direction' => 'both'],
    device: ['type' => 'rtp', 'addr' => '192.168.1.100', 'port' => 9000],
));
safe('Tap stop', fn() => $client->calling->tapStop($CALL_ID));

// 6. Stream (WebSocket)
echo "\nStream (WebSocket)...\n";
safe('Stream start', fn() => $client->calling->stream($CALL_ID, url: 'wss://example.com/audio-stream'));
safe('Stream stop',  fn() => $client->calling->streamStop($CALL_ID));

// 7. User event
echo "\nSending user event...\n";
safe('User event', fn() => $client->calling->userEvent($CALL_ID,
    eventName: 'agent_note',
    data:      ['note' => 'VIP caller'],
));

// 8. SIP refer
echo "\nSIP refer...\n";
safe('SIP refer', fn() => $client->calling->refer($CALL_ID, sipUri: 'sip:support@example.com'));

// 9. Fax stop commands
echo "\nFax stop commands...\n";
safe('Send fax stop',    fn() => $client->calling->sendFaxStop($CALL_ID));
safe('Receive fax stop', fn() => $client->calling->receiveFaxStop($CALL_ID));

// 10. Transfer and disconnect
echo "\nTransfer and disconnect...\n";
safe('Transfer',   fn() => $client->calling->transfer($CALL_ID, dest: '+15559999999'));
safe('Update call', fn() => $client->calling->update(callId: $CALL_ID, metadata: ['priority' => 'high']));
safe('Disconnect', fn() => $client->calling->disconnect($CALL_ID));
