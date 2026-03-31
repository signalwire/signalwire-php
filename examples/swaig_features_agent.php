<?php
/**
 * SWAIG Features Agent
 *
 * Demonstrates enhanced SWAIG features:
 * - Default webhook URL for all functions (via defaults object)
 * - Properly structured parameters (type:object with properties)
 * - Speech fillers for functions (feedback during processing)
 * - Declarative prompt sections via class constants
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

$agent = new AgentBase(
    name:              'swaig_features',
    route:             '/swaig_features',
    host:              '0.0.0.0',
    port:              3000,
    defaultWebhookUrl: 'https://api.example-external-service.com/swaig',
);

// Declarative prompt sections
$agent->promptAddSection('Personality', 'You are a friendly and helpful assistant.');
$agent->promptAddSection('Goal', 'Demonstrate advanced SWAIG features.');
$agent->promptAddSection('Instructions', '', bullets: [
    'Be concise and direct in your responses.',
    'Use the get_weather function when asked about weather.',
    'Use the get_time function when asked about the current time.',
]);

$agent->setPostPrompt(<<<'POST'
Return a JSON summary of the conversation:
{
    "topic": "MAIN_TOPIC",
    "functions_used": ["list", "of", "functions", "used"]
}
POST);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

// Tool with fillers
$agent->defineTool(
    name:        'get_time',
    description: 'Get the current time',
    parameters:  ['type' => 'object', 'properties' => []],
    fillers:     [
        'en-US' => ['Let me check the time for you', 'One moment while I check the current time'],
    ],
    handler: function (array $args, array $raw): FunctionResult {
        return new FunctionResult('The current time is ' . date('H:i:s'));
    },
);

// Tool with multi-language fillers
$agent->defineTool(
    name:        'get_weather',
    description: 'Get the current weather for a location (including Star Wars planets)',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'location' => ['type' => 'string', 'description' => 'City or location to get weather for'],
        ],
    ],
    fillers: [
        'en-US' => ['I am checking the weather for you', 'Let me look up the weather information'],
        'es'    => ['Estoy consultando el clima para ti', 'Permíteme verificar el clima'],
    ],
    handler: function (array $args, array $raw): FunctionResult {
        $location = $args['location'] ?? 'unknown';
        $data = [
            'tatooine' => 'Hot and dry, with occasional sandstorms. Twin suns at their peak.',
            'hoth'     => 'Extremely cold with blizzard conditions. High of -20C.',
            'endor'    => 'Mild forest weather. Partly cloudy with a high of 22C.',
        ];
        $result = $data[strtolower($location)] ?? "It's sunny and 72F";
        return new FunctionResult("The weather in {$location}: {$result}");
    },
);

// Tool with enum parameter
$agent->defineTool(
    name:        'get_forecast',
    description: 'Get a 3-day weather forecast for a location',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'location' => ['type' => 'string', 'description' => 'City or location'],
            'units'    => ['type' => 'string', 'description' => 'Temperature units', 'enum' => ['celsius', 'fahrenheit']],
        ],
    ],
    handler: function (array $args, array $raw): FunctionResult {
        $location = $args['location'] ?? 'unknown';
        $units    = $args['units'] ?? 'fahrenheit';
        $forecast = [
            ['day' => 'Today',     'temp' => 72, 'condition' => 'Sunny'],
            ['day' => 'Tomorrow',  'temp' => 68, 'condition' => 'Partly Cloudy'],
            ['day' => 'Day After', 'temp' => 75, 'condition' => 'Clear'],
        ];
        $symbol = 'F';
        if ($units === 'celsius') {
            $symbol = 'C';
            foreach ($forecast as &$day) {
                $day['temp'] = round(($day['temp'] - 32) * 5 / 9);
            }
        }
        $lines = array_map(fn($d) => "{$d['day']}: {$d['temp']}{$symbol}, {$d['condition']}", $forecast);
        return new FunctionResult("3-day forecast for {$location}:\n" . implode("\n", $lines));
    },
);

echo "Starting SWAIG Features Agent\n";
echo "Available at: http://localhost:3000/swaig_features\n";

$agent->run();
