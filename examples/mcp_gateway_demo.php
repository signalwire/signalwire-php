<?php
/**
 * MCP Gateway Demo
 *
 * Connects a SignalWire AI agent to MCP (Model Context Protocol) servers
 * through the mcp_gateway skill. The gateway bridges MCP tools so the
 * agent can use them as SWAIG functions.
 *
 * Prerequisites:
 *   Start a gateway server: mcp-gateway -c config.json
 *
 * Environment variables (or pass directly):
 *   MCP_GATEWAY_URL           - URL of the running MCP gateway service
 *   MCP_GATEWAY_AUTH_USER     - Basic auth username
 *   MCP_GATEWAY_AUTH_PASSWORD - Basic auth password
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(
    name:  'MCP Gateway Agent',
    route: '/mcp-gateway',
);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');

$agent->promptAddSection(
    'Role',
    'You are a helpful assistant with access to external tools provided '
    . 'through MCP servers. Use the available tools to help users accomplish their tasks.',
);

$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

// Connect to MCP gateway -- tools are discovered automatically
$agent->addSkill('mcp_gateway', [
    'gateway_url'   => $_ENV['MCP_GATEWAY_URL'] ?? getenv('MCP_GATEWAY_URL') ?: 'http://localhost:8080',
    'auth_user'     => $_ENV['MCP_GATEWAY_AUTH_USER'] ?? getenv('MCP_GATEWAY_AUTH_USER') ?: 'admin',
    'auth_password' => $_ENV['MCP_GATEWAY_AUTH_PASSWORD'] ?? getenv('MCP_GATEWAY_AUTH_PASSWORD') ?: 'changeme',
    'services'      => [['name' => 'todo']],
]);

echo "Starting MCP Gateway Agent\n";
echo "Available at: http://localhost:3000/mcp-gateway\n";

$agent->run();
