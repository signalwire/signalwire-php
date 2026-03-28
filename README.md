<!-- Header -->
<div align="center">
    <a href="https://signalwire.com" target="_blank">
        <img src="https://github.com/user-attachments/assets/0c8ed3b9-8c50-4dc6-9cc4-cc6cd137fd50" width="500" />
    </a>

# SignalWire SDK for PHP

_Build AI voice agents, control live calls over WebSocket, and manage every SignalWire resource over REST -- all from one package._

<p align="center">
  <a href="https://developer.signalwire.com/sdks/agents-sdk" target="_blank">Documentation</a> &middot;
  <a href="https://github.com/signalwire/signalwire-docs/issues/new/choose" target="_blank">Report an Issue</a> &middot;
  <a href="https://packagist.org/packages/signalwire/sdk" target="_blank">Packagist</a>
</p>

<a href="https://discord.com/invite/F2WNYTNjuF" target="_blank"><img src="https://img.shields.io/badge/Discord%20Community-5865F2" alt="Discord" /></a>
<a href="LICENSE"><img src="https://img.shields.io/badge/MIT-License-blue" alt="MIT License" /></a>
<a href="https://github.com/signalwire/signalwire-php" target="_blank"><img src="https://img.shields.io/github/stars/signalwire/signalwire-php" alt="GitHub Stars" /></a>

</div>

---

## What's in this SDK

| Capability | What it does | Quick link |
|-----------|-------------|------------|
| **AI Agents** | Build voice agents that handle calls autonomously -- the platform runs the AI pipeline, your code defines the persona, tools, and call flow | [Agent Guide](#ai-agents) |
| **RELAY Client** | Control live calls and SMS/MMS in real time over WebSocket -- answer, play, record, collect DTMF, conference, transfer, and more | [RELAY docs](relay/README.md) |
| **REST Client** | Manage SignalWire resources over HTTP -- phone numbers, SIP endpoints, Fabric AI agents, video rooms, messaging, and 18+ API namespaces | [REST docs](rest/README.md) |

```bash
composer require signalwire/sdk
```

---

## AI Agents

Each agent is a self-contained microservice that generates [SWML](docs/swml_service_guide.md) (SignalWire Markup Language) and handles [SWAIG](docs/swaig_reference.md) (SignalWire AI Gateway) tool calls. The SignalWire platform runs the entire AI pipeline (STT, LLM, TTS) -- your agent just defines the behavior.

```php
<?php
require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

$agent = new AgentBase(name: 'my-agent', route: '/agent');

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->promptAddSection('Role', 'You are a helpful assistant.');

$agent->defineTool(
    name:        'get_time',
    description: 'Get the current time',
    parameters:  ['type' => 'object', 'properties' => []],
    handler: function (array $args, array $rawData): FunctionResult {
        return new FunctionResult('The time is ' . date('H:i:s'));
    },
);

$agent->run();
```

Test locally without running a server:

```bash
vendor/bin/swaig-test examples/simple_agent.php --list-tools
vendor/bin/swaig-test examples/simple_agent.php --dump-swml
vendor/bin/swaig-test examples/simple_agent.php --exec get_time
```

### Agent Features

- **Prompt Object Model (POM)** -- structured prompt composition via `promptAddSection()`
- **SWAIG tools** -- define functions with `defineTool()` that the AI calls mid-conversation, with native access to the call's media stack
- **Skills system** -- add capabilities with one-liners: `$agent->addSkill('datetime')`
- **Contexts and steps** -- structured multi-step workflows with navigation control
- **DataMap tools** -- tools that execute on SignalWire's servers, calling REST APIs without your own webhook
- **Dynamic configuration** -- per-request agent customization for multi-tenant deployments
- **Call flow control** -- pre-answer, post-answer, and post-AI verb insertion
- **Prefab agents** -- ready-to-use archetypes (InfoGatherer, Survey, FAQ, Receptionist, Concierge)
- **Multi-agent hosting** -- serve multiple agents on a single server with `AgentServer`
- **SIP routing** -- route SIP calls to agents based on usernames
- **Session state** -- persistent conversation state with global data and post-prompt summaries
- **Security** -- auto-generated basic auth, function-specific HMAC tokens, SSL support
- **Serverless** -- native PHP/CGI support, plus Lambda and Cloud Functions adapters

### Agent Examples

The [`examples/`](examples/) directory contains working examples:

| Example | What it demonstrates |
|---------|---------------------|
| [simple_agent.php](examples/simple_agent.php) | POM prompts, SWAIG tools, multilingual support, LLM tuning |
| [contexts_demo.php](examples/contexts_demo.php) | Multi-persona workflow with context switching and step navigation |
| [datamap_demo.php](examples/datamap_demo.php) | Server-side API tools without webhooks |
| [skills_demo.php](examples/skills_demo.php) | Loading built-in skills (datetime, math) |
| [call_flow.php](examples/call_flow.php) | Call flow verbs, debug events, FunctionResult actions |
| [session_state.php](examples/session_state.php) | on_summary, global data, post-prompt summaries |
| [multi_agent_server.php](examples/multi_agent_server.php) | Multiple agents on one server |
| [simple_dynamic_agent.php](examples/simple_dynamic_agent.php) | Per-request dynamic configuration, multi-tenant routing |

See [examples/README.md](examples/README.md) for the full list organized by category.

---

## RELAY Client

Real-time call control and messaging over WebSocket. The RELAY client connects to SignalWire via the Blade protocol and gives you imperative, blocking control over live phone calls and SMS/MMS.

```php
<?php
require 'vendor/autoload.php';

use SignalWire\Relay\Client;

$client = new Client(
    project:  $_ENV['SIGNALWIRE_PROJECT_ID'],
    token:    $_ENV['SIGNALWIRE_API_TOKEN'],
    host:     $_ENV['SIGNALWIRE_SPACE'] ?? 'relay.signalwire.com',
    contexts: ['default'],
);

$client->onCall(function ($call) {
    $call->answer();
    $action = $call->play(media: [
        ['type' => 'tts', 'params' => ['text' => 'Welcome to SignalWire!']],
    ]);
    $action->wait();
    $call->hangup();
});

$client->connectWs();
$client->authenticate();
$client->run();
```

- 57+ calling methods (play, record, collect, detect, tap, stream, AI, conferencing, and more)
- SMS/MMS messaging with delivery tracking
- Action objects with `wait()`, `stop()`, `pause()`, `resume()`
- Auto-reconnect with exponential backoff

See the **[RELAY documentation](relay/README.md)** for the full guide, API reference, and examples.

---

## REST Client

Synchronous REST client for managing SignalWire resources and controlling calls over HTTP. No WebSocket required.

```php
<?php
require 'vendor/autoload.php';

use SignalWire\REST\RestClient;

$client = new RestClient(
    project: $_ENV['SIGNALWIRE_PROJECT_ID'],
    token:   $_ENV['SIGNALWIRE_API_TOKEN'],
    host:    $_ENV['SIGNALWIRE_SPACE'],
);

$client->fabric->aiAgents->create(name: 'Support Bot', prompt: ['text' => 'You are helpful.']);
$client->calling->play($callId, play: [['type' => 'tts', 'text' => 'Hello!']]);
$client->phoneNumbers->search(areaCode: '512');
$client->datasphere->documents->search(queryString: 'billing policy');
```

- 21 namespaced API surfaces: Fabric (13 resource types), Calling (37 commands), Video, Datasphere, Compat (Twilio-compatible), Phone Numbers, SIP, Queues, Recordings, and more
- Lightweight HTTP via cURL with connection reuse
- Array returns -- raw data, no wrapper objects

See the **[REST documentation](rest/README.md)** for the full guide, API reference, and examples.

---

## Installation

```bash
composer require signalwire/sdk
```

Requires PHP 8.1+ with the `json`, `openssl`, and `mbstring` extensions.

## Documentation

Full reference documentation is available at **[developer.signalwire.com/sdks/agents-sdk](https://developer.signalwire.com/sdks/agents-sdk)**.

Guides are also available in the [`docs/`](docs/) directory:

### Getting Started

- [Agent Guide](docs/agent_guide.md) -- creating agents, prompt configuration, dynamic setup
- [Architecture](docs/architecture.md) -- SDK architecture and core concepts
- [SDK Features](docs/sdk_features.md) -- feature overview, SDK vs raw SWML comparison

### Core Features

- [SWAIG Reference](docs/swaig_reference.md) -- function results, actions, post_data lifecycle
- [Contexts and Steps](docs/contexts_guide.md) -- structured workflows, navigation, gather mode
- [DataMap Guide](docs/datamap_guide.md) -- serverless API tools without webhooks
- [LLM Parameters](docs/llm_parameters.md) -- temperature, top_p, barge confidence tuning
- [SWML Service Guide](docs/swml_service_guide.md) -- low-level construction of SWML documents

### Skills and Extensions

- [Skills System](docs/skills_system.md) -- built-in skills and the modular framework
- [Third-Party Skills](docs/third_party_skills.md) -- creating and publishing custom skills

### Deployment

- [CLI Guide](docs/cli_guide.md) -- `swaig-test` command reference
- [Configuration](docs/configuration.md) -- environment variables, SSL, proxy setup
- [Security](docs/security.md) -- authentication and security model

### Reference

- [API Reference](docs/api_reference.md) -- complete class and method reference
- [Skills Parameter Schema](docs/skills_parameter_schema.md) -- skill parameter definitions

## Environment Variables

| Variable | Used by | Description |
|----------|---------|-------------|
| `SIGNALWIRE_PROJECT_ID` | RELAY, REST | Project identifier |
| `SIGNALWIRE_API_TOKEN` | RELAY, REST | API token |
| `SIGNALWIRE_SPACE` | RELAY, REST | Space hostname (e.g. `example.signalwire.com`) |
| `SWML_BASIC_AUTH_USER` | Agents | Basic auth username (default: auto-generated) |
| `SWML_BASIC_AUTH_PASSWORD` | Agents | Basic auth password (default: auto-generated) |
| `SWML_PROXY_URL_BASE` | Agents | Base URL when behind a reverse proxy |
| `SWML_SSL_ENABLED` | Agents | Enable HTTPS (`true`, `1`, `yes`) |
| `SWML_SSL_CERT_PATH` | Agents | Path to SSL certificate |
| `SWML_SSL_KEY_PATH` | Agents | Path to SSL private key |
| `SIGNALWIRE_LOG_LEVEL` | All | Logging level (`debug`, `info`, `warn`, `error`) |
| `SIGNALWIRE_LOG_MODE` | All | Set to `off` to suppress all logging |

## Testing

```bash
# Install dependencies
composer install

# Run the test suite
composer test

# Run with verbose output
composer test:verbose

# Coverage (requires Xdebug or PCOV)
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html coverage/
```

## License

MIT -- see [LICENSE](LICENSE) for details.
