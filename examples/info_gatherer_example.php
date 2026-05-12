<?php
/**
 * InfoGatherer Example (Static Mode)
 *
 * Uses the InfoGathererAgent with a fixed set of questions.
 * For dynamic configuration, see dynamic_info_gatherer_example.php.
 */

require 'vendor/autoload.php';

use SignalWire\Prefabs\InfoGathererAgent;

$agent = new InfoGathererAgent(
    name: 'contact-form',
    questions: [
        ['key_name' => 'name',   'question_text' => 'What is your full name?'],
        ['key_name' => 'phone',  'question_text' => 'What is your phone number?', 'confirm' => true],
        ['key_name' => 'age',    'question_text' => 'What is your age?'],
        ['key_name' => 'reason', 'question_text' => 'What are you contacting us about today?'],
    ],
    route: '/contact',
);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');

$agent->promptAddSection(
    'Introduction',
    "I'm here to help you fill out our contact form. "
    . 'This information helps us better serve you.',
);

$agent->setPostPrompt('Summarise the questions and answers in a concise manner.');

$user = $agent->basicAuthUser();
$pass = $agent->basicAuthPassword();

echo "Starting Information Gathering Agent\n";
echo "URL: http://localhost:3000/contact\n";
echo "Basic Auth: {$user}:{$pass}\n";

$agent->run();
