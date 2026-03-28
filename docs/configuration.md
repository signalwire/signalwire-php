# Configuration Guide (PHP)

## Overview

All SignalWire services support optional JSON configuration files with environment variable substitution. Services continue to work without any configuration file.

## Quick Start

### Zero Configuration

```php
$agent = new AgentBase(name: 'my-agent', route: '/agent');
$agent->run();
```

### With Configuration File

Configuration files are auto-detected in this order:

1. `{service_name}_config.json`
2. `config.json`
3. `.swml/config.json`
4. `~/.swml/config.json`
5. `/etc/swml/config.json`

### Configuration Structure

```json
{
  "service": {
    "name": "my-service",
    "host": "${HOST|0.0.0.0}",
    "port": "${PORT|3000}"
  },
  "security": {
    "ssl_enabled": "${SSL_ENABLED|false}",
    "ssl_cert_path": "${SSL_CERT|/etc/ssl/cert.pem}",
    "ssl_key_path": "${SSL_KEY|/etc/ssl/key.pem}",
    "basic_auth_user": "${AUTH_USER|signalwire}",
    "basic_auth_password": "${AUTH_PASS|}"
  },
  "logging": {
    "level": "${LOG_LEVEL|info}",
    "format": "${LOG_FORMAT|json}"
  }
}
```

### Environment Variable Substitution

Config values support `${VAR|default}` syntax:

- `${PORT}` -- Use the PORT environment variable, fail if missing
- `${PORT|3000}` -- Use PORT, default to 3000 if missing
- `${SSL_ENABLED|false}` -- Use SSL_ENABLED, default to "false"

## Environment Variables

### Core Settings

| Variable | Default | Description |
|----------|---------|-------------|
| `SWML_HOST` | `0.0.0.0` | Listen address |
| `SWML_PORT` | `3000` | Listen port |
| `SWML_LOG_LEVEL` | `info` | Log level (debug, info, warn, error) |

### SignalWire Credentials

| Variable | Description |
|----------|-------------|
| `SIGNALWIRE_PROJECT_ID` | Project ID for REST/RELAY authentication |
| `SIGNALWIRE_API_TOKEN` | API token for REST/RELAY authentication |
| `SIGNALWIRE_SPACE` | Space hostname (e.g. `example.signalwire.com`) |

### Agent Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `SWML_BASIC_AUTH_USER` | `signalwire` | Basic auth username |
| `SWML_BASIC_AUTH_PASSWORD` | *auto* | Basic auth password |
| `SWML_SSL_ENABLED` | `false` | Enable HTTPS |
| `SWML_SSL_CERT_PATH` | - | SSL certificate path |
| `SWML_SSL_KEY_PATH` | - | SSL key path |
| `SWML_DOMAIN` | - | Domain name |

### RELAY Client

| Variable | Default | Description |
|----------|---------|-------------|
| `SIGNALWIRE_LOG_LEVEL` | - | Log level for RELAY (`debug` for WebSocket traffic) |

## Constructor-Based Configuration

All settings can be passed directly to constructors:

```php
// Agent
$agent = new AgentBase(
    name:       'my-agent',
    route:      '/agent',
    host:       '0.0.0.0',
    port:       3000,
    autoAnswer: true,
    recordCall: true,
);

// REST client
$client = new RestClient(
    project: $_ENV['SIGNALWIRE_PROJECT_ID'],
    token:   $_ENV['SIGNALWIRE_API_TOKEN'],
    host:    $_ENV['SIGNALWIRE_SPACE'],
);

// RELAY client
$relay = new Client(
    project:  $_ENV['SIGNALWIRE_PROJECT_ID'],
    token:    $_ENV['SIGNALWIRE_API_TOKEN'],
    host:     $_ENV['SIGNALWIRE_SPACE'] ?? 'relay.signalwire.com',
    contexts: ['default'],
);
```

## AI Model Configuration

```php
$agent->setParams([
    'ai_model'              => 'gpt-4.1-nano',
    'wait_for_user'         => false,
    'end_of_speech_timeout' => 1000,
    'ai_volume'             => 5,
    'languages_enabled'     => true,
    'local_tz'              => 'America/Los_Angeles',
    'attention_timeout'     => 15000,
]);
```

### Common AI Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `ai_model` | string | LLM model name |
| `wait_for_user` | bool | Wait for user to speak first |
| `end_of_speech_timeout` | int | Silence timeout (ms) |
| `ai_volume` | int | AI speech volume (-10 to 10) |
| `languages_enabled` | bool | Enable multi-language |
| `local_tz` | string | Timezone for time-aware prompts |
| `attention_timeout` | int | Max silence before disconnect (ms) |
| `background_file_volume` | int | Background audio volume |

## Multi-Environment Setup

Use different config files per environment:

```bash
# Development
export SWML_HOST=0.0.0.0
export SWML_PORT=3000
export LOG_LEVEL=debug

# Production
export SWML_HOST=0.0.0.0
export SWML_PORT=8080
export SSL_ENABLED=true
export LOG_LEVEL=info
```

## Docker Configuration

```dockerfile
FROM php:8.2-cli
WORKDIR /app
COPY . .
RUN composer install --no-dev
ENV SWML_HOST=0.0.0.0
ENV SWML_PORT=3000
CMD ["php", "agent.php"]
```

```yaml
# docker-compose.yml
services:
  agent:
    build: .
    ports:
      - "3000:3000"
    environment:
      - SIGNALWIRE_PROJECT_ID
      - SIGNALWIRE_API_TOKEN
      - SIGNALWIRE_SPACE
      - SWML_BASIC_AUTH_PASSWORD
```
