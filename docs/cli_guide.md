# CLI Guide (PHP)

## Overview

The SignalWire PHP SDK is designed to be run from the command line. Agent scripts are standard PHP files that create an agent and call `run()` to start the HTTP server.

## Running an Agent

```bash
php examples/simple_agent.php
```

Output:

```
Starting the agent. Press Ctrl+C to stop.
Agent 'simple' is available at:
URL: http://localhost:3000/simple
Basic Auth: signalwire:a1b2c3d4e5f6...
```

## Environment Variables

Set credentials before running:

```bash
export SIGNALWIRE_PROJECT_ID=your-project-id
export SIGNALWIRE_API_TOKEN=your-api-token
export SIGNALWIRE_SPACE=example.signalwire.com
```

## Common Options

### Custom Host and Port

```php
$agent = new AgentBase(
    name:  'my-agent',
    route: '/agent',
    host:  '0.0.0.0',
    port:  8080,
);
```

Or via environment:

```bash
export SWML_HOST=0.0.0.0
export SWML_PORT=8080
php agent.php
```

### Custom Auth Credentials

```bash
export SWML_BASIC_AUTH_USER=admin
export SWML_BASIC_AUTH_PASSWORD=mysecretpassword
php agent.php
```

## Running Multiple Agents

Use `AgentServer` to host multiple agents:

```bash
php examples/multi_agent_server.php
```

Output:

```
Starting Multi-Agent AI Server

Available agents:
- http://localhost:3000/healthcare - Healthcare AI (HIPAA compliant)
- http://localhost:3000/finance    - Financial Services AI
- http://localhost:3000/retail     - Retail Customer Service AI
```

## Testing with cURL

### Fetch the SWML document

```bash
curl -u signalwire:password http://localhost:3000/agent
```

### Test with query parameters (dynamic config)

```bash
curl -u signalwire:password 'http://localhost:3000/agent?customer_tier=vip&department=sales'
```

### Invoke a SWAIG function

```bash
curl -X POST -u signalwire:password \
  -H 'Content-Type: application/json' \
  -d '{"function":"get_weather","argument":{"location":"Austin"}}' \
  http://localhost:3000/agent/swaig
```

## Running REST Scripts

REST client scripts run once and exit:

```bash
export SIGNALWIRE_PROJECT_ID=your-project-id
export SIGNALWIRE_API_TOKEN=your-api-token
export SIGNALWIRE_SPACE=example.signalwire.com

php rest/examples/rest_manage_resources.php
```

## Running RELAY Scripts

RELAY scripts connect via WebSocket and run continuously:

```bash
export SIGNALWIRE_PROJECT_ID=your-project-id
export SIGNALWIRE_API_TOKEN=your-api-token
export SIGNALWIRE_SPACE=example.signalwire.com

php relay/examples/relay_answer_and_welcome.php
```

Output:

```
Waiting for inbound calls on context 'default' ...
```

## Docker Deployment

```dockerfile
FROM php:8.2-cli
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader
COPY . .
EXPOSE 3000
CMD ["php", "agent.php"]
```

```bash
docker build -t my-agent .
docker run -p 3000:3000 \
  -e SIGNALWIRE_PROJECT_ID \
  -e SIGNALWIRE_API_TOKEN \
  -e SIGNALWIRE_SPACE \
  my-agent
```

## Composer Setup

```json
{
    "require": {
        "signalwire/signalwire-php": "^1.0"
    }
}
```

```bash
composer install
```

## Debugging

### Enable Debug Logging

```bash
export SWML_LOG_LEVEL=debug
php agent.php
```

### Enable Debug Events

```php
$agent->enableDebugEvents(true);
$agent->onDebugEvent(function ($event) {
    file_put_contents('php://stderr', json_encode($event) . "\n");
});
```

### RELAY Debug

```bash
export SIGNALWIRE_LOG_LEVEL=debug
php relay/examples/relay_answer_and_welcome.php
```

This logs all WebSocket frames and protocol messages.

## Process Management

For production, use a process manager:

### Supervisor

```ini
[program:agent]
command=php /app/agent.php
autostart=true
autorestart=true
environment=SIGNALWIRE_PROJECT_ID="...",SIGNALWIRE_API_TOKEN="...",SIGNALWIRE_SPACE="..."
stdout_logfile=/var/log/agent.log
```

### systemd

```ini
[Unit]
Description=SignalWire Agent
After=network.target

[Service]
Type=simple
User=www-data
ExecStart=/usr/bin/php /app/agent.php
EnvironmentFile=/etc/signalwire/agent.env
Restart=always

[Install]
WantedBy=multi-user.target
```
