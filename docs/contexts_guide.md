# Contexts and Steps Guide (PHP)

## Overview

The contexts system enables multi-step, multi-persona conversations. Each context defines a system prompt and a sequence of steps. The AI navigates between steps within a context and can switch between contexts when allowed.

## Basic Usage

```php
<?php
require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(name: 'sales-flow', route: '/sales');

// Base prompt (always present)
$agent->promptAddSection('Instructions', 'Follow the structured sales workflow.');

// Define contexts
$ctx = $agent->defineContexts();

$ctx->addContext('sales', [
    'system_prompt' => 'You are Franklin, a friendly computer sales consultant.',
    'consolidate'   => true,
    'steps' => [
        [
            'name'        => 'greeting',
            'prompt'      => 'Greet the customer and ask what they need.',
            'criteria'    => 'Customer has stated their general needs.',
            'valid_steps' => ['needs_assessment'],
        ],
        [
            'name'           => 'needs_assessment',
            'prompt'         => 'Ask about budget, use case, and requirements.',
            'criteria'       => 'Budget and use case are known.',
            'valid_steps'    => ['recommendation'],
            'valid_contexts' => ['support'],
        ],
        [
            'name'           => 'recommendation',
            'prompt'         => 'Recommend a computer based on requirements.',
            'criteria'       => 'Customer has received a recommendation.',
            'valid_contexts' => ['support'],
        ],
    ],
]);

$ctx->addContext('support', [
    'system_prompt' => 'You are Rachael, a technical support specialist.',
    'full_reset'    => true,
    'steps' => [
        [
            'name'           => 'diagnose',
            'prompt'         => 'Help with technical questions or issues.',
            'criteria'       => 'Issue identified or question answered.',
            'valid_contexts' => ['sales'],
        ],
    ],
]);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);
$agent->run();
```

## Context Entry Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `system_prompt` | string | Override the system prompt when entering this context |
| `consolidate` | bool | Summarize conversation history when entering (reduces token usage) |
| `full_reset` | bool | Clear conversation history entirely when entering |
| `steps` | array | Ordered list of step definitions |

### consolidate vs full_reset

- `consolidate: true` -- The AI summarizes the conversation so far into a compact form. The new context starts with context, but the full verbatim history is discarded.
- `full_reset: true` -- The conversation history is wiped entirely. The new context starts fresh with no memory of the previous context.
- Both false (default) -- Full conversation history carries over.

## Step Definition

Each step is an associative array:

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `name` | string | Yes | Unique step identifier |
| `prompt` | string | Yes | Instructions for the AI in this step |
| `criteria` | string | No | Completion criteria for advancing |
| `valid_steps` | array | No | Steps the AI can move to next |
| `valid_contexts` | array | No | Contexts the AI can switch to |

## Navigation

The AI automatically navigates based on:

1. **Within a context**: When step criteria are met, the AI moves to the next step in `valid_steps`
2. **Between contexts**: When `valid_contexts` is specified, the AI can switch contexts

## Programmatic Context Switching

From a SWAIG function handler, use `FunctionResult::switchContext()`:

```php
use SignalWire\SWAIG\FunctionResult;

$agent->defineTool(
    name:        'escalate',
    description: 'Escalate to support',
    parameters:  ['type' => 'object', 'properties' => []],
    handler: function (array $args, array $raw): FunctionResult {
        $result = new FunctionResult('Connecting you with a specialist.');
        $result->switchContext(
            systemPrompt: 'You are a senior support specialist.',
            consolidate:  true,
        );
        return $result;
    },
);
```

## Multi-Persona Example

Contexts enable different AI personalities:

```php
$ctx->addContext('greeter', [
    'system_prompt' => 'You are a cheerful receptionist named Sam.',
    'steps' => [
        [
            'name'           => 'welcome',
            'prompt'         => 'Welcome the caller and ask how you can help.',
            'criteria'       => 'Caller has stated their need.',
            'valid_contexts' => ['sales', 'support'],
        ],
    ],
]);

$ctx->addContext('sales', [
    'system_prompt' => 'You are Alex, a knowledgeable sales advisor.',
    'consolidate'   => true,
    'steps' => [
        [
            'name'   => 'qualify',
            'prompt' => 'Understand the customer needs and recommend solutions.',
        ],
    ],
]);
```

## Best Practices

1. Always define a base prompt on the agent -- contexts augment it, not replace it
2. Use `consolidate: true` for natural transitions where context matters
3. Use `full_reset: true` for clean handoffs to a different persona
4. Keep step criteria specific and measurable
5. Limit valid_steps and valid_contexts to prevent the AI from jumping randomly
