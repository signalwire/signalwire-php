<?php
/**
 * Call Flow and Actions Demo
 *
 * Demonstrates call flow verbs (pre/post-answer), debug events, and
 * SwaigFunctionResult actions (connect, SMS, record, hold, etc.).
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

$agent = new AgentBase(
    name:       'call-flow-demo',
    route:      '/call-flow',
    autoAnswer: true,
    recordCall: true,
);

// Configure prompt
$agent->promptAddSection(
    'Role',
    'You are a call routing assistant that can transfer calls, send SMS, '
    . 'and manage call state.',
    bullets: [
        'Use transfer_call to connect callers to the right department',
        'Use send_notification to send an SMS to the caller',
        'Use put_on_hold to hold the caller while looking something up',
    ],
);

// Pre-answer verb: play hold music before the AI answers
$agent->addPreAnswerVerb('play', [
    'url'    => 'https://cdn.signalwire.com/default-music/welcome.mp3',
    'volume' => -5,
]);

// Post-AI verb: play goodbye message after AI disconnects
$agent->addPostAiVerb('play', [
    'url' => 'say:Thank you for calling. Goodbye.',
]);

// Enable debug events
$agent->enableDebugEvents(true);

// Debug event handler
$agent->onDebugEvent(function ($event) {
    echo "DEBUG EVENT: " . (is_array($event) ? json_encode($event) : $event) . "\n";
});

// --- Tool: transfer_call ---
$agent->defineTool(
    name:        'transfer_call',
    description: 'Transfer the call to a phone number',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'department' => ['type' => 'string', 'description' => 'Department name (sales, support, billing)'],
        ],
        'required' => ['department'],
    ],
    handler: function (array $args, array $raw): FunctionResult {
        $numbers = [
            'sales'   => '+15551001001',
            'support' => '+15551002002',
            'billing' => '+15551003003',
        ];
        $dept = strtolower($args['department'] ?? 'support');
        $num  = $numbers[$dept] ?? $numbers['support'];

        $result = new FunctionResult("Transferring you to {$dept} now.");
        $result->connect($num);
        return $result;
    },
);

// --- Tool: send_notification ---
$agent->defineTool(
    name:        'send_notification',
    description: 'Send an SMS notification to the caller',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'message' => ['type' => 'string', 'description' => 'SMS message to send'],
        ],
        'required' => ['message'],
    ],
    handler: function (array $args, array $raw): FunctionResult {
        $result = new FunctionResult('SMS notification sent.');
        $result->sendSms(
            toNumber:   '+15551234567',
            fromNumber: '+15559876543',
            body:       $args['message'] ?? 'Notification from call center',
        );
        return $result;
    },
);

// --- Tool: put_on_hold ---
$agent->defineTool(
    name:        'put_on_hold',
    description: 'Put the caller on hold briefly',
    parameters:  ['type' => 'object', 'properties' => []],
    handler: function (array $args, array $raw): FunctionResult {
        $result = new FunctionResult('Placing you on hold for a moment.');
        $result->hold(30);
        return $result;
    },
);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

echo "Starting Call Flow Demo\n";
echo "Available at: http://localhost:3000/call-flow\n";

$agent->run();
