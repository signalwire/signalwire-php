<?php

/**
 * Quickstart: the minimal RELAY client from the top-level README.
 *
 * Connects to SignalWire over WebSocket (Blade protocol) and gives imperative,
 * blocking control over live calls. Set SIGNALWIRE_PROJECT_ID /
 * SIGNALWIRE_API_TOKEN / SIGNALWIRE_SPACE and run with `php quickstart_relay.php`.
 *
 * The `construct` region below is included verbatim into README.md by the
 * readme-include gate.
 */

// region: construct
require 'vendor/autoload.php';

use SignalWire\Relay\Client;

$client = new Client([
    'project'  => $_ENV['SIGNALWIRE_PROJECT_ID'],
    'token'    => $_ENV['SIGNALWIRE_API_TOKEN'],
    'host'     => $_ENV['SIGNALWIRE_SPACE'] ?? 'relay.signalwire.com',
    'contexts' => ['default'],
]);

$client->onCall(function ($call) {
    $call->answer();
    $action = $call->play(media: [
        ['type' => 'tts', 'params' => ['text' => 'Welcome to SignalWire!']],
    ]);
    $action->wait();
    $call->hangup();
});

$client->connect();  // opens the WebSocket and authenticates
$client->run();
// endregion: construct
