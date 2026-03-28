<?php
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

$client = new Client(
    project:  $_ENV['SIGNALWIRE_PROJECT_ID']  ?? die("Set SIGNALWIRE_PROJECT_ID\n"),
    token:    $_ENV['SIGNALWIRE_API_TOKEN']    ?? die("Set SIGNALWIRE_API_TOKEN\n"),
    host:     $_ENV['SIGNALWIRE_SPACE']        ?? 'relay.signalwire.com',
    contexts: ['default'],
);

$client->onCall(function ($call) {
    echo "Incoming call: " . $call->callId() . "\n";
    $call->answer();

    $action = $call->play(
        media: [['type' => 'tts', 'params' => ['text' => 'Welcome to SignalWire!']]],
    );
    $action->wait();

    $call->hangup();
    echo "Call ended: " . $call->callId() . "\n";
});

$client->connectWs()  or die("Connection failed\n");
$client->authenticate();
echo "Waiting for inbound calls on context 'default' ...\n";
$client->run();
