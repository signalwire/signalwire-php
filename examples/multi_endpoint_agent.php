<?php
/**
 * Multi-Endpoint Agent
 *
 * Demonstrates serving multiple endpoints from a single agent:
 * - /swml   - Voice AI SWML endpoint
 * - /health - Health check
 *
 * In a full PHP framework setup you could also add:
 * - /       - Web UI with HTML
 * - /api    - JSON API endpoint
 * - /static - Static file serving
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

$agent = new AgentBase(
    name:  'multi-endpoint',
    route: '/swml',
    host:  '0.0.0.0',
    port:  8080,
);

$agent->promptAddSection('Role', 'You are a helpful voice assistant.');
$agent->promptAddSection('Instructions', '', bullets: [
    'Greet callers warmly',
    'Be concise in your responses',
    'Use the available functions when appropriate',
]);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

$agent->defineTool(
    name:        'get_time',
    description: 'Get the current time',
    parameters:  ['type' => 'object', 'properties' => []],
    handler: function (array $args, array $raw): FunctionResult {
        return new FunctionResult('The current time is ' . date('g:i A'));
    },
);

echo "Multi-Endpoint Agent starting...\n";
echo "Endpoints:\n";
echo "  SWML:   http://localhost:8080/swml\n";
echo "  Health: http://localhost:8080/health\n";

$agent->run();
