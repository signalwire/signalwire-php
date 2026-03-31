<?php
/**
 * DataSphere Multiple Instance Demo
 *
 * Demonstrates loading the DataSphere skill multiple times with different
 * configurations and tool names for different knowledge bases.
 *
 * Required: SignalWire DataSphere setup with documents and valid
 * space_name, project_id, token, and document_id values.
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(name: 'Multi-DataSphere Assistant', route: '/datasphere-demo');

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

echo "Creating agent with multiple DataSphere skill instances...\n";

try { $agent->addSkill('datetime'); echo "Added datetime skill\n"; }
catch (\Exception $e) { echo "Failed: " . $e->getMessage() . "\n"; }

try { $agent->addSkill('math'); echo "Added math skill\n"; }
catch (\Exception $e) { echo "Failed: " . $e->getMessage() . "\n"; }

// Replace with your actual DataSphere details
$config = [
    'space_name' => 'your-space',
    'project_id' => 'your-project-id',
    'token'      => 'your-token',
];

// Instance 1: Drinks knowledge base
try {
    $agent->addSkill('datasphere', array_merge($config, [
        'document_id'        => 'drinks-document-id',
        'tool_name'          => 'search_drinks',
        'count'              => 3,
        'no_results_message' => "I couldn't find any drinks matching '{query}'. Try another beverage name.",
        'swaig_fields'       => [
            'fillers' => [
                'en-US' => ['Searching the drinks database...', 'Looking up beverage information...'],
            ],
        ],
    ]));
    echo "Added DataSphere drinks instance (tool: search_drinks)\n";
} catch (\Exception $e) {
    echo "Failed: " . $e->getMessage() . "\n";
}

// Instance 2: Food knowledge base
try {
    $agent->addSkill('datasphere', array_merge($config, [
        'document_id'        => 'food-document-id',
        'tool_name'          => 'search_food',
        'count'              => 5,
        'no_results_message' => "I couldn't find any food items matching '{query}'.",
    ]));
    echo "Added DataSphere food instance (tool: search_food)\n";
} catch (\Exception $e) {
    echo "Failed: " . $e->getMessage() . "\n";
}

$loaded = $agent->listSkills();
echo "\nLoaded skills: " . implode(', ', $loaded) . "\n";

echo "\nMulti-DataSphere Assistant starting\n";
echo "Available at: http://localhost:3000/datasphere-demo\n";

$agent->run();
