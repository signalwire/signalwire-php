<?php
/**
 * Contexts and Steps Demo Agent
 *
 * Demonstrates the contexts system including:
 * - Context entry parameters (system_prompt, consolidate, full_reset)
 * - Step-to-context navigation with context switching
 * - Multi-persona experience
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(
    name:  'Advanced Computer Sales Agent',
    route: '/advanced-contexts-demo',
);

// Base prompt (required even when using contexts)
$agent->promptAddSection(
    'Instructions',
    'Follow the structured sales workflow to guide customers through their computer purchase decision.',
    bullets: [
        "Complete each step's specific criteria before advancing",
        'Ask focused questions to gather the exact information needed',
        'Be helpful and consultative, not pushy',
    ],
);

// Define contexts using the ContextBuilder
$ctx = $agent->defineContexts();

// Sales context
$ctx->addContext('sales', [
    'system_prompt' => 'You are Franklin, a friendly computer sales consultant.',
    'consolidate'   => true,
    'steps' => [
        [
            'name'        => 'greeting',
            'prompt'      => 'Greet the customer and ask what kind of computer they need.',
            'criteria'    => 'Customer has stated their general needs.',
            'valid_steps' => ['needs_assessment'],
        ],
        [
            'name'           => 'needs_assessment',
            'prompt'         => 'Ask about budget, use case, and specific requirements.',
            'criteria'       => 'Budget and use case are known.',
            'valid_steps'    => ['recommendation'],
            'valid_contexts' => ['support'],
        ],
        [
            'name'           => 'recommendation',
            'prompt'         => 'Recommend a computer based on the gathered requirements.',
            'criteria'       => 'Customer has received a recommendation.',
            'valid_contexts' => ['support'],
        ],
    ],
]);

// Support context
$ctx->addContext('support', [
    'system_prompt' => 'You are Rachael, a technical support specialist.',
    'full_reset'    => true,
    'steps' => [
        [
            'name'           => 'diagnose',
            'prompt'         => 'Help the customer with any technical questions or issues.',
            'criteria'       => 'Issue has been identified or question answered.',
            'valid_contexts' => ['sales'],
        ],
    ],
]);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

echo "Starting Contexts Demo Agent\n";
echo "Available at: http://localhost:3000/advanced-contexts-demo\n";

$agent->run();
