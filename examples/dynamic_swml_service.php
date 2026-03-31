<?php
/**
 * Dynamic SWML Service Example
 *
 * Creates SWML services that generate different responses based on
 * POST data. Demonstrates dynamic greeting and call routing services.
 */

require 'vendor/autoload.php';

use SignalWire\SWML\SWMLService;

// --- Dynamic Greeting Service ---

$greeting = new SWMLService(name: 'dynamic-greeting', route: '/greeting');

// Default document
$greeting->addAnswerVerb();
$greeting->addVerb('play', ['url' => 'say:Hello, thank you for calling our service.']);
$greeting->addVerb('prompt', [
    'play'       => 'say:Please press 1 for sales, 2 for support, or 3 to leave a message.',
    'max_digits' => 1,
    'terminators' => '#',
]);
$greeting->addHangupVerb();

// Dynamic request handler
$greeting->onRequest(function (?array $requestData) use ($greeting) {
    if (!$requestData) {
        return null;
    }

    $greeting->resetDocument();
    $greeting->addAnswerVerb();

    $callerName = $requestData['caller_name'] ?? null;
    if ($callerName) {
        $greeting->addVerb('play', ['url' => "say:Hello {$callerName}, welcome back to our service!"]);
    } else {
        $greeting->addVerb('play', ['url' => 'say:Hello, thank you for calling our service.']);
    }

    $callerType = strtolower($requestData['caller_type'] ?? '');

    if ($callerType === 'vip') {
        $greeting->addVerb('play', ['url' => "say:As a VIP customer, you'll be connected to our priority support team."]);
        $greeting->addVerb('connect', ['to' => '+15551234567', 'timeout' => 30, 'answer_on_bridge' => true]);
    } elseif ($callerType === 'existing') {
        $greeting->addVerb('prompt', [
            'play'       => 'say:Please press 1 for account management, 2 for technical support, or 3 for billing.',
            'max_digits' => 1,
            'terminators' => '#',
        ]);
    } else {
        $greeting->addVerb('prompt', [
            'play'       => 'say:Please press 1 for sales, 2 for support, or 3 to leave a message.',
            'max_digits' => 1,
            'terminators' => '#',
        ]);
    }

    $department = strtolower($requestData['department'] ?? '');
    if ($department) {
        $numbers = [
            'sales'     => '+15551112222',
            'support'   => '+15553334444',
            'billing'   => '+15555556666',
            'technical' => '+15557778888',
        ];
        $greeting->addVerb('play', ['url' => "say:We'll connect you to our {$department} department."]);
        $greeting->addVerb('connect', [
            'to'      => $numbers[$department] ?? '+15559990000',
            'timeout' => 30,
        ]);
    }

    $greeting->addHangupVerb();
    return null;
});

echo "Starting Dynamic Greeting Service\n";
echo "Available at: http://localhost:3000/greeting\n";
echo "\nSend POST with JSON:\n";
echo json_encode([
    'caller_name' => 'John Doe',
    'caller_type' => 'vip',
    'department'  => 'technical',
], JSON_PRETTY_PRINT) . "\n\n";

$greeting->run();
