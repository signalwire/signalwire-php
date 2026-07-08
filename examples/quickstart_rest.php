<?php

/**
 * Quickstart: the minimal REST client from the top-level README.
 *
 * Synchronous HTTP client for managing SignalWire resources and controlling
 * calls — no WebSocket required. Set SIGNALWIRE_PROJECT_ID /
 * SIGNALWIRE_API_TOKEN / SIGNALWIRE_SPACE and run with `php quickstart_rest.php`.
 *
 * The `construct` region below is included verbatim into README.md by the
 * readme-include gate.
 */

$callId = $_ENV['SIGNALWIRE_CALL_ID'] ?? 'call-id-here';

// region: construct
require 'vendor/autoload.php';

use SignalWire\REST\RestClient;

$client = new RestClient(
    projectId: $_ENV['SIGNALWIRE_PROJECT_ID'],
    token:     $_ENV['SIGNALWIRE_API_TOKEN'],
    space:     $_ENV['SIGNALWIRE_SPACE'],
);

$client->fabric()->aiAgents()->create(['name' => 'Support Bot', 'prompt' => ['text' => 'You are helpful.']]);
$client->calling()->play($callId, play: [['type' => 'tts', 'text' => 'Hello!']]);
$client->phoneNumbers()->search(['areaCode' => '512']);
$client->datasphere()->documents()->search(queryString: 'billing policy');
// endregion: construct
