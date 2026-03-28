# SWML Service Guide (PHP)

## Overview

`SignalWire\SWML\Service` is the foundation class for all agents. It provides SWML (SignalWire Markup Language) document creation, HTTP serving, and basic routing. `AgentBase` extends it with AI-specific features.

## What is SWML?

SWML is a JSON document format that defines how a call behaves. When SignalWire receives or initiates a call, it fetches a SWML document from your endpoint and executes the instructions.

### Basic SWML Document

```json
{
    "version": "1.0.0",
    "sections": {
        "main": [
            {"answer": {}},
            {"play": {"url": "say:Welcome to SignalWire!"}},
            {"hangup": {}}
        ]
    }
}
```

## Using SWML\Document

The `Document` class builds SWML documents programmatically:

```php
use SignalWire\SWML\Document;

$doc = new Document();
$doc->addApplication('main', 'answer', []);
$doc->addApplication('main', 'play', ['url' => 'say:Hello World!']);
$doc->addApplication('main', 'hangup', []);

echo json_encode($doc->render(), JSON_PRETTY_PRINT);
```

## Using SWML\Service

`Service` wraps `Document` with an HTTP server:

```php
use SignalWire\SWML\Service;

$service = new Service(name: 'greeter', route: '/greet', port: 3000);
// Configure SWML document...
$service->run();
```

When SignalWire calls your endpoint, `Service` returns the SWML document as JSON.

## SWML Schema Validation

The `Schema` class validates SWML documents:

```php
use SignalWire\SWML\Schema;

$schema = new Schema();
$errors = $schema->validate($swmlArray);
if (empty($errors)) {
    echo "Valid SWML document.\n";
} else {
    echo "Errors: " . implode(', ', $errors) . "\n";
}
```

## AgentBase as SWML Service

`AgentBase` extends `Service` to generate SWML with AI sections:

```php
use SignalWire\Agent\AgentBase;

$agent = new AgentBase(name: 'bot', route: '/bot');

$agent->promptAddSection('Role', 'You are a helpful assistant.');
$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

$agent->run();
```

The generated SWML includes:

1. Pre-answer verbs (optional)
2. `answer` verb
3. `ai` verb with prompt, tools, languages, and parameters
4. Post-AI verbs (optional)
5. `hangup` verb

## Call Flow Verbs

Add verbs before and after the AI session:

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

### Auto-Answer and Record

```php
$agent = new AgentBase(
    name:       'recording-agent',
    route:      '/record',
    autoAnswer: true,   // Automatically answer inbound calls
    recordCall: true,   // Record all calls
);
```

## Dynamic SWML Generation

Use the dynamic config callback for per-request SWML:

```php
$agent->setDynamicConfigCallback(function ($queryParams, $bodyParams, $headers, $agentClone) {
    $lang = $queryParams['lang'] ?? 'en-US';

    if ($lang === 'es') {
        $agentClone->addLanguage(name: 'Spanish', code: 'es', voice: 'inworld.Sarah');
        $agentClone->promptAddSection('Role', 'Eres un asistente amable.');
    } else {
        $agentClone->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
        $agentClone->promptAddSection('Role', 'You are a helpful assistant.');
    }

    $agentClone->setParams(['ai_model' => 'gpt-4.1-nano']);
});
```

Each request gets a fresh clone of the agent, so configuration is isolated.

## SWML Sections

A SWML document has named sections. The `main` section runs first:

```php
$doc = new Document();

// Main section
$doc->addApplication('main', 'answer', []);
$doc->addApplication('main', 'ai', [
    'prompt' => ['text' => 'You are helpful.'],
    'SWAIG' => ['functions' => [/* ... */]],
]);

// Other sections can be referenced
$doc->addApplication('transfer', 'connect', [
    'to' => '+15551234567',
]);
```

## SIP Request Routing

For SIP-based routing, use the dynamic config callback to inspect SIP headers:

```php
$agent->setDynamicConfigCallback(function ($queryParams, $bodyParams, $headers, $agentClone) {
    $sipTo = $headers['X-SIP-To'] ?? '';

    if (str_contains($sipTo, 'sales')) {
        $agentClone->promptAddSection('Role', 'You are a sales assistant.');
    } else {
        $agentClone->promptAddSection('Role', 'You are a general assistant.');
    }
});
```

## Best Practices

1. Use `AgentBase` for AI agents, `Service` only for raw SWML endpoints
2. Use dynamic config callbacks for request-specific behavior
3. Validate SWML with `Schema` during development
4. Keep SWML documents focused -- one AI section per document
5. Use pre/post verbs for call flow control outside the AI session
