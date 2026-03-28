<?php
/**
 * Skills System Demo
 *
 * Demonstrates the modular skills system. Skills are automatically
 * discovered and can be added with simple one-liner calls.
 *
 * The datetime and math skills work without any additional setup.
 * The web_search skill requires GOOGLE_SEARCH_API_KEY and
 * GOOGLE_SEARCH_ENGINE_ID environment variables.
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(
    name:  'Multi-Skill Assistant',
    route: '/assistant',
);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');

$agent->promptAddSection(
    'Role',
    'You are a helpful assistant with access to various skills including '
    . 'date/time information, mathematical calculations, and web search.',
);

$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

echo "Creating agent with multiple skills...\n";

// Add skills using the skills system
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

try {
    $apiKey   = $_ENV['GOOGLE_SEARCH_API_KEY'] ?? null;
    $engineId = $_ENV['GOOGLE_SEARCH_ENGINE_ID'] ?? null;

    if (!$apiKey || !$engineId) {
        throw new \RuntimeException('Missing GOOGLE_SEARCH_API_KEY or GOOGLE_SEARCH_ENGINE_ID');
    }

    $agent->addSkill('web_search', [
        'api_key'          => $apiKey,
        'search_engine_id' => $engineId,
        'num_results'      => 1,
        'delay'            => 0,
    ]);
    echo "Added web_search skill\n";
} catch (\Exception $e) {
    echo "Web search not available: " . $e->getMessage() . "\n";
}

// List loaded skills
$loaded = $agent->listSkills();
if (!empty($loaded)) {
    echo "\nLoaded skills: " . implode(', ', $loaded) . "\n";
}

echo "\nStarting Skills Demo Agent\n";
echo "Available at: http://localhost:3000/assistant\n";

$agent->run();
