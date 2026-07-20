# SignalWire AI Agents - Cloud Functions Deployment Guide

This guide covers deploying SignalWire AI Agents to Google Cloud Functions and Azure Functions from PHP.

## Overview

SignalWire AI Agents support deployment to major cloud function platforms:

- **Google Cloud Functions** - Serverless compute platform on Google Cloud
- **Azure Functions** - Serverless compute service on Microsoft Azure
- **AWS Lambda** - Already supported (see existing documentation)

## Google Cloud Functions

### Environment Detection

The agent automatically detects Google Cloud Functions environment using these variables:
- `FUNCTION_TARGET` - The function entry point
- `K_SERVICE` - Knative service name (Cloud Run/Functions)
- `GOOGLE_CLOUD_PROJECT` - Google Cloud project ID

### Deployment Steps

1. **Create your agent file** (`index.php`):
```php
<?php
require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\Serverless\Adapter;

// Create agent instance
$agent = new AgentBase(
    name:  'my-agent',
    route: '/',
);

$agent->promptAddSection('Role', 'You are a helpful assistant running in Google Cloud Functions.');

// Handle the request. Adapter::handleGcf() reads the request from the PHP
// superglobals, calls $agent->handleRequest(), and emits the response.
Adapter::handleGcf($agent);
```

> `Adapter::serve($agent)` auto-detects the execution mode (`gcf`, `azure`,
> `lambda`, `cgi`, or `server`) and dispatches to the right handler, so a single
> entry point can run unchanged across platforms.

2. **Create `composer.json`**:
```json
{
    "require": {
        "signalwire/signalwire-agents": "*"
    }
}
```

3. **Deploy using gcloud**:
```bash
gcloud functions deploy my-agent \
    --runtime php82 \
    --trigger-http \
    --entry-point agent_handler \
    --allow-unauthenticated
```

### Environment Variables

Set these environment variables for your function:

```bash
# SignalWire credentials
export SIGNALWIRE_PROJECT_ID="your-project-id"
export SIGNALWIRE_API_TOKEN="your-token"

# Agent HTTP Basic Auth
export SWML_BASIC_AUTH_USER="your-username"
export SWML_BASIC_AUTH_PASSWORD="your-password"

# Optional: Custom region/project settings
export FUNCTION_REGION="us-central1"
export GOOGLE_CLOUD_PROJECT="your-project-id"
```

### URL Format

Google Cloud Functions URLs follow this pattern:
```
https://{region}-{project-id}.cloudfunctions.net/{function-name}
```

With authentication:
```
https://username:password@{region}-{project-id}.cloudfunctions.net/{function-name}
```

## Azure Functions

### Environment Detection

The agent automatically detects Azure Functions environment using these variables:
- `AZURE_FUNCTIONS_ENVIRONMENT` - Azure Functions runtime environment
- `FUNCTIONS_WORKER_RUNTIME` - Runtime language (custom for PHP)
- `AzureWebJobsStorage` - Azure storage connection string

### Deployment Steps

1. **Create your function app structure**:
```
my-agent-function/
├── agent/
│   ├── function.json
│   └── index.php
├── composer.json
└── host.json
```

2. **Create `agent/index.php`**:
<!-- snippet: no-run illustrative deployment file — `$request` is the Azure Functions HTTP request array supplied by the runtime (established in the prose below), not defined in this fragment -->
```php
<?php
require __DIR__ . '/../vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\Serverless\Adapter;

$agent = new AgentBase(
    name: 'my-agent',
);

// $request is the Azure Functions HTTP request array (method, url, headers, body).
// handleAzure() returns an Azure-compatible {status, headers, body} response.
return Adapter::handleAzure($agent, $request);
```

3. **Create `agent/function.json`**:
```json
{
  "bindings": [
    {
      "authLevel": "anonymous",
      "type": "httpTrigger",
      "direction": "in",
      "name": "req",
      "methods": ["get", "post"]
    },
    {
      "type": "http",
      "direction": "out",
      "name": "$return"
    }
  ]
}
```

4. **Deploy using Azure CLI**:
```bash
# Create function app
az functionapp create \
    --resource-group myResourceGroup \
    --consumption-plan-location westus \
    --runtime custom \
    --functions-version 4 \
    --name my-agent-function \
    --storage-account mystorageaccount

# Deploy code
func azure functionapp publish my-agent-function
```

### Environment Variables

Set these in your Azure Function App settings:

```bash
SIGNALWIRE_PROJECT_ID="your-project-id"
SIGNALWIRE_API_TOKEN="your-token"
SWML_BASIC_AUTH_USER="your-username"
SWML_BASIC_AUTH_PASSWORD="your-password"
```

### URL Format

Azure Functions URLs follow this pattern:
```
https://{function-app-name}.azurewebsites.net/api/{function-name}
```

## Authentication

Both platforms support HTTP Basic Authentication:

### Automatic Authentication
```php
use SignalWire\Agent\AgentBase;

$agent = new AgentBase(
    name:              'my-agent',
    basicAuthUser:     'your-username',
    basicAuthPassword: 'your-password',
);
```

### Authentication Flow
1. Client sends request with `Authorization: Basic <credentials>` header
2. Agent validates credentials against configured username/password
3. If invalid, returns 401 with `WWW-Authenticate` header
4. If valid, processes the request normally

## Testing

### Local Testing

```bash
# Install dependencies
composer install

# Run locally with PHP built-in server
php -S localhost:3000 index.php
```

### Testing Authentication

```bash
# Test without auth (should return 401)
curl https://your-function-url/

# Test with valid auth
curl -u username:password https://your-function-url/

# Test SWAIG function call
curl -u username:password \
  -H "Content-Type: application/json" \
  -d '{"call_id": "test", "argument": {"parsed": [{"param": "value"}]}}' \
  https://your-function-url/your_function_name
```

## Best Practices

### Performance
- Use connection pooling for database connections
- Implement proper caching strategies
- Minimise cold start times with smaller deployment packages

### Security
- Always use HTTPS endpoints
- Implement proper authentication
- Use environment variables for sensitive data
- Consider using cloud-native secret management

### Monitoring
- Enable cloud platform logging
- Monitor function execution times
- Set up alerts for errors and timeouts
- Use distributed tracing for complex workflows

### Cost Optimisation
- Right-size memory allocation
- Implement proper timeout settings
- Use reserved capacity for predictable workloads
- Monitor and optimise function execution patterns

## Troubleshooting

### Common Issues

**Environment Detection:**
```php
// Check detected mode
echo "Detected: " . getenv('K_SERVICE') . "\n";
echo "Project:  " . getenv('GOOGLE_CLOUD_PROJECT') . "\n";
```

**Authentication Issues:**
- Verify username/password are set correctly
- Check that the Authorization header is being sent
- Ensure credentials match exactly (case-sensitive)

### Debugging

Enable debug logging:
```php
error_reporting(E_ALL);
ini_set('display_errors', '1');
```

## Migration from Other Platforms

### From AWS Lambda
- Update environment variable names
- Modify request/response handling if needed
- Update deployment scripts

### From Traditional Servers
- Add cloud function entry point
- Configure environment variables
- Update URL generation logic
- Test authentication flow
