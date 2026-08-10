<?php

declare(strict_types=1);
/**
 * RELAY Client Demo
 *
 * Shows how to use the RELAY client to answer inbound calls and play TTS.
 * This is a thin wrapper that demonstrates the RELAY client API.
 *
 * Set these env vars:
 *   SIGNALWIRE_PROJECT_ID
 *   SIGNALWIRE_API_TOKEN
 *   SIGNALWIRE_SPACE
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
    echo 'Incoming call from RELAY: ' . $call->callId . "\n";
    $call->answer();

    // Play a welcome message
    $action = $call->play(
        media: [[
            'type'   => 'tts',
            'params' => ['text' => 'Hello! This is a demo of the RELAY client in PHP.'],
        ]],
    );
    $action->wait();

    // Say goodbye
    $bye = $call->play(
        media: [[
            'type'   => 'tts',
            'params' => ['text' => 'Thank you for testing. Goodbye!'],
        ]],
    );
    $bye->wait();

    $call->hangup();
    echo 'Call ended: ' . $call->callId . "\n";
});

$client->connect();  // opens the WebSocket and authenticates (throws on failure)

echo "RELAY Demo: Waiting for inbound calls...\n";
$client->run();
