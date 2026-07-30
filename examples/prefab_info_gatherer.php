<?php

declare(strict_types=1);
/**
 * InfoGatherer Prefab Example
 *
 * Demonstrates using the InfoGatherer prefab agent to collect structured
 * information from callers via a guided question flow.
 */

require 'vendor/autoload.php';

use SignalWire\Prefabs\InfoGathererAgent;

/**
 * The question set. The prefab's contract is `key_name` — that is the key it
 * reads when recording each answer into global_data.
 *
 * @return list<array{key_name: string, question_text: string}>
 */
function buildInfoGathererQuestions(): array
{
    return [
        ['key_name' => 'full_name', 'question_text' => 'What is your full name?'],
        ['key_name' => 'email',     'question_text' => 'What is your email address?'],
        ['key_name' => 'phone',     'question_text' => 'What is your phone number?'],
    ];
}

$agent = new InfoGathererAgent(
    name:  'registration',
    route: '/register',
    questions: buildInfoGathererQuestions(),
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

$agent->setSummaryCallback(function ($summary, $raw) {
    if ($summary) {
        echo "Registration completed:\n";
        if (is_array($summary)) {
            echo json_encode($summary) . "\n";
        } else {
            echo "{$summary}\n";
        }
    }
});

// Guard the blocking run() so this file can be LOADED in-process (the question
// set above is unit-tested) without starting the HTTP server.
$isCliEntrypoint = PHP_SAPI === 'cli'
    && is_array($_SERVER['argv'] ?? null)
    && is_string($_SERVER['argv'][0] ?? null)
    && \realpath($_SERVER['argv'][0]) === __FILE__;

if ($isCliEntrypoint) {
    echo "Starting InfoGatherer Agent\n";
    echo "Available at: http://localhost:3000/register\n";
    echo "This agent will collect: name, email, phone\n\n";

    $agent->run();
}
