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

// Define contexts (fluent API)
$ctx = $agent->defineContexts();

$sales = $ctx->addContext('sales')
    ->setSystemPrompt('You are Franklin, a friendly computer sales consultant.')
    ->setConsolidate(true);

$sales->addStep(
    'greeting',
    task: 'Greet the customer and ask what they need.',
    criteria: 'Customer has stated their general needs.',
    valid_steps: ['needs_assessment'],
);

$sales->addStep(
    'needs_assessment',
    task: 'Ask about budget, use case, and requirements.',
    criteria: 'Budget and use case are known.',
    valid_steps: ['recommendation'],
)->setValidContexts(['support']);

$sales->addStep(
    'recommendation',
    task: 'Recommend a computer based on requirements.',
    criteria: 'Customer has received a recommendation.',
)->setValidContexts(['support']);

$support = $ctx->addContext('support')
    ->setSystemPrompt('You are Rachael, a technical support specialist.')
    ->setFullReset(true);

$support->addStep(
    'diagnose',
    task: 'Help with technical questions or issues.',
    criteria: 'Issue identified or question answered.',
)->setValidContexts(['sales']);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);
$agent->run();
```

## Context Configuration Methods

A `Context` returned by `$ctx->addContext($name)` is configured with chainable
setters:

| Method | Description |
|--------|-------------|
| `setSystemPrompt(string)` | Override the system prompt when entering this context |
| `setConsolidate(bool)` | Summarize conversation history when entering (reduces token usage) |
| `setFullReset(bool)` | Clear conversation history entirely when entering |
| `addStep(string $name, ...)` | Add an ordered step; returns the `Step` for further chaining |

### consolidate vs full_reset

- `setConsolidate(true)` -- The AI summarizes the conversation so far into a compact form. The new context starts with that summary, but the full verbatim history is discarded.
- `setFullReset(true)` -- The conversation history is wiped entirely. The new context starts fresh with no memory of the previous context.
- Both false (default) -- Full conversation history carries over.

## Step Definition

`addStep()` accepts the step name plus optional named parameters, and the
returned `Step` exposes further setters:

| `addStep()` parameter / `Step` setter | Description |
|---------------------------------------|-------------|
| `$name` (required) | Unique step identifier |
| `task:` / `setText(string)` | Instructions for the AI in this step |
| `criteria:` / `setStepCriteria(string)` | Completion criteria for advancing |
| `valid_steps:` / `setValidSteps(array)` | Steps the AI can move to next |
| `setValidContexts(array)` | Contexts the AI can switch to |

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
$ctx->addContext('greeter')
    ->setSystemPrompt('You are a cheerful receptionist named Sam.')
    ->addStep(
        'welcome',
        task: 'Welcome the caller and ask how you can help.',
        criteria: 'Caller has stated their need.',
    )->setValidContexts(['sales', 'support']);

$ctx->addContext('sales')
    ->setSystemPrompt('You are Alex, a knowledgeable sales advisor.')
    ->setConsolidate(true)
    ->addStep(
        'qualify',
        task: 'Understand the customer needs and recommend solutions.',
    );
```

## Best Practices

1. Always define a base prompt on the agent -- contexts augment it, not replace it
2. Use `setConsolidate(true)` for natural transitions where context matters
3. Use `setFullReset(true)` for clean handoffs to a different persona
4. Keep step criteria specific and measurable
5. Limit valid_steps and valid_contexts to prevent the AI from jumping randomly
