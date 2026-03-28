# SDK Features Overview (PHP)

## Core Features

### AI Agent Framework

- **AgentBase** class for building voice AI agents as HTTP microservices
- Prompt Object Model (POM) for structured prompt construction
- SWAIG (SignalWire AI Gateway) tool framework with native media stack access
- Dynamic per-request configuration via callbacks
- Multi-agent server for hosting multiple agents on one port

### SWML Document Generation

- Full SWML (SignalWire Markup Language) document creation and validation
- Schema validation against the official SWML schema
- Dynamic verb creation for call control
- Pre-answer and post-AI verb injection

### SWAIG Function System

- Define tools with JSON schema parameter validation
- `FunctionResult` class with 20+ action methods (connect, SMS, record, hold, etc.)
- Method chaining for fluent action composition
- Post-processing mode for AI-first response delivery
- History management with `replaceInHistory()`

### DataMap Tools

- Declarative tools that execute on SignalWire's servers
- Webhook integration for REST API calls
- Expression-based pattern matching
- Variable expansion with dot notation and encoding functions
- No webhook infrastructure required

### Contexts and Steps

- Multi-step conversation flows with named contexts
- Per-context system prompts and personas
- Step-to-step and context-to-context navigation
- Conversation history management (consolidate, full_reset)

### Skills System

- Modular skill architecture with auto-discovery
- 17+ built-in skills (datetime, math, web_search, datasphere, etc.)
- Parameter-configurable skills
- Dependency validation
- Custom skill support via `SkillBase` extension

### Prefab Agents

- **InfoGathererAgent** -- guided question flows for data collection
- **SurveyAgent** -- structured surveys with rating, yes/no, and open-ended questions
- **ConciergeAgent** -- multi-purpose reception agent
- **ReceptionistAgent** -- call routing and screening
- **FAQBotAgent** -- knowledge-base Q&A

## Communication Features

### REST Client

- Synchronous HTTP client for all SignalWire REST APIs
- Namespaced sub-objects: `fabric`, `calling`, `phoneNumbers`, `compat`, `video`, `datasphere`, `registry`, etc.
- Full Fabric API: AI agents, SWML scripts, subscribers, call flows, conference rooms, SIP gateways
- Call control: dial, play, record, collect, detect, AI, transcribe, tap, stream
- Compatibility API: full Twilio-compatible LAML surface
- Phone number management, 10DLC, MFA, logs
- Array returns for zero-overhead data access

### RELAY Client

- Real-time call control over WebSocket (Blade protocol, JSON-RPC 2.0)
- Synchronous blocking API
- Auto-reconnect with exponential backoff
- All calling methods: play, record, collect, connect, detect, fax, tap, stream, AI, conferencing, queues
- SMS/MMS messaging: send, receive, track delivery
- Action objects with `wait()`, `stop()`, `pause()`, `resume()`
- Typed event classes
- Dynamic context subscription

## Infrastructure Features

### Security

- Automatic basic authentication with auto-generated credentials
- SSL/TLS support (direct or via reverse proxy)
- SWAIG function authentication
- Metadata tokens for scoped data access
- CORS and security headers

### Configuration

- JSON configuration files with environment variable substitution
- Multi-location config file discovery
- Docker-friendly environment variable support
- Zero-config mode with sensible defaults

### Logging

- Structured logging via `SignalWire\Logging\Logger`
- Configurable log levels (debug, info, warn, error)
- Debug webhook support for remote debugging

### Deployment

- Built-in HTTP server for development and production
- Docker support
- Cloud function adapter (`SignalWire\Serverless\Adapter`)
- Multi-agent server for microservice architecture
- Reverse proxy compatible

## Language and Speech

- Multi-language support with per-language voice selection
- Speech recognition hints for domain vocabulary
- Custom pronunciation mappings
- Barge-in confidence control
- End-of-speech and attention timeouts

## Session Management

- Global data for AI context persistence
- Metadata for function-scoped state
- Summary callbacks for post-call processing
- Session lifecycle hooks
