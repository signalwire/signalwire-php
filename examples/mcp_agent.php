<?php
/**
 * MCP Integration -- Client and Server
 *
 * Demonstrates both MCP features:
 * 1. MCP Server: Exposes tools at /mcp for external MCP clients
 *    (Claude Desktop, other agents).
 * 2. MCP Client: Connects to external MCP servers to pull in
 *    additional tools for voice calls.
 *
 * Usage:
 *   php mcp_agent.php
 *   Point a SignalWire phone number at http://your-server:3000/agent
 *   Connect Claude Desktop to http://your-server:3000/agent/mcp
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

$agent = new AgentBase(name: 'mcp-agent', route: '/agent');

// -- MCP Server --
// Adds a /mcp endpoint that speaks JSON-RPC 2.0 (MCP protocol).
$agent->enableMcpServer();

// -- MCP Client --
// Connect to an external MCP server. Tools are discovered at call start.
$agent->addMcpServer(
    'https://mcp.example.com/tools',
    headers: ['Authorization' => 'Bearer sk-your-mcp-api-key'],
);

// MCP Client with resources (read-only data fetched into global_data)
$agent->addMcpServer(
    'https://mcp.example.com/crm',
    headers:      ['Authorization' => 'Bearer sk-your-crm-key'],
    resources:    true,
    resourceVars: [
        'caller_id' => '${caller_id_number}',
        'tenant'    => 'acme-corp',
    ],
);

// -- Agent Configuration --
$agent->promptAddSection('Role', 'You are a helpful customer support agent. '
    . 'You have access to the customer\'s profile via global_data.');

$agent->promptAddSection('Customer Context',
    "Customer name: \${global_data.customer_name}\n"
    . "Account status: \${global_data.account_status}\n"
    . 'If customer data is not available, ask the caller for their name.',
);

$agent->setParams([
    'ai_model'         => 'gpt-4.1-nano',
    'attention_timeout' => 15000,
]);

// -- Local Tools (available via both SWAIG and MCP) --

$agent->defineTool(
    name:        'get_weather',
    description: 'Get the current weather for a location',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'location' => ['type' => 'string', 'description' => 'City name or zip code'],
        ],
    ],
    handler: function (array $args, array $raw): FunctionResult {
        $location = $args['location'] ?? 'unknown';
        return new FunctionResult("Currently 72F and sunny in {$location}.");
    },
);

$agent->defineTool(
    name:        'create_ticket',
    description: 'Create a support ticket for the customer',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'subject'     => ['type' => 'string', 'description' => 'Ticket subject'],
            'description' => ['type' => 'string', 'description' => 'Detailed description of the issue'],
        ],
    ],
    handler: function (array $args, array $raw): FunctionResult {
        $subject = $args['subject'] ?? 'No subject';
        return new FunctionResult("Ticket created: '{$subject}'. Reference number: TK-12345.");
    },
);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');

echo "Starting MCP Agent\n";
echo "SWML endpoint: http://localhost:3000/agent\n";
echo "MCP endpoint:  http://localhost:3000/agent/mcp\n";

$agent->run();
