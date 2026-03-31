<?php
/**
 * Web Search Agent
 *
 * An AI agent that can search the web using the web_search skill.
 *
 * Required environment variables:
 *   GOOGLE_SEARCH_API_KEY     - Google Custom Search API key
 *   GOOGLE_SEARCH_ENGINE_ID   - Google Custom Search Engine ID
 *
 * Get credentials at:
 *   https://developers.google.com/custom-search/v1/introduction
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(name: 'Web Search Assistant', route: '/search');

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');

$agent->promptAddSection('Personality',
    'You are Franklin, a friendly and knowledgeable search bot. '
    . "You're enthusiastic about helping people find information on the internet.",
);

$agent->promptAddSection('Goal',
    'Help users find accurate, up-to-date information from the web.',
);

$agent->promptAddSection('Instructions', '', bullets: [
    'Always introduce yourself as Franklin when users first interact with you',
    'Use your web search capabilities to find current information',
    'Present search results in a clear, organised format',
    'Be enthusiastic about searching and learning new things',
]);

$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

$googleApiKey         = $_ENV['GOOGLE_SEARCH_API_KEY'] ?? getenv('GOOGLE_SEARCH_API_KEY');
$googleSearchEngineId = $_ENV['GOOGLE_SEARCH_ENGINE_ID'] ?? getenv('GOOGLE_SEARCH_ENGINE_ID');

if (!$googleApiKey || !$googleSearchEngineId) {
    echo "Error: Missing required environment variables:\n";
    echo "  GOOGLE_SEARCH_API_KEY\n";
    echo "  GOOGLE_SEARCH_ENGINE_ID\n";
    echo "\nGet these by setting up Google Custom Search:\n";
    echo "  https://developers.google.com/custom-search/v1/introduction\n";
    exit(1);
}

try {
    $agent->addSkill('web_search', [
        'api_key'            => $googleApiKey,
        'search_engine_id'   => $googleSearchEngineId,
        'num_results'        => 1,
        'delay'              => 0,
        'max_content_length' => 3000,
        'no_results_message' => "I apologize, but I wasn't able to find any information about '{query}'. Could you try rephrasing?",
        'swaig_fields'       => [
            'fillers' => [
                'en-US' => [
                    'I am searching the web for that information...',
                    'Let me google that for you...',
                    'Searching the internet now...',
                ],
            ],
        ],
    ]);
    echo "Web search skill loaded successfully\n";
} catch (\Exception $e) {
    echo "Failed to load web search skill: " . $e->getMessage() . "\n";
    exit(1);
}

$loaded = $agent->listSkills();
echo "Loaded skills: " . implode(', ', $loaded) . "\n";

echo "Starting Web Search Agent\n";
echo "Available at: http://localhost:3000/search\n";

$agent->run();
