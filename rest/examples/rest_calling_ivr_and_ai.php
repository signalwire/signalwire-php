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
safe('Collect', fn() => $client->calling()->collect($CALL_ID,
    controlId: 'collect-1',
    digits: ['max' => 4, 'terminators' => '#'],
    initialTimeout: 5.0,
));
safe('Start input timers', fn() => $client->calling()->collectStartInputTimers($CALL_ID, 'collect-1'));
safe('Stop collect',       fn() => $client->calling()->collectStop($CALL_ID, 'collect-1'));

// 2. Answering machine detection
echo "\nDetecting answering machine...\n";
safe('Detect',      fn() => $client->calling()->detect($CALL_ID, ['type' => 'machine'], controlId: 'detect-1'));
safe('Stop detect', fn() => $client->calling()->detectStop($CALL_ID, 'detect-1'));

// 3. AI operations
echo "\nAI agent operations...\n";
safe('AI message', fn() => $client->calling()->aiMessage($CALL_ID,
    role: 'system',
    messageText: 'The customer wants to check their balance.',
));
safe('AI hold',   fn() => $client->calling()->aiHold($CALL_ID));
safe('AI unhold', fn() => $client->calling()->aiUnhold($CALL_ID));
safe('AI stop',   fn() => $client->calling()->aiStop($CALL_ID, 'ai-1'));

// 4. Live transcription and translation
echo "\nLive transcription and translation...\n";
safe('Live transcribe', fn() => $client->calling()->liveTranscribe($CALL_ID, ['start' => ['lang' => 'en-US']]));
safe('Live translate',  fn() => $client->calling()->liveTranslate($CALL_ID, ['start' => ['from_lang' => 'en-US', 'to_lang' => 'es-ES']]));

// 5. Tap (media fork)
echo "\nTap (media fork)...\n";
safe('Tap start', fn() => $client->calling()->tap($CALL_ID,
    tap:    ['type' => 'audio', 'params' => ['direction' => 'both']],
    device: ['type' => 'rtp', 'params' => ['addr' => '192.168.1.100', 'port' => 9000]],
    controlId: 'tap-1',
));
safe('Tap stop', fn() => $client->calling()->tapStop($CALL_ID, 'tap-1'));

// 6. Stream (WebSocket)
echo "\nStream (WebSocket)...\n";
safe('Stream start', fn() => $client->calling()->stream($CALL_ID, 'wss://example.com/audio-stream', controlId: 'stream-1'));
safe('Stream stop',  fn() => $client->calling()->streamStop($CALL_ID, 'stream-1'));

// 7. User event
echo "\nSending user event...\n";
safe('User event', fn() => $client->calling()->userEvent($CALL_ID, [
    'action' => 'agent_note',
    'data'   => ['note' => 'VIP caller'],
]));

// 8. SIP refer
echo "\nSIP refer...\n";
safe('SIP refer', fn() => $client->calling()->refer($CALL_ID, ['type' => 'sip', 'params' => ['to' => 'sip:support@example.com']]));

// 9. Fax stop commands
echo "\nFax stop commands...\n";
safe('Send fax stop',    fn() => $client->calling()->sendFaxStop($CALL_ID, 'fax-1'));
safe('Receive fax stop', fn() => $client->calling()->receiveFaxStop($CALL_ID, 'fax-1'));

// 10. Transfer and disconnect
echo "\nTransfer and disconnect...\n";
safe('Transfer',   fn() => $client->calling()->transfer($CALL_ID, ['transfer' => ['dest' => 'sip:destination@example.com']]));
safe('Update call', fn() => $client->calling()->update($CALL_ID, status: 'completed'));
safe('Disconnect', fn() => $client->calling()->disconnect($CALL_ID));
