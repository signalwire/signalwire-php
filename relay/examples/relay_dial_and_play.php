<?php

declare(strict_types=1);
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

/** Read a required setting from the environment, or stop with a clear message. */
function env(string $name): string
{
    $value = $_ENV[$name] ?? null;
    if (!is_string($value) || $value === '') {
        exit("Set {$name}\n");
    }
    return $value;
}

/** Read an optional setting from the environment, falling back to a default. */
function envOr(string $name, string $default): string
{
    $value = $_ENV[$name] ?? null;
    return is_string($value) && $value !== '' ? $value : $default;
}

$fromNumber = env('RELAY_FROM_NUMBER');
$toNumber   = env('RELAY_TO_NUMBER');

$client = new Client([
    'project' => env('SIGNALWIRE_PROJECT_ID'),
    'token'   => env('SIGNALWIRE_API_TOKEN'),
    'host'    => envOr('SIGNALWIRE_SPACE', 'relay.signalwire.com'),
]);

$client->connect();  // opens the WebSocket and authenticates (throws on failure)
echo "Connected\n";

// Dial the number
try {
    $call = $client->dial(
        [[
            ['type' => 'phone', 'params' => [
                'to_number'   => $toNumber,
                'from_number' => $fromNumber,
            ]],
        ]],
        ['dial_timeout' => 30],
    );
} catch (\Exception $e) {
    echo 'Dial failed: ' . $e->getMessage() . "\n";
    $client->disconnect();
    exit(1);
}

echo "Dialing {$toNumber} from {$fromNumber} -- call_id: " . $call->callId . "\n";
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
    if ($call->state === 'ended') {
        break;
    }
    $client->readOnce();
}
echo "Call ended\n";

$client->disconnect();
echo "Disconnected\n";
