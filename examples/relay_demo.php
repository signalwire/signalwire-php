<?php
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

$client = new Client(
    project:  $_ENV['SIGNALWIRE_PROJECT_ID'] ?? die("Set SIGNALWIRE_PROJECT_ID\n"),
    token:    $_ENV['SIGNALWIRE_API_TOKEN']  ?? die("Set SIGNALWIRE_API_TOKEN\n"),
    host:     $_ENV['SIGNALWIRE_SPACE']      ?? 'relay.signalwire.com',
    contexts: ['default'],
);

$client->onCall(function ($call) {
    echo "Incoming call from RELAY: " . $call->callId() . "\n";
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
    echo "Call ended: " . $call->callId() . "\n";
});

$client->connectWs()  or die("WebSocket connection failed\n");
$client->authenticate();

echo "RELAY Demo: Waiting for inbound calls...\n";
$client->run();
