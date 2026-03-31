<?php
/**
 * Declarative Agent Example
 *
 * Demonstrates defining the entire prompt structure as class-level
 * constants (PROMPT_SECTIONS) instead of building the prompt
 * imperatively in the constructor.
 *
 * Benefits:
 * - Separates prompt definition from implementation logic
 * - More visible and maintainable prompt structure
 * - Easier reuse of prompt templates
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

// --- Declarative prompt sections ---

$promptSections = [
    'Personality'  => 'You are a friendly and helpful AI assistant who responds in a casual, conversational tone.',
    'Goal'         => 'Help users with their questions about time and weather.',
    'Instructions' => [
        'Be concise and direct in your responses.',
        "If you don't know something, say so clearly.",
        'Use the get_time function when asked about the current time.',
        'Use the get_weather function when asked about the weather.',
    ],
];

// --- Agent setup ---

$agent = new AgentBase(
    name:  'declarative',
    route: '/declarative',
    host:  '0.0.0.0',
    port:  3000,
);

// Apply declarative sections
foreach ($promptSections as $title => $content) {
    if (is_array($content)) {
        $agent->promptAddSection($title, '', bullets: $content);
    } else {
        $agent->promptAddSection($title, $content);
    }
}

// Post-prompt for summary generation
$agent->setPostPrompt(<<<'PROMPT'
Return a JSON summary of the conversation:
{
    "topic": "MAIN_TOPIC",
    "satisfied": true/false,
    "follow_up_needed": true/false
}
PROMPT);

// --- Tools ---

$agent->defineTool(
    name:        'get_time',
    description: 'Get the current time',
    parameters:  ['type' => 'object', 'properties' => []],
    handler: function (array $args, array $rawData): FunctionResult {
        return new FunctionResult('The current time is ' . date('H:i:s'));
    },
);

$agent->defineTool(
    name:        'get_weather',
    description: 'Get the current weather for a location',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'location' => ['type' => 'string', 'description' => 'City or location'],
        ],
    ],
    handler: function (array $args, array $rawData): FunctionResult {
        $location = $args['location'] ?? 'Unknown location';
        return new FunctionResult("It's sunny and 72F in {$location}.");
    },
);

$agent->onSummary(function ($summary, $raw) {
    if ($summary) {
        echo "Conversation summary: " . json_encode($summary) . "\n";
    }
});

echo "Starting Declarative Agent\n";
echo "Available at: http://localhost:3000/declarative\n";

$agent->run();
