# MCP to SWAIG Gateway

## Overview

The MCP-SWAIG Gateway bridges Model Context Protocol (MCP) servers with SignalWire AI Gateway (SWAIG) functions, allowing SignalWire AI agents to interact with MCP-based tools. This gateway acts as a translation layer and session manager between the two protocols.

## Installation

The MCP Gateway is included in the SignalWire Agents SDK. Install with the gateway dependencies:

```bash
composer require signalwire/signalwire-agents
```

The `mcp-gateway` CLI command can be installed from the Python SDK or run via Docker.

## Architecture

### Components

1. **MCP Gateway Service** (`mcp_gateway/`)
   - HTTP/HTTPS server with Basic Authentication
   - Manages multiple MCP server instances
   - Handles session lifecycle per SignalWire call
   - Translates between SWAIG and MCP protocols

2. **MCP Gateway Skill** (`SignalWire\Skills\McpGateway`)
   - SignalWire skill that connects agents to the gateway
   - Dynamically creates SWAIG functions from MCP tools
   - Manages session lifecycle using call_id

3. **Test MCP Server** (`mcp_gateway/test/todo_mcp.py`)
   - Simple todo list MCP server for testing
   - Demonstrates stateful MCP server implementation

## Protocol Flow

```
SignalWire Agent                 Gateway Service              MCP Server
      |                                |                          |
      |---(1) Add Skill--------------->|                          |
      |<--(2) Query Tools--------------|                          |
      |                                |---(3) List Tools-------->|
      |                                |<--(4) Tool List----------|
      |---(5) Call SWAIG Function----->|                          |
      |                                |---(6) Spawn Session----->|
      |                                |---(7) Call MCP Tool----->|
      |                                |<--(8) MCP Response-------|
      |<--(9) SWAIG Response-----------|                          |
      |                                |                          |
      |---(10) Hangup Hook------------>|                          |
      |                                |---(11) Close Session---->|
```

## Message Envelope Format

The gateway uses a custom envelope format for routing and session management:

```json
{
    "session_id": "call_xyz123",
    "service": "todo",
    "tool": "add_todo",
    "arguments": {
        "text": "Buy milk"
    },
    "timeout": 300,
    "metadata": {
        "agent_id": "agent_123",
        "timestamp": "2024-01-20T10:30:00Z"
    }
}
```

## Configuration

### Gateway Configuration (`config.json`)

The configuration supports environment variable substitution using `${VAR_NAME|default}` syntax:

```json
{
    "server": {
        "host": "${MCP_HOST|0.0.0.0}",
        "port": "${MCP_PORT|8080}",
        "auth_user": "${MCP_AUTH_USER|admin}",
        "auth_password": "${MCP_AUTH_PASSWORD|changeme}",
        "auth_token": "${MCP_AUTH_TOKEN|optional-bearer-token}"
    },
    "services": {
        "todo": {
            "command": ["python3", "./test/todo_mcp.py"],
            "description": "Simple todo list for testing",
            "enabled": true,
            "sandbox": {
                "enabled": true,
                "resource_limits": true,
                "restricted_env": true
            }
        },
        "calculator": {
            "command": ["node", "/path/to/calculator.js"],
            "description": "Math calculations",
            "enabled": true
        }
    },
    "session": {
        "default_timeout": 300,
        "max_sessions_per_service": 100,
        "cleanup_interval": 60
    },
    "rate_limiting": {
        "default_limits": ["200 per day", "50 per hour"],
        "tools_limit": "30 per minute",
        "call_limit": "10 per minute",
        "session_delete_limit": "20 per minute",
        "storage_uri": "memory://"
    },
    "logging": {
        "level": "INFO",
        "file": "gateway.log"
    }
}
```

### Environment Variable Substitution

Supported variables:
- `MCP_HOST`: Server bind address (default: 0.0.0.0)
- `MCP_PORT`: Server port (default: 8080)
- `MCP_AUTH_USER`: Basic auth username (default: admin)
- `MCP_AUTH_PASSWORD`: Basic auth password (default: changeme)
- `MCP_AUTH_TOKEN`: Bearer token for API access (default: empty)
- `MCP_SESSION_TIMEOUT`: Session timeout in seconds (default: 300)
- `MCP_MAX_SESSIONS`: Max sessions per service (default: 100)
- `MCP_LOG_LEVEL`: Logging level (default: INFO)

### Sandbox Configuration Options

Each service can have its own sandbox configuration:

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `true` | Enable/disable sandboxing completely |
| `resource_limits` | `true` | Apply CPU, memory, process limits |
| `restricted_env` | `true` | Use minimal environment variables |

#### Sandbox Profiles

1. **High Security** (Default)
```json
"sandbox": {
    "enabled": true,
    "resource_limits": true,
    "restricted_env": true
}
```

2. **Medium Security** (For services needing env vars)
```json
"sandbox": {
    "enabled": true,
    "resource_limits": true,
    "restricted_env": false
}
```

3. **No Sandbox** (For trusted services needing full access)
```json
"sandbox": {
    "enabled": false
}
```

### Skill Configuration (PHP)

```php
$agent->addSkill('mcp_gateway', [
    'gateway_url'    => 'https://localhost:8080',
    'auth_user'      => 'admin',
    'auth_password'  => 'changeme',
    'services'       => [
        [
            'name'  => 'todo',
            'tools' => ['add_todo', 'list_todos'],  // Specific tools only
        ],
        [
            'name'  => 'calculator',
            'tools' => '*',  // All tools
        ],
    ],
    'session_timeout'  => 300,
    'tool_prefix'      => 'mcp_',
    'retry_attempts'   => 3,
    'request_timeout'  => 30,
    'verify_ssl'       => true,
]);
```

## API Endpoints

### Gateway Service Endpoints

#### GET /health
Health check endpoint
```bash
curl http://localhost:8080/health
```

#### GET /services
List available MCP services
```bash
curl -u admin:changeme http://localhost:8080/services
```

#### GET /services/{service_name}/tools
Get tools for a specific service
```bash
curl -u admin:changeme http://localhost:8080/services/todo/tools
```

#### POST /services/{service_name}/call
Call a tool on a service

Using Basic Auth:
```bash
curl -u admin:changeme -X POST http://localhost:8080/services/todo/call \
  -H "Content-Type: application/json" \
  -d '{
    "tool": "add_todo",
    "arguments": {"text": "Test item"},
    "session_id": "test-123",
    "timeout": 300
  }'
```

Using Bearer Token:
```bash
curl -X POST http://localhost:8080/services/todo/call \
  -H "Authorization: Bearer your-token-here" \
  -H "Content-Type: application/json" \
  -d '{
    "tool": "add_todo",
    "arguments": {"text": "Test item"},
    "session_id": "test-123"
  }'
```

#### GET /sessions
List active sessions
```bash
curl -u admin:changeme http://localhost:8080/sessions
```

#### DELETE /sessions/{session_id}
Close a specific session
```bash
curl -u admin:changeme -X DELETE http://localhost:8080/sessions/test-123
```

## Security Features

### Authentication
- **Basic Auth**: Username/password authentication
- **Bearer Token**: Alternative token-based authentication
- **Dual Support**: Can use either Basic Auth or Bearer tokens

### Input Validation
- Service name validation (alphanumeric + dash/underscore, max 64 chars)
- Session ID validation (alphanumeric + dot/dash/underscore, max 128 chars)
- Tool name validation (alphanumeric + dash/underscore, max 64 chars)
- Request size limits (10 MB max)

### Rate Limiting
Fully configurable through the `rate_limiting` section in config.json.

### Security Headers
- X-Content-Type-Options: nosniff
- X-Frame-Options: DENY
- X-XSS-Protection: 1; mode=block
- Content-Security-Policy: default-src 'none'
- Strict-Transport-Security (HTTPS only)

## Testing

### 1. Unit Testing the Gateway

```bash
# Start the gateway
cd mcp_gateway
python3 gateway_service.py

# Test with curl
./test/test_gateway.sh
```

### 2. End-to-End Testing with PHP

```php
<?php
require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(
    name:  'MCP Test Agent',
    route: '/mcp-test',
);

$agent->addSkill('mcp_gateway', [
    'gateway_url'   => 'http://localhost:8080',
    'auth_user'     => 'admin',
    'auth_password' => 'changeme',
    'services'      => [['name' => 'todo']],
]);

$agent->run();
```

## Deployment

### Docker Deployment

```bash
cd mcp_gateway

# Build the Docker image
./mcp-docker.sh build

# Start in background
./mcp-docker.sh start -d

# View logs
./mcp-docker.sh logs -f

# Check status
./mcp-docker.sh status

# Stop the container
./mcp-docker.sh stop
```

### Docker Compose

```bash
cd mcp_gateway
docker-compose up -d
docker-compose logs -f
docker-compose down
```

## Troubleshooting

### Common Issues

1. **MCP Server Won't Start** - Check command path in config.json and verify the server is executable
2. **Authentication Failures** - Verify credentials match in config and skill
3. **Session Timeouts** - Increase timeout in skill configuration
4. **SSL Certificate Errors** - For self-signed certs, set `verify_ssl: false`

### Debug Mode

Enable debug logging:
```json
{
    "logging": {
        "level": "DEBUG",
        "file": "gateway.log"
    }
}
```

## Examples

- `examples/mcp_gateway_demo.php` - Agent connecting to MCP servers through the `mcp_gateway` skill
