<?php
/**
 * Web Search Multiple Instance Demo
 *
 * Demonstrates loading the same skill multiple times with different
 * configurations and tool names. Shows multiple search engines
 * and custom settings per instance.
 *
 * Required:
 *   GOOGLE_SEARCH_API_KEY     - Google Custom Search API key
 *   GOOGLE_SEARCH_ENGINE_ID   - Google Custom Search Engine ID
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(name: 'Multi-Search Assistant', route: '/search-demo');

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

echo "Creating agent with multiple skill instances...\n";

// Add basic skills
try {
    $agent->addSkill('datetime');
    echo "Added datetime skill\n";
} catch (\Exception $e) {
    echo "Failed to add datetime skill: " . $e->getMessage() . "\n";
}

try {
    $agent->addSkill('math');
    echo "Added math skill\n";
} catch (\Exception $e) {
    echo "Failed to add math skill: " . $e->getMessage() . "\n";
}

// Add web search instances
$googleApiKey         = $_ENV['GOOGLE_SEARCH_API_KEY'] ?? getenv('GOOGLE_SEARCH_API_KEY');
$googleSearchEngineId = $_ENV['GOOGLE_SEARCH_ENGINE_ID'] ?? getenv('GOOGLE_SEARCH_ENGINE_ID');

if ($googleApiKey && $googleSearchEngineId) {
    // Instance 1: General web search
    try {
        $agent->addSkill('web_search', [
            'api_key'          => $googleApiKey,
            'search_engine_id' => $googleSearchEngineId,
            'tool_name'        => 'search_general',
            'num_results'      => 2,
        ]);
        echo "Added general web search (tool: search_general)\n";
    } catch (\Exception $e) {
        echo "Failed: " . $e->getMessage() . "\n";
    }

    // Instance 2: Technical search with fewer results
    try {
        $agent->addSkill('web_search', [
            'api_key'            => $googleApiKey,
            'search_engine_id'   => $googleSearchEngineId,
            'tool_name'          => 'search_technical',
            'num_results'        => 1,
            'max_content_length' => 5000,
            'no_results_message' => "No technical docs found for '{query}'. Try different terms.",
        ]);
        echo "Added technical search (tool: search_technical)\n";
    } catch (\Exception $e) {
        echo "Failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "Skipping web search (GOOGLE_SEARCH_API_KEY / GOOGLE_SEARCH_ENGINE_ID not set)\n";
}

// Add Wikipedia search
try {
    $agent->addSkill('wikipedia_search', [
        'num_results' => 2,
        'swaig_fields' => [
            'fillers' => [
                'en-US' => ['Checking Wikipedia...', 'Looking that up in the encyclopaedia...'],
            ],
        ],
    ]);
    echo "Added Wikipedia search (tool: search_wiki)\n";
} catch (\Exception $e) {
    echo "Failed to add Wikipedia skill: " . $e->getMessage() . "\n";
}

$loaded = $agent->listSkills();
echo "\nLoaded skills: " . implode(', ', $loaded) . "\n";

echo "\nMulti-Search Assistant starting\n";
echo "Available at: http://localhost:3000/search-demo\n";

$agent->run();
