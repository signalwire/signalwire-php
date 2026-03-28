# Security Configuration Guide (PHP)

## Overview

The SignalWire PHP SDK provides a unified security configuration system with secure defaults for HTTPS, basic authentication, and security headers.

## Quick Start

### Basic HTTPS Setup

```bash
export SWML_SSL_ENABLED=true
export SWML_SSL_CERT_PATH=/path/to/cert.pem
export SWML_SSL_KEY_PATH=/path/to/key.pem
export SWML_DOMAIN=yourdomain.com
```

### Basic Authentication

Basic auth is enabled by default with auto-generated credentials:

```bash
export SWML_BASIC_AUTH_USER=myusername
export SWML_BASIC_AUTH_PASSWORD=mysecurepassword
```

## Environment Variables

### SSL/TLS Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `SWML_SSL_ENABLED` | `false` | Enable HTTPS |
| `SWML_SSL_CERT_PATH` | - | Path to SSL certificate |
| `SWML_SSL_KEY_PATH` | - | Path to SSL private key |
| `SWML_DOMAIN` | - | Domain name for URL generation |

### Authentication

| Variable | Default | Description |
|----------|---------|-------------|
| `SWML_BASIC_AUTH_USER` | `signalwire` | Basic auth username |
| `SWML_BASIC_AUTH_PASSWORD` | *auto-generated* | Basic auth password (32-char token) |

### Security Headers

| Variable | Default | Description |
|----------|---------|-------------|
| `SWML_ALLOWED_ORIGINS` | `*` | CORS allowed origins |
| `SWML_RATE_LIMIT` | `100` | Requests per minute per IP |

## Retrieving Credentials

```php
$agent = new AgentBase(name: 'secure-agent', route: '/agent');

$user = $agent->basicAuthUser();
$pass = $agent->basicAuthPassword();
echo "Auth: {$user}:{$pass}\n";
```

When `SWML_BASIC_AUTH_PASSWORD` is not set, a random 32-character token is generated at startup and printed to the console.

## SWAIG Function Security

SWAIG function calls from SignalWire include authentication headers. The SDK validates these automatically.

### Function-Level Security

Tools can require metadata tokens for scoped data access:

```php
$agent->defineTool(
    name:        'get_patient_info',
    description: 'Get patient medical information',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'patient_id' => ['type' => 'string', 'description' => 'Patient ID'],
        ],
    ],
    handler: function (array $args, array $rawData): FunctionResult {
        // $rawData contains meta_data_token and meta_data
        $token = $rawData['meta_data_token'] ?? null;
        if (!$token) {
            return new FunctionResult('Access denied: no metadata token.');
        }
        return new FunctionResult("Patient info retrieved.");
    },
);
```

## Production Deployment

### HTTPS with Reverse Proxy

For production, use a reverse proxy (nginx, Caddy) for TLS termination:

```nginx
server {
    listen 443 ssl;
    server_name agent.example.com;

    ssl_certificate     /etc/letsencrypt/live/agent.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/agent.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

### Environment Configuration

```bash
# Production environment
export SWML_BASIC_AUTH_USER=prod_user
export SWML_BASIC_AUTH_PASSWORD=$(openssl rand -hex 16)
export SWML_SSL_ENABLED=true
export SWML_DOMAIN=agent.example.com
```

## REST Client Security

The REST client authenticates with project credentials:

```php
use SignalWire\REST\RestClient;

$client = new RestClient(
    project: $_ENV['SIGNALWIRE_PROJECT_ID'],
    token:   $_ENV['SIGNALWIRE_API_TOKEN'],
    host:    $_ENV['SIGNALWIRE_SPACE'],
);
```

Credentials are sent as HTTP Basic Auth on every request. Always use environment variables -- never hardcode credentials.

## RELAY Client Security

The RELAY client authenticates over WebSocket:

```php
use SignalWire\Relay\Client;

$client = new Client(
    project: $_ENV['SIGNALWIRE_PROJECT_ID'],
    token:   $_ENV['SIGNALWIRE_API_TOKEN'],
    host:    $_ENV['SIGNALWIRE_SPACE'] ?? 'relay.signalwire.com',
);
```

The WebSocket connection uses TLS by default. Authentication happens via the Blade protocol after connection.

## Best Practices

1. Never hardcode credentials in source files
2. Use environment variables for all secrets
3. Rotate API tokens regularly
4. Enable HTTPS in production (directly or via reverse proxy)
5. Use auto-generated passwords for basic auth when possible
6. Keep the basic auth password in a secrets manager for multi-instance deployments
