<?php

declare(strict_types=1);

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
$callId = is_string($callId) ? $callId : 'call-id-here';

// region: construct
require 'vendor/autoload.php';

use SignalWire\REST\RestClient;

/** Read a required setting from the environment, or stop with a clear message. */
function env(string $name): string
{
    $value = $_ENV[$name] ?? null;
    if (!is_string($value) || $value === '') {
        exit("Set {$name}\n");
    }
    return $value;
}

$client = new RestClient(
    project: env('SIGNALWIRE_PROJECT_ID'),
    token:   env('SIGNALWIRE_API_TOKEN'),
    host:    env('SIGNALWIRE_SPACE'),
);

$client->fabric()->aiAgents()->create(['name' => 'Support Bot', 'prompt' => ['text' => 'You are helpful.']]);
$client->calling()->play($callId, play: [['type' => 'tts', 'params' => ['text' => 'Hello!']]]);
$client->phoneNumbers()->search(['areacode' => '512']);
$client->datasphere()->documents()->search(queryString: 'billing policy');
// endregion: construct
