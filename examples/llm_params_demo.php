<?php
/**
 * LLM Parameters Demo
 *
 * Demonstrates LLM parameter customisation for different agent personalities:
 * - Precise assistant (low temperature, consistent responses)
 * - Creative assistant (high temperature, varied responses)
 * - Customer service agent (balanced parameters)
 *
 * Usage: php examples/llm_params_demo.php [precise|creative|support]
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

$agentType = $argv[1] ?? 'support';

$agent = match ($agentType) {
    'precise'  => createPreciseAssistant(),
    'creative' => createCreativeAssistant(),
    default    => createCustomerServiceAgent(),
};

echo "Starting " . ucfirst($agentType) . " Agent\n";
echo "Available at: http://localhost:3000\n";

$agent->run();

// --- Agent Factories ---

function createPreciseAssistant(): AgentBase
{
    $agent = new AgentBase(name: 'precise-assistant', route: '/precise');

    $agent->promptAddSection('Role', 'You are a precise technical assistant.');
    $agent->promptAddSection('Instructions', '', bullets: [
        'Provide accurate, factual information',
        'Be concise and direct',
        'Avoid speculation or guessing',
        'If uncertain, say so clearly',
    ]);

    $agent->setPromptLlmParams(
        temperature:      0.2,
        topP:             0.85,
        bargeConfidence:  0.8,
        presencePenalty:  0.0,
        frequencyPenalty: 0.1,
    );

    $agent->setPostPrompt('Provide a brief technical summary of the key points discussed.');
    $agent->setPostPromptLlmParams(temperature: 0.1);

    $agent->defineTool(
        name:        'get_system_info',
        description: 'Get technical system information',
        parameters:  ['type' => 'object', 'properties' => []],
        handler: function (array $args, array $raw): FunctionResult {
            return new FunctionResult(sprintf(
                'System Status: CPU %d%%, Memory %dGB, Disk %dGB free, Uptime %d days',
                rand(10, 90), rand(1, 16), rand(50, 500), rand(1, 30)
            ));
        },
    );

    $agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
    return $agent;
}

function createCreativeAssistant(): AgentBase
{
    $agent = new AgentBase(name: 'creative-assistant', route: '/creative');

    $agent->promptAddSection('Role', 'You are a creative writing assistant.');
    $agent->promptAddSection('Instructions', '', bullets: [
        'Be imaginative and creative',
        'Use varied vocabulary and expressions',
        'Encourage creative thinking',
        'Suggest unique perspectives',
    ]);

    $agent->setPromptLlmParams(
        temperature:      0.8,
        topP:             0.95,
        bargeConfidence:  0.5,
        presencePenalty:  0.2,
        frequencyPenalty: 0.3,
    );

    $agent->setPostPrompt('Create an artistic summary of our conversation.');
    $agent->setPostPromptLlmParams(temperature: 0.7);

    $prompts = [
        'adventure' => ['A map that only appears during thunderstorms', 'A compass that points to what you need most'],
        'mystery'   => ['A photograph where people keep disappearing', 'A library book that writes itself'],
        'default'   => ['An ordinary object with extraordinary powers', 'A door that leads somewhere different each time'],
    ];

    $agent->defineTool(
        name:        'generate_story_prompt',
        description: 'Generate a creative story prompt',
        parameters:  [
            'type' => 'object',
            'properties' => [
                'theme' => ['type' => 'string', 'description' => 'Story theme'],
            ],
        ],
        handler: function (array $args, array $raw) use ($prompts): FunctionResult {
            $theme = strtolower($args['theme'] ?? 'default');
            $list  = $prompts[$theme] ?? $prompts['default'];
            $chosen = $list[array_rand($list)];
            return new FunctionResult("Story prompt for {$theme}: {$chosen}");
        },
    );

    $agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
    return $agent;
}

function createCustomerServiceAgent(): AgentBase
{
    $agent = new AgentBase(name: 'customer-service', route: '/support');

    $agent->promptAddSection('Role', 'You are a professional customer service representative.');
    $agent->promptAddSection('Guidelines', '', bullets: [
        'Always be polite and empathetic',
        'Listen carefully to customer concerns',
        'Provide clear, helpful solutions',
        'Follow company policies',
    ]);

    $agent->setPromptLlmParams(
        temperature:      0.4,
        topP:             0.9,
        bargeConfidence:  0.7,
        presencePenalty:  0.1,
        frequencyPenalty: 0.1,
    );

    $agent->setPostPrompt("Summarise the customer's issue and resolution for the ticket system.");
    $agent->setPostPromptLlmParams(temperature: 0.3);

    $agent->defineTool(
        name:        'check_order_status',
        description: 'Check the status of a customer order',
        parameters:  [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'string', 'description' => 'Order ID'],
            ],
        ],
        handler: function (array $args, array $raw): FunctionResult {
            $orderId  = $args['order_id'] ?? 'unknown';
            $statuses = [
                'Processing - Expected to ship within 24 hours',
                'Shipped - Tracking number: TRK' . rand(100000, 999999),
                'Out for delivery - Expected today by 6 PM',
                'Delivered - Left at front door',
            ];
            return new FunctionResult("Order {$orderId} status: " . $statuses[array_rand($statuses)]);
        },
    );

    $agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
    return $agent;
}
