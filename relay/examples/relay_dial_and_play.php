<?php
/**
 * Dial a number and play "Welcome to SignalWire" using the RELAY client.
 *
 * Requires env vars:
 *     SIGNALWIRE_PROJECT_ID
 *     SIGNALWIRE_API_TOKEN
 *     RELAY_FROM_NUMBER   - a number on your SignalWire project
 *     RELAY_TO_NUMBER     - destination to call
 */

require 'vendor/autoload.php';

use SignalWire\Relay\Client;

$fromNumber = $_ENV['RELAY_FROM_NUMBER'] ?? die("Set RELAY_FROM_NUMBER\n");
$toNumber   = $_ENV['RELAY_TO_NUMBER']   ?? die("Set RELAY_TO_NUMBER\n");

$client = new Client(
    project: $_ENV['SIGNALWIRE_PROJECT_ID'] ?? die("Set SIGNALWIRE_PROJECT_ID\n"),
    token:   $_ENV['SIGNALWIRE_API_TOKEN']  ?? die("Set SIGNALWIRE_API_TOKEN\n"),
    host:    $_ENV['SIGNALWIRE_SPACE']      ?? 'relay.signalwire.com',
);

$client->connectWs()  or die("Connection failed\n");
$client->authenticate();
echo "Connected -- protocol: " . $client->protocol() . "\n";

// Dial the number
try {
    $call = $client->dial(
        devices: [[
            ['type' => 'phone', 'params' => [
                'to_number'   => $toNumber,
                'from_number' => $fromNumber,
            ]],
        ]],
        timeout: 30,
    );
} catch (\Exception $e) {
    echo "Dial failed: " . $e->getMessage() . "\n";
    $client->disconnectWs();
    exit(1);
}

echo "Dialing {$toNumber} from {$fromNumber} -- call_id: " . $call->callId() . "\n";
echo "Call answered -- playing TTS\n";

// Play TTS
$playAction = $call->play(
    media: [['type' => 'tts', 'params' => ['text' => 'Welcome to SignalWire']]],
);

// Wait for playback to finish
$playAction->wait(timeout: 15);
echo "Playback finished -- hanging up\n";

$call->hangup();

// Allow the ended event to arrive
for ($i = 0; $i < 50; $i++) {
    if ($call->state() === 'ended') {
        break;
    }
    $client->readOnce();
}
echo "Call ended\n";

$client->disconnectWs();
echo "Disconnected\n";
