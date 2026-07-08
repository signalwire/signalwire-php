# SignalWire AI Agents SDK -- PHP Port

## Project Overview

PHP port of the SignalWire AI Agents SDK. Generates SWML documents, handles SWAIG webhooks, and provides RELAY/REST clients for the SignalWire platform.

Package: `signalwire/sdk` (Composer)
PHP: >= 8.1
Framework: None (standalone, PSR-7 compatible)

## Development Commands

Test / lint / format go through the canonical `scripts/run-*.sh` entry points.
They self-bootstrap their tool environment (resolve the repo root from the
script's own path, put `vendor/bin` on PATH, `composer install` if `vendor/` is
missing) and run correctly from ANY directory — call them instead of
`phpunit` / `php-cs-fixer` / `phpstan` directly (see
porting-sdk/RUN_LINT_FORMAT_SPEC.md). `scripts/run-ci.sh` invokes these same
scripts for its TEST/FMT/LINT gates, so local == CI.

```bash
# Install dependencies (the run-*.sh scripts also do this automatically on first
# use if vendor/ is missing)
composer install

# Run the full test suite (canonical entry point; self-bootstraps, any CWD)
bash scripts/run-tests.sh

# Run a subset — pass a filter (phpunit --filter): a test name / class / regex
bash scripts/run-tests.sh LoggerTest

# Format (php-cs-fixer): APPLY in place (default) — reformats the tree
bash scripts/run-format.sh
# Format VERIFY-ONLY (what CI runs): fails if anything is unformatted
bash scripts/run-format.sh --check

# Lint (phpstan level 9, zero findings): report findings, non-zero on any finding
bash scripts/run-lint.sh

# Syntax check a single file
php -l src/SignalWire/Logging/Logger.php
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
