<?php
/**
 * InfoGatherer Prefab Example
 *
 * Demonstrates using the InfoGatherer prefab agent to collect structured
 * information from callers via a guided question flow.
 */

require 'vendor/autoload.php';

use SignalWire\Prefabs\InfoGathererAgent;

$agent = new InfoGathererAgent(
    name:  'registration',
    route: '/register',
    questions: [
        ['question_text' => 'What is your full name?',     'field' => 'full_name'],
        ['question_text' => 'What is your email address?', 'field' => 'email'],
        ['question_text' => 'What is your phone number?',  'field' => 'phone'],
    ],
);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

// Post-prompt for structured output
$agent->setPostPrompt(<<<'POST'
Return a JSON object with all collected information:
{
    "full_name": "NAME",
    "email": "EMAIL",
    "phone": "PHONE",
    "completed": true/false
}
POST);

$agent->onSummary(function ($summary, $raw) {
    if ($summary) {
        echo "Registration completed:\n";
        if (is_array($summary)) {
            echo json_encode($summary) . "\n";
        } else {
            echo "{$summary}\n";
        }
    }
});

echo "Starting InfoGatherer Agent\n";
echo "Available at: http://localhost:3000/register\n";
echo "This agent will collect: name, email, phone\n\n";

$agent->run();
