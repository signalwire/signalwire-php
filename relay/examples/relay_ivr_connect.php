<?php

declare(strict_types=1);
/**
 * Example: IVR menu with DTMF collection, playback, and call connect.
 *
 * Answers an inbound call, plays a greeting, collects a digit, and
 * routes the caller based on their choice:
 *   1 - Hear a sales message
 *   2 - Hear a support message
 *   0 - Connect to a live agent at +19184238080
 *
 * Set these env vars:
 *   SIGNALWIRE_PROJECT_ID   - your SignalWire project ID
 *   SIGNALWIRE_API_TOKEN    - your SignalWire API token
 *   SIGNALWIRE_SPACE        - your SignalWire space (optional)
 */

require 'vendor/autoload.php';

use SignalWire\Relay\Client;
use SignalWire\Relay\Event;

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

const AGENT_NUMBER = '+19184238080';

/**
 * Helper to build a TTS play element
 *
 * @return array{type: string, params: array{text: string}}
 */
function tts(string $text): array
{
    return ['type' => 'tts', 'params' => ['text' => $text]];
}

/**
 * Pull the collect result out of a resolved play-and-collect event.
 *
 * The wire payload is `params.result = {type: 'digit', params: {digits: '1'}}`,
 * reached through {@see Event::getParams()} — NOT a `params()` accessor, which
 * does not exist on Event.
 *
 * @return array{type: string, digits: string} empty strings when the collect
 *   produced no result (timeout, or no event at all)
 */
function collectResult(?Event $event): array
{
    if ($event === null) {
        return ['type' => '', 'digits' => ''];
    }

    $result = $event->getParams()['result'] ?? null;
    if (!is_array($result)) {
        return ['type' => '', 'digits' => ''];
    }

    $type = $result['type'] ?? '';
    $params = $result['params'] ?? [];
    $digits = is_array($params) ? ($params['digits'] ?? '') : '';

    return [
        'type' => is_string($type) ? $type : '',
        'digits' => is_string($digits) ? $digits : '',
    ];
}

// Guard the blocking client so this file can be LOADED in-process (the helpers
// above are unit-tested) without opening a WebSocket. The client is built and
// run only when this file is the CLI entrypoint.
$isCliEntrypoint = PHP_SAPI === 'cli'
    && isset($_SERVER['argv'][0])
    && \realpath($_SERVER['argv'][0]) === __FILE__;

if (!$isCliEntrypoint) {
    return;
}

$client = new Client([
    'project'  => env('SIGNALWIRE_PROJECT_ID'),
    'token'    => env('SIGNALWIRE_API_TOKEN'),
    'host'     => envOr('SIGNALWIRE_SPACE', 'relay.signalwire.com'),
    'contexts' => ['default'],
]);

$client->onCall(function ($call) use ($client) {
    echo 'Incoming call: ' . $call->callId . "\n";
    $call->answer();

    // Play greeting and collect a single digit
    $collectAction = $call->playAndCollect(
        media: [
            tts('Welcome to SignalWire!'),
            tts('Press 1 for sales. Press 2 for support. Press 0 to speak with an agent.'),
        ],
        collect: [
            'digits'          => ['max' => 1, 'digit_timeout' => 5.0],
            'initial_timeout' => 10.0,
        ],
    );

    ['type' => $resultType, 'digits' => $digits] = collectResult($collectAction->wait());

    echo "Collect result: type={$resultType} digits={$digits}\n";

    if ($resultType === 'digit' && $digits === '1') {
        // Sales
        $action = $call->play(
            media: [tts('Thank you for your interest! A sales representative will be with you shortly.')],
        );
        $action->wait();
    } elseif ($resultType === 'digit' && $digits === '2') {
        // Support
        $action = $call->play(
            media: [tts('Please hold while we connect you to our support team.')],
        );
        $action->wait();
    } elseif ($resultType === 'digit' && $digits === '0') {
        // Connect to live agent
        $action = $call->play(
            media: [tts('Connecting you to an agent now. Please hold.')],
        );
        $action->wait();

        $fromNumber = ($call->device['params'] ?? [])['to_number'] ?? '';
        echo 'Connecting to ' . AGENT_NUMBER . " from {$fromNumber}\n";

        $call->connect([
            'devices' => [[
                ['type' => 'phone', 'params' => [
                    'to_number'   => AGENT_NUMBER,
                    'from_number' => $fromNumber,
                    'timeout'     => 30,
                ]],
            ]],
            'ringback' => [tts('Please wait while we connect your call.')],
        ]);

        // Stay on the call until the bridge ends
        while ($call->state !== 'ended') {
            $client->readOnce();
        }
        echo 'Connected call ended: ' . $call->callId . "\n";
        return;
    } else {
        // No input or invalid
        $action = $call->play(
            media: [tts("We didn't receive a valid selection.")],
        );
        $action->wait();
    }

    $call->hangup();
    echo 'Call ended: ' . $call->callId . "\n";
});

$client->connect();  // opens the WebSocket and authenticates (throws on failure)
echo "Waiting for inbound calls on context 'default' ...\n";
$client->run();
