<?php
/**
 * Joke Agent
 *
 * Uses a raw data_map configuration to integrate with the
 * API Ninjas joke API.
 *
 * Run with: API_NINJAS_KEY=your_api_key php examples/joke_agent.php
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$apiKey = $_ENV['API_NINJAS_KEY'] ?? getenv('API_NINJAS_KEY');
if (!$apiKey) {
    echo "Error: API_NINJAS_KEY environment variable is required\n";
    echo "Get your free API key from https://api.api-ninjas.com/\n";
    exit(1);
}

$agent = new AgentBase(
    name:  'Joke Agent',
    route: '/joke-agent',
);

$agent->promptAddSection('Personality', 'You are a funny assistant who loves to tell jokes.');
$agent->promptAddSection('Goal', 'Make people laugh with great jokes.');
$agent->promptAddSection('Instructions', '', bullets: [
    'Use the get_joke function to tell jokes when asked',
    'You can tell either regular jokes or dad jokes',
    'Be enthusiastic about sharing humour',
]);

// Register the joke function with raw data_map configuration
$agent->registerSwaigFunction([
    'function'    => 'get_joke',
    'description' => 'Tell a joke',
    'data_map'    => [
        'webhooks' => [
            [
                'url'     => 'https://api.api-ninjas.com/v1/%{args.type}',
                'headers' => ['X-Api-Key' => $apiKey],
                'output'  => [
                    'response' => 'Tell the user: %{array[0].joke}',
                    'action'   => [
                        [
                            'SWML' => [
                                'sections' => [
                                    'main' => [['set' => ['dad_joke' => '%{array[0].joke}']]],
                                ],
                                'version' => '1.0.0',
                            ],
                        ],
                    ],
                ],
                'error_keys' => 'error',
                'method'     => 'GET',
            ],
        ],
        'output' => [
            'response' => 'Tell the user that the joke service is not working right now and just make up a joke on your own',
        ],
    ],
    'parameters' => [
        'type'       => 'object',
        'properties' => [
            'type' => [
                'description' => "must either be 'jokes' or 'dadjokes'",
                'type'        => 'string',
            ],
        ],
    ],
]);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

echo "Starting Joke Agent\n";
echo "Available at: http://localhost:3000/joke-agent\n";

$agent->run();
