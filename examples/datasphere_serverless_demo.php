<?php
/**
 * DataSphere Serverless Demo
 *
 * Demonstrates the DataSphere Serverless skill using DataMap.
 * Executes on SignalWire servers rather than the agent server,
 * so no webhook infrastructure is required.
 *
 * Required: SignalWire DataSphere setup with documents.
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(name: 'DataSphere Serverless Assistant', route: '/datasphere-serverless-demo');

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

echo "Creating agent with DataSphere Serverless skill (DataMap implementation)...\n";

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

// Serverless instance -- executes on SignalWire servers
try {
    $agent->addSkill('datasphere_serverless', array_merge($config, [
        'document_id'        => 'your-document-id',
        'tool_name'          => 'search_knowledge',
        'count'              => 3,
        'no_results_message' => "No results found for '{query}'. Try different keywords.",
        'swaig_fields'       => [
            'fillers' => [
                'en-US' => ['Searching the knowledge base...', 'Let me look that up...'],
            ],
        ],
    ]));
    echo "Added DataSphere Serverless instance (tool: search_knowledge)\n";
} catch (\Exception $e) {
    echo "Failed: " . $e->getMessage() . "\n";
}

$loaded = $agent->listSkills();
echo "\nLoaded skills: " . implode(', ', $loaded) . "\n";

echo "\nDataSphere Serverless Assistant starting\n";
echo "Available at: http://localhost:3000/datasphere-serverless-demo\n";
echo "\nKey advantage: No webhook infrastructure needed -- runs on SignalWire servers.\n";

$agent->run();
