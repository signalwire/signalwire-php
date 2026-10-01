<?php

declare(strict_types=1);
/**
 * Example: Answer an inbound call and say "Welcome to SignalWire!"
 *
 * Set these env vars:
 *   SIGNALWIRE_PROJECT_ID   - your SignalWire project ID
 *   SIGNALWIRE_API_TOKEN    - your SignalWire API token
 *   SIGNALWIRE_SPACE        - your SignalWire space (e.g. example.signalwire.com)
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

$client = new Client([
    'project'  => env('SIGNALWIRE_PROJECT_ID'),
    'token'    => env('SIGNALWIRE_API_TOKEN'),
    'host'     => envOr('SIGNALWIRE_SPACE', 'relay.signalwire.com'),
    'contexts' => ['default'],
]);

$client->onCall(function ($call) {
    echo 'Incoming call: ' . $call->callId . "\n";
    $call->answer();

    $action = $call->play(
        media: [['type' => 'tts', 'params' => ['text' => 'Welcome to SignalWire!']]],
    );
    $action->wait();

    $call->hangup();
    echo 'Call ended: ' . $call->callId . "\n";
});

$client->connect();  // opens the WebSocket and authenticates (throws on failure)
echo "Waiting for inbound calls on context 'default' ...\n";
$client->run();
