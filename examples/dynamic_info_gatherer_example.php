<?php
/**
 * Dynamic InfoGatherer Example
 *
 * Shows how to use InfoGathererAgent with a callback function
 * to dynamically configure questions based on request parameters.
 *
 * Test URLs:
 *   /contact            (default questions)
 *   /contact?set=support   (customer support questions)
 *   /contact?set=medical   (medical intake questions)
 *   /contact?set=onboarding (employee onboarding questions)
 */

require 'vendor/autoload.php';

use SignalWire\Prefabs\InfoGathererAgent;

$questionSets = [
    'default' => [
        ['key_name' => 'name',   'question_text' => 'What is your full name?'],
        ['key_name' => 'phone',  'question_text' => 'What is your phone number?', 'confirm' => true],
        ['key_name' => 'reason', 'question_text' => 'How can I help you today?'],
    ],
    'support' => [
        ['key_name' => 'customer_name',   'question_text' => 'What is your name?'],
        ['key_name' => 'account_number',  'question_text' => 'What is your account number?', 'confirm' => true],
        ['key_name' => 'issue',           'question_text' => 'What issue are you experiencing?'],
        ['key_name' => 'priority',        'question_text' => 'How urgent is this issue? (Low, Medium, High)'],
    ],
    'medical' => [
        ['key_name' => 'patient_name', 'question_text' => "What is the patient's full name?"],
        ['key_name' => 'symptoms',     'question_text' => 'What symptoms are you experiencing?', 'confirm' => true],
        ['key_name' => 'duration',     'question_text' => 'How long have you had these symptoms?'],
        ['key_name' => 'medications',  'question_text' => 'Are you currently taking any medications?'],
    ],
    'onboarding' => [
        ['key_name' => 'full_name',   'question_text' => 'What is your full name?'],
        ['key_name' => 'email',       'question_text' => 'What is your email address?', 'confirm' => true],
        ['key_name' => 'company',     'question_text' => 'What company do you work for?'],
        ['key_name' => 'department',  'question_text' => 'What department will you be working in?'],
        ['key_name' => 'start_date',  'question_text' => 'What is your start date?'],
    ],
];

// Create agent in dynamic mode (no static questions)
$agent = new InfoGathererAgent(
    questions: null,
    name:  'contact-form',
    route: '/contact',
);

$agent->setQuestionCallback(function ($queryParams, $bodyParams, $headers) use ($questionSets) {
    $set = $queryParams['set'] ?? 'default';
    echo "Dynamic configuration requested: set={$set}\n";
    return $questionSets[$set] ?? $questionSets['default'];
});

echo "Starting Dynamic InfoGatherer Agent\n";
echo "Test URLs:\n";
echo "  /contact            (default)\n";
echo "  /contact?set=support   (customer support)\n";
echo "  /contact?set=medical   (medical intake)\n";
echo "  /contact?set=onboarding (employee onboarding)\n\n";

$agent->run();
