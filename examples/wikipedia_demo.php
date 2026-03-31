<?php
/**
 * Wikipedia Search Skill Demo
 *
 * Demonstrates the Wikipedia search skill for factual information retrieval.
 * Includes custom no_results_message, multiple result configuration,
 * and SWAIG fillers for better user experience.
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(name: 'Wikipedia Assistant', route: '/wiki-demo');

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

echo "Creating Wikipedia search assistant...\n";

// Add basic datetime skill
try {
    $agent->addSkill('datetime');
    echo "Added datetime skill\n";
} catch (\Exception $e) {
    echo "Failed to add datetime skill: " . $e->getMessage() . "\n";
}

// Add Wikipedia search skill
try {
    $agent->addSkill('wikipedia_search', [
        'num_results'        => 2,
        'no_results_message' => "I couldn't find any Wikipedia articles about '{query}'. Try different keywords or ask about a related topic.",
        'swaig_fields'       => [
            'fillers' => [
                'en-US' => [
                    'Let me search Wikipedia for that information...',
                    "Checking Wikipedia's knowledge base...",
                    'Looking that up in the encyclopaedia...',
                    'Searching for factual information...',
                ],
            ],
        ],
    ]);
    echo "Added Wikipedia search (tool: search_wiki)\n";
} catch (\Exception $e) {
    echo "Failed to add Wikipedia skill: " . $e->getMessage() . "\n";
    exit(1);
}

$loaded = $agent->listSkills();
echo "\nLoaded skills: " . implode(', ', $loaded) . "\n";

echo "\nWikipedia Assistant starting\n";
echo "Available at: http://localhost:3000/wiki-demo\n";
echo "\nExample queries:\n";
echo "  'Tell me about Albert Einstein'\n";
echo "  'What is quantum physics?'\n";
echo "  'Look up Python programming language'\n";

$agent->run();
