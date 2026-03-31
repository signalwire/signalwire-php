<?php
/**
 * AWS Lambda Deployment Example
 *
 * Shows how to deploy a SignalWire AI Agent to AWS Lambda using Bref.
 *
 * Requirements:
 *   composer require bref/bref
 *
 * Usage:
 *   1. Deploy this file to AWS Lambda via Bref
 *   2. Configure API Gateway to route all requests to this function
 *   3. Set environment variables:
 *      - SWML_BASIC_AUTH_USER (optional)
 *      - SWML_BASIC_AUTH_PASSWORD (optional)
 *
 * For local testing:
 *   php examples/lambda_agent.php
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

$agent = new AgentBase(
    name:  'lambda-agent',
    route: '/',
);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');

$agent->promptAddSection('Role', 'You are a helpful AI assistant running in AWS Lambda.');
$agent->promptAddSection('Instructions', '', bullets: [
    'Greet users warmly and offer help',
    'Use the greet_user function when asked to greet someone',
    'Use the get_time function when asked about the current time',
]);

$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

$agent->defineTool(
    name:        'greet_user',
    description: 'Greet a user by name',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'description' => 'Name of the user to greet'],
        ],
    ],
    handler: function (array $args, array $raw): FunctionResult {
        $name = $args['name'] ?? 'friend';
        return new FunctionResult("Hello {$name}! I'm running in AWS Lambda!");
    },
);

$agent->defineTool(
    name:        'get_time',
    description: 'Get the current time',
    parameters:  ['type' => 'object', 'properties' => []],
    handler: function (array $args, array $raw): FunctionResult {
        return new FunctionResult('Current time: ' . date('c'));
    },
);

// For Lambda, export the handler; for local testing, run the server
if (php_sapi_name() === 'cli' && !getenv('LAMBDA_TASK_ROOT')) {
    echo "Starting Lambda Agent locally\n";
    echo "Available at: http://localhost:3000/\n";
    $agent->run();
} else {
    // In Lambda, return the handler
    return $agent->handleServerlessRequest($_SERVER, file_get_contents('php://input'));
}
