# SignalWire SDK for PHP

The official SignalWire AI Agents SDK for PHP. Build intelligent voice agents, generate SWML documents, handle SWAIG tool calls, and control calls in real-time.

## Features

- **Agent Framework** -- Build AI voice agents with structured prompts (POM), tools, and skills
- **SWML Generation** -- Schema-driven verb methods auto-generated from `schema.json`
- **SWAIG Functions** -- Define tools the AI can call, with a fluent result builder (40+ actions)
- **DataMap Tools** -- Server-side API tools that execute on SignalWire's servers
- **Contexts & Steps** -- Multi-step conversation workflows with navigation control
- **Skills System** -- 18 built-in skills (datetime, weather, web search, math, etc.)
- **Prefab Agents** -- Ready-made patterns (InfoGatherer, Survey, Receptionist, FAQ, Concierge)
- **Multi-Agent Hosting** -- Serve multiple agents on one server with AgentServer
- **RELAY Client** -- Real-time call control over WebSocket (57+ methods)
- **REST Client** -- Synchronous HTTP client for 18+ API namespaces
- **Serverless** -- Native PHP/CGI support, plus Lambda/Cloud Functions adapters
- **Security** -- Timing-safe auth, HMAC tokens, security headers, body size limits

## Quick Start

```php
<?php
require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

$agent = new AgentBase(['name' => 'my-agent']);

$agent->setPromptText('You are a helpful assistant.')
    ->addLanguage('English', 'en-US', 'rime.spore:mistv2')
    ->addHints('SignalWire', 'SWML');

$agent->defineTool(
    name: 'get_weather',
    description: 'Get current weather for a city',
    parameters: [
        'city' => ['type' => 'string', 'description' => 'City name'],
    ],
    handler: function (array $args, array $rawData): FunctionResult {
        $city = $args['city'] ?? 'unknown';
        return new FunctionResult("The weather in {$city} is sunny and 72F.");
    }
);

$agent->run();
```

## Installation

```bash
composer require signalwire/sdk
```

## Environment Variables

| Variable | Used By | Purpose |
|----------|---------|---------|
| `PORT` | Agent/Server | HTTP server port (default 3000) |
| `SWML_BASIC_AUTH_USER` | Agent | Basic auth username |
| `SWML_BASIC_AUTH_PASSWORD` | Agent | Basic auth password |
| `SWML_PROXY_URL_BASE` | Agent | Override webhook base URL |
| `SIGNALWIRE_PROJECT_ID` | RELAY/REST | Project identifier |
| `SIGNALWIRE_API_TOKEN` | RELAY/REST | API token |
| `SIGNALWIRE_SPACE` | RELAY/REST | Space hostname |
| `SIGNALWIRE_LOG_LEVEL` | Logging | debug/info/warn/error |
| `SIGNALWIRE_LOG_MODE` | Logging | "off" to suppress all |

## Documentation

See the [docs/](docs/) directory for detailed guides:

- [Architecture](docs/architecture.md)
- [Agent Guide](docs/agent_guide.md)
- [SWAIG Reference](docs/swaig_reference.md)
- [Skills System](docs/skills_system.md)
- [Configuration](docs/configuration.md)

## License

MIT
