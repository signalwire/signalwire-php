<?php
/**
 * Kubernetes-Ready Agent
 *
 * Configured for production Kubernetes deployment with:
 * - Health and readiness endpoints
 * - Environment variable configuration
 * - Graceful shutdown handling
 *
 * Usage:
 *   php kubernetes_ready_agent.php
 *   PORT=8081 php kubernetes_ready_agent.php
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

$port = (int) ($_ENV['PORT'] ?? getenv('PORT') ?: 8080);

$agent = new AgentBase(
    name:  'k8s-agent',
    route: '/',
    host:  '0.0.0.0',
    port:  $port,
);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');

$agent->promptAddSection(
    'Role',
    'You are a production-ready AI agent running in Kubernetes. '
    . 'You can help users with general questions and demonstrate cloud-native deployment patterns.',
);

$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

$agent->defineTool(
    name:        'health_status',
    description: 'Get the health status of this agent',
    parameters:  ['type' => 'object', 'properties' => []],
    handler: function (array $args, array $raw) use ($agent, $port): FunctionResult {
        return new FunctionResult("Agent is healthy, running on port {$port} in Kubernetes.");
    },
);

echo "READY: Kubernetes-ready agent starting on port {$port}\n";
echo "HEALTH: Health check: http://localhost:{$port}/health\n";
echo "STATUS: Readiness check: http://localhost:{$port}/ready\n";

$agent->run();
