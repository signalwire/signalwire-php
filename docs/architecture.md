# SignalWire AI Agents SDK Architecture (PHP)

## Overview

The SignalWire AI Agents SDK for PHP provides a framework for building, deploying, and managing AI agents as microservices. Agents are self-contained web applications that expose HTTP endpoints to interact with the SignalWire platform. The SDK handles HTTP routing, prompt management, SWAIG tool execution, and SWML document generation.

## Core Components

### Class Hierarchy

```
SignalWire\SWML\Service          -- SWML document creation and HTTP service
  └── SignalWire\Agent\AgentBase -- AI agent functionality (prompt, tools, skills)
        ├── Custom Agent Classes -- Your implementations
        └── Prefab Agents        -- InfoGathererAgent, SurveyAgent, etc.
```

### Key Components

1. **SWML Document Management** (`SignalWire\SWML\Document`, `SignalWire\SWML\Schema`)
   - Schema validation for SWML documents
   - Dynamic SWML verb creation and validation
   - Document rendering and serving via HTTP

2. **Prompt Object Model (POM)**
   - Structured format for defining AI prompts
   - Section-based organization (Personality, Goal, Instructions, etc.)
   - Programmatic prompt construction via `promptAddSection()`

3. **SWAIG Function Framework** (`SignalWire\SWAIG\FunctionResult`)
   - SWAIG (SignalWire AI Gateway) is the platform's AI tool-calling system with native media stack access
   - Tool definition and registration via `defineTool()`
   - Parameter validation using JSON schema
   - Handler callbacks for function execution
   - Action methods: `connect()`, `sendSms()`, `hangup()`, `hold()`, etc.

4. **HTTP Routing** (`SignalWire\Server\AgentServer`)
   - Built-in PHP HTTP server
   - Endpoint routing for SWML, SWAIG, and debug endpoints
   - Dynamic configuration callbacks for per-request customization
   - Basic authentication with auto-generated credentials

5. **State Management** (`SignalWire\Security\SessionManager`)
   - Session-based state tracking
   - Global data for AI context
   - Summary callbacks for post-call processing

6. **Prefab Agents** (`SignalWire\Prefabs\*`)
   - `InfoGathererAgent` -- guided question flows
   - `SurveyAgent` -- structured surveys with multiple question types
   - `ConciergeAgent`, `ReceptionistAgent`, `FAQBotAgent`

7. **Skills System** (`SignalWire\Skills\SkillManager`, `SignalWire\Skills\SkillRegistry`)
   - Modular skill architecture for extending agent capabilities
   - Automatic discovery from `Skills\Builtin\` namespace
   - Built-in skills: `datetime`, `math`, `web_search`, `datasphere`, and more

## DataMap Tools

The DataMap system (`SignalWire\DataMap\DataMap`) provides declarative SWAIG tools that integrate with REST APIs without webhook infrastructure. DataMap tools execute on SignalWire's servers.

### Pipeline

```
Function Call ──> Expression Processing ──> Webhook Execution ──> Response Generation
(Arguments)       (Pattern Matching)        (HTTP Request)        (Template Rendering)
```

### Builder Pattern

```php
use SignalWire\DataMap\DataMap;
use SignalWire\SWAIG\FunctionResult;

$weather = (new DataMap('get_weather'))
    ->description('Get weather for a location')
    ->parameter('location', 'string', 'City name', required: true)
    ->webhook('GET', 'https://api.weather.com/v1/current?q=${args.location}')
    ->output(new FunctionResult('Weather: ${response.current.condition.text}'));
```

## Contexts System

The contexts system (`SignalWire\Contexts\ContextBuilder`) enables multi-step, multi-persona conversations:

```php
$ctx = $agent->defineContexts();

$ctx->addContext('sales', [
    'system_prompt' => 'You are Franklin, a sales consultant.',
    'steps' => [
        ['name' => 'greeting', 'prompt' => 'Greet the customer.', 'valid_steps' => ['needs']],
        ['name' => 'needs', 'prompt' => 'Gather requirements.', 'valid_contexts' => ['support']],
    ],
]);
```

## REST Client

The REST client (`SignalWire\REST\RestClient`) provides synchronous HTTP access to all SignalWire APIs:

```php
use SignalWire\REST\RestClient;

$client = new RestClient(
    project: $_ENV['SIGNALWIRE_PROJECT_ID'],
    token:   $_ENV['SIGNALWIRE_API_TOKEN'],
    host:    $_ENV['SIGNALWIRE_SPACE'],
);

$client->fabric->aiAgents->create(name: 'Bot', prompt: ['text' => 'You are helpful.']);
$client->phoneNumbers->search(areaCode: '512');
$client->calling->dial(from: '+15559876543', to: '+15551234567', url: 'https://example.com/handler');
```

## RELAY Client

The RELAY client (`SignalWire\Relay\Client`) provides real-time call control over WebSocket:

```php
use SignalWire\Relay\Client;

$client = new Client(
    project:  $_ENV['SIGNALWIRE_PROJECT_ID'],
    token:    $_ENV['SIGNALWIRE_API_TOKEN'],
    host:     $_ENV['SIGNALWIRE_SPACE'] ?? 'relay.signalwire.com',
    contexts: ['default'],
);

$client->onCall(function ($call) {
    $call->answer();
    $call->play(media: [['type' => 'tts', 'params' => ['text' => 'Hello!']]]);
    $call->hangup();
});

$client->run();
```

## Request Flow

```
Inbound Call ──> SignalWire Platform ──> HTTP POST to Agent
                                              │
                                    ┌─────────┴─────────┐
                                    │ AgentBase          │
                                    │  - Render SWML     │
                                    │  - Handle SWAIG    │
                                    │  - Process Summary │
                                    └───────────────────┘
```

1. SignalWire sends an HTTP request to your agent's endpoint
2. `AgentBase` renders a SWML document with prompt, tools, and parameters
3. During the call, the AI invokes SWAIG functions via HTTP POST
4. Your tool handlers return `FunctionResult` objects with responses and actions
5. Post-call, the summary callback receives conversation data

## Module Structure

```
src/SignalWire/
    SignalWire.php                  # Package entry point
    Agent/AgentBase.php            # AI agent base class
    Server/AgentServer.php         # Multi-agent HTTP server
    SWML/Document.php              # SWML document builder
    SWML/Service.php               # SWML HTTP service
    SWML/Schema.php                # SWML schema validation
    SWAIG/FunctionResult.php       # Tool result with actions
    DataMap/DataMap.php            # Declarative DataMap tools
    Contexts/ContextBuilder.php    # Multi-step context system
    Skills/SkillManager.php        # Skill loader and registry
    Skills/SkillRegistry.php       # Skill catalog
    Skills/SkillBase.php           # Base class for skills
    Skills/Builtin/*.php           # Built-in skills
    Prefabs/*.php                  # Prefab agent implementations
    REST/RestClient.php            # REST API client
    REST/HttpClient.php            # HTTP transport
    REST/Namespaces/*.php          # API namespace wrappers
    Relay/Client.php               # RELAY WebSocket client
    Relay/Call.php                 # Call object
    Relay/Action.php               # Controllable action
    Relay/Event.php                # Typed event classes
    Relay/Message.php              # SMS/MMS message
    Security/SessionManager.php    # Session state management
    Logging/Logger.php             # Logging utilities
    Serverless/Adapter.php         # Cloud function adapter
```
