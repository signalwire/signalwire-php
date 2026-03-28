<?php
/**
 * Simple example of using the SignalWire AI Agent SDK (PHP)
 *
 * This example demonstrates creating an agent using explicit methods
 * to manipulate the POM (Prompt Object Model) structure directly.
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

// Create an agent
$agent = new AgentBase(
    name:  'simple',
    route: '/simple',
    host:  '0.0.0.0',
    port:  3000,
);

// --- Prompt Configuration ---

$agent->promptAddSection('Personality', 'You are a friendly and helpful assistant.');
$agent->promptAddSection('Goal', 'Help users with basic tasks and answer questions.');
$agent->promptAddSection('Instructions', '', bullets: [
    'Be concise and direct in your responses.',
    "If you don't know something, say so clearly.",
    'Use the get_time function when asked about the current time.',
    'Use the get_weather function when asked about the weather.',
]);

// LLM parameters
$agent->setPromptLlmParams(
    temperature:      0.3,
    topP:             0.9,
    bargeConfidence:  0.7,
    presencePenalty:  0.1,
    frequencyPenalty: 0.2,
);

// Post-prompt for summary generation
$agent->setPostPrompt(<<<'PROMPT'
Return a JSON summary of the conversation:
{
    "topic": "MAIN_TOPIC",
    "satisfied": true/false,
    "follow_up_needed": true/false
}
PROMPT);

// --- Pronunciation and Hints ---

$agent->addHints('SignalWire', 'SWML', 'SWAIG');
$agent->addPronunciation('API', 'A P I', ignoreCase: false);
$agent->addPronunciation('SIP', 'sip', ignoreCase: true);

// --- Languages ---

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->addLanguage(name: 'Spanish', code: 'es', voice: 'inworld.Sarah');
$agent->addLanguage(name: 'French', code: 'fr-FR', voice: 'inworld.Hanna');

// --- AI Behavior ---

$agent->setParams([
    'ai_model'              => 'gpt-4.1-nano',
    'wait_for_user'         => false,
    'end_of_speech_timeout' => 1000,
    'ai_volume'             => 5,
    'languages_enabled'     => true,
    'local_tz'              => 'America/Los_Angeles',
]);

$agent->setGlobalData([
    'company_name'       => 'SignalWire',
    'product'            => 'AI Agent SDK',
    'supported_features' => ['Voice AI', 'Telephone integration', 'SWAIG functions'],
]);

// --- Native Functions ---

$agent->setNativeFunctions(['check_time', 'wait_seconds']);

// --- Tool Definitions ---

$agent->defineTool(
    name:        'get_time',
    description: 'Get the current time',
    parameters:  ['type' => 'object', 'properties' => []],
    handler: function (array $args, array $rawData): FunctionResult {
        $time = date('H:i:s');
        return new FunctionResult("The current time is {$time}");
    },
);

$agent->defineTool(
    name:        'get_weather',
    description: 'Get the current weather for a location',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'location' => ['type' => 'string', 'description' => 'The city or location to get weather for'],
        ],
    ],
    handler: function (array $args, array $rawData): FunctionResult {
        $location = $args['location'] ?? 'Unknown location';
        $result = new FunctionResult("It's sunny and 72F in {$location}.");
        $result->addAction('set_global_data', ['weather_location' => $location]);
        return $result;
    },
);

// --- Summary Callback ---

$agent->onSummary(function ($summary, $rawData) {
    if ($summary) {
        if (is_array($summary)) {
            echo "SUMMARY: " . json_encode($summary) . "\n";
        } else {
            echo "SUMMARY: {$summary}\n";
        }
    }
});

// --- Start the Agent ---

$user = $agent->basicAuthUser();
$pass = $agent->basicAuthPassword();

echo "Starting the agent. Press Ctrl+C to stop.\n";
echo "Agent 'simple' is available at:\n";
echo "URL: http://localhost:3000/simple\n";
echo "Basic Auth: {$user}:{$pass}\n";

$agent->run();
