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

// Define contexts using the ContextBuilder's FLUENT API. addContext() takes
// ONLY the context name and returns a Context you configure with chainable
// setters; addStep() takes the step name plus named params and returns a Step.
// (An array-config shape is NOT accepted — addContext() rejects extra args
// loudly so a stale config-array example fails fast instead of building empty
// contexts that fatal at serve time.)
$ctx = $agent->defineContexts();

// ── Sales context ────────────────────────────────────────────────────────
$sales = $ctx->addContext('sales')
    ->setSystemPrompt('You are Franklin, a friendly computer sales consultant.')
    ->setConsolidate(true);

$sales->addStep(
    'greeting',
    task: 'Greet the customer and ask what kind of computer they need.',
    criteria: 'Customer has stated their general needs.',
    valid_steps: ['needs_assessment'],
);

$sales->addStep(
    'needs_assessment',
    task: 'Ask about budget, use case, and specific requirements.',
    criteria: 'Budget and use case are known.',
    valid_steps: ['recommendation'],
)->setValidContexts(['support']);

$sales->addStep(
    'recommendation',
    task: 'Recommend a computer based on the gathered requirements.',
    criteria: 'Customer has received a recommendation.',
)->setValidContexts(['support']);

// ── Support context ──────────────────────────────────────────────────────
$support = $ctx->addContext('support')
    ->setSystemPrompt('You are Rachael, a technical support specialist.')
    ->setFullReset(true);

$support->addStep(
    'diagnose',
    task: 'Help the customer with any technical questions or issues.',
    criteria: 'Issue has been identified or question answered.',
)->setValidContexts(['sales']);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

echo "Starting Contexts Demo Agent\n";
echo "Available at: http://localhost:3000/advanced-contexts-demo\n";

$agent->run();
