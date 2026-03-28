# SignalWire AI Agents SDK -- PHP Port

## Project Overview

PHP port of the SignalWire AI Agents SDK. Generates SWML documents, handles SWAIG webhooks, and provides RELAY/REST clients for the SignalWire platform.

Package: `signalwire/sdk` (Composer)
PHP: >= 8.1
Framework: None (standalone, PSR-7 compatible)

## Development Commands

```bash
# Install dependencies
composer install

# Run all tests
composer test

# Run tests verbose
composer test:verbose

# Run specific test file
./vendor/bin/phpunit tests/LoggerTest.php

# Syntax check
php -l src/SignalWire/Logging/Logger.php

# Lint all source files
find src -name '*.php' -exec php -l {} \;
```

## Architecture

### Core Components

1. **Logger** (`src/SignalWire/Logging/Logger.php`) -- Singleton loggers, level filtering, env var config
2. **SWML Document** (`src/SignalWire/SWML/`) -- Document model, schema loading, auto-vivified verb methods
3. **SWMLService** (`src/SignalWire/SWML/Service.php`) -- HTTP server base with auth and security headers
4. **AgentBase** (`src/SignalWire/Agent/AgentBase.php`) -- Main agent class composing all features
5. **FunctionResult** (`src/SignalWire/SWAIG/FunctionResult.php`) -- Fluent builder for tool responses (40+ actions)
6. **DataMap** (`src/SignalWire/DataMap/DataMap.php`) -- Server-side API tools
7. **Contexts** (`src/SignalWire/Contexts/`) -- Multi-step conversation workflows
8. **Skills** (`src/SignalWire/Skills/`) -- 18 built-in skills with registry/manager
9. **Prefabs** (`src/SignalWire/Prefabs/`) -- 5 ready-made agent patterns
10. **AgentServer** (`src/SignalWire/Server/AgentServer.php`) -- Multi-agent hosting
11. **RelayClient** (`src/SignalWire/Relay/Client.php`) -- WebSocket real-time call control
12. **RestClient** (`src/SignalWire/REST/RestClient.php`) -- Synchronous HTTP API client

### Key Patterns

- **Method chaining**: All config methods return `$this`
- **Auto-vivification**: SWML verb methods generated from `schema.json` via `__call()`
- **Dynamic config**: Per-request agent cloning for multi-tenancy
- **Timing-safe auth**: Use `hash_equals()` for all credential comparisons
- **Schema-driven**: 38 SWML verbs extracted from embedded schema at runtime

### PHP-Specific Conventions

- PSR-4 autoloading under `src/SignalWire/`
- PHPUnit 10 for testing
- `declare(strict_types=1)` in every file
- Type declarations on all parameters and return types
- No external framework dependency for core (plain PHP HTTP handling)

## File Locations

- Source: `src/SignalWire/`
- Tests: `tests/`
- Examples: `examples/`
- RELAY docs: `relay/`
- REST docs: `rest/`
- General docs: `docs/`
- CLI tools: `bin/`
- SWML schema: `src/SignalWire/SWML/schema.json`

## Reference Implementation

The Python SDK at `~/src/signalwire-python` is the source of truth.
The porting guide is at `~/src/porting-sdk/PORTING_GUIDE.md`.
Progress is tracked in `PROGRESS.md`.
