# Agent Development Guide (PHP)

## Overview

This guide walks you through building AI voice agents with the SignalWire PHP SDK. Agents are HTTP microservices that serve SWML documents and handle SWAIG function calls.

## Creating Your First Agent

```php
<?php
require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

$agent = new AgentBase(
    name:  'my-agent',
    route: '/agent',
    host:  '0.0.0.0',
    port:  3000,
);

$agent->promptAddSection('Role', 'You are a helpful assistant.');
$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);
$agent->run();
```

## Prompt Configuration

### Sections

The Prompt Object Model (POM) organizes prompts into named sections:

```php
$agent->promptAddSection('Personality', 'You are friendly and professional.');
$agent->promptAddSection('Goal', 'Help users resolve technical issues.');
$agent->promptAddSection('Instructions', '', bullets: [
    'Be concise and direct.',
    'Ask clarifying questions when needed.',
    'Use tools when appropriate.',
]);
```

### LLM Parameters

```php
$agent->setPromptLlmParams(
    temperature:      0.3,
    topP:             0.9,
    bargeConfidence:  0.7,
    presencePenalty:  0.1,
    frequencyPenalty: 0.2,
);
```

### Post-Prompt

The post-prompt runs after the conversation ends to generate a summary:

```php
$agent->setPostPrompt(<<<'PROMPT'
Return a JSON summary of the conversation:
{
    "topic": "MAIN_TOPIC",
    "resolved": true/false
}
PROMPT);
```

## Defining Tools

Tools are SWAIG functions the AI can invoke during a call:

```php
$agent->defineTool(
    name:        'get_weather',
    description: 'Get weather for a location',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'location' => ['type' => 'string', 'description' => 'City name'],
        ],
    ],
    handler: function (array $args, array $rawData): FunctionResult {
        $location = $args['location'] ?? 'Unknown';
        $result = new FunctionResult("It's sunny and 72F in {$location}.");
        $result->addAction('set_global_data', ['weather_location' => $location]);
        return $result;
    },
);
```

## FunctionResult Actions

`FunctionResult` supports many call-control actions:

```php
// Transfer call
$result->connect('+15551234567', final: true);

// Send SMS
$result->sendSms(toNumber: '+15551234567', fromNumber: '+15559876543', body: 'Hello!');

// Hang up
$result->hangup();

// Hold caller
$result->hold(60);

// Update global data
$result->updateGlobalData(['status' => 'verified']);

// Record call
$result->recordCall(controlId: 'rec_001', stereo: true);
```

## Global Data and State

Global data provides context to the AI throughout the conversation:

```php
$agent->setGlobalData([
    'company_name' => 'Acme Corp',
    'department'   => 'support',
]);
```

Tools can update global data at runtime via `FunctionResult::updateGlobalData()`.

## Summary Callback

Process post-call summaries:

```php
$agent->onSummary(function ($summary, $rawData) {
    if ($summary) {
        echo "Summary: " . json_encode($summary) . "\n";
    }
});
```

## Dynamic Configuration

Customize agent behavior per-request using a callback:

```php
$agent->setDynamicConfigCallback(function ($queryParams, $bodyParams, $headers, $agentClone) {
    $tier = strtolower($queryParams['tier'] ?? 'standard');

    $agentClone->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
    $agentClone->setParams(['ai_model' => 'gpt-4.1-nano']);

    $agentClone->setGlobalData([
        'customer_tier' => $tier,
        'session_type'  => 'dynamic',
    ]);

    $agentClone->promptAddSection('Role', "You are a {$tier}-tier support agent.");
});
```

## Languages and Speech

```php
$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->addLanguage(name: 'Spanish', code: 'es', voice: 'inworld.Sarah');
$agent->addHints('SignalWire', 'SWML', 'SWAIG');
$agent->addPronunciation('API', 'A P I', ignoreCase: false);
```

## Native Functions

Native functions are built-in SWML functions available without a handler:

```php
$agent->setNativeFunctions(['check_time', 'wait_seconds']);
```

## Call Flow Verbs

Add verbs that execute before or after the AI session:

```php
// Play hold music before AI answers
$agent->addPreAnswerVerb('play', [
    'url'    => 'https://cdn.signalwire.com/default-music/welcome.mp3',
    'volume' => -5,
]);

// Play goodbye after AI disconnects
$agent->addPostAiVerb('play', [
    'url' => 'say:Thank you for calling. Goodbye.',
]);
```

## Debug Events

```php
$agent->enableDebugEvents(true);
$agent->onDebugEvent(function ($event) {
    echo "DEBUG: " . json_encode($event) . "\n";
});
```

## Running the Agent

```php
// Print connection info
$user = $agent->basicAuthUser();
$pass = $agent->basicAuthPassword();
echo "Agent: http://localhost:3000/agent\n";
echo "Auth: {$user}:{$pass}\n";

$agent->run();
```

## Multi-Agent Server

Run multiple agents on one server:

```php
use SignalWire\Server\AgentServer;

$server = new AgentServer(host: '0.0.0.0', port: 3000);
$server->register($salesAgent);
$server->register($supportAgent);
$server->run();
```
