<?php
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

$AGENT_NUMBER = '+19184238080';

$client = new Client(
    project:  $_ENV['SIGNALWIRE_PROJECT_ID'] ?? die("Set SIGNALWIRE_PROJECT_ID\n"),
    token:    $_ENV['SIGNALWIRE_API_TOKEN']  ?? die("Set SIGNALWIRE_API_TOKEN\n"),
    host:     $_ENV['SIGNALWIRE_SPACE']      ?? 'relay.signalwire.com',
    contexts: ['default'],
);

/** Helper to build a TTS play element */
function tts(string $text): array
{
    return ['type' => 'tts', 'params' => ['text' => $text]];
}

$client->onCall(function ($call) use ($client, $AGENT_NUMBER) {
    echo "Incoming call: " . $call->callId() . "\n";
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

    $resultEvent = $collectAction->wait();
    $result     = [];
    $resultType = '';
    $digits     = '';

    if ($resultEvent && method_exists($resultEvent, 'params')) {
        $result     = $resultEvent->params()['result'] ?? [];
        $resultType = $result['type'] ?? '';
        $digits     = ($result['params'] ?? [])['digits'] ?? '';
    }

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

        $fromNumber = ($call->device()['params'] ?? [])['to_number'] ?? '';
        echo "Connecting to {$AGENT_NUMBER} from {$fromNumber}\n";

        $call->connect(
            devices: [[
                ['type' => 'phone', 'params' => [
                    'to_number'   => $AGENT_NUMBER,
                    'from_number' => $fromNumber,
                    'timeout'     => 30,
                ]],
            ]],
            ringback: [tts('Please wait while we connect your call.')],
        );

        // Stay on the call until the bridge ends
        while ($call->state() !== 'ended') {
            $client->readOnce();
        }
        echo "Connected call ended: " . $call->callId() . "\n";
        return;
    } else {
        // No input or invalid
        $action = $call->play(
            media: [tts("We didn't receive a valid selection.")],
        );
        $action->wait();
    }

    $call->hangup();
    echo "Call ended: " . $call->callId() . "\n";
});

$client->connectWs()  or die("Connection failed\n");
$client->authenticate();
echo "Waiting for inbound calls on context 'default' ...\n";
$client->run();
