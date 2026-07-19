# Web Service & HTTP Serving

The PHP SDK serves agents over HTTP through two classes:

- `SignalWire\Agent\AgentBase` (and its base `SignalWire\SWML\Service`) — serves a
  single agent: the SWML document on `GET`, SWAIG function calls on `POST`, with
  HTTP Basic Auth and security headers built in.
- `SignalWire\Server\AgentServer` — hosts multiple agents on one process, each at
  its own route, and can also serve static files.

> There is no standalone `WebService` class in the PHP port. Static-file serving
> is provided by `AgentServer::serveStaticFiles()`.

## Serving a Single Agent

`AgentBase` extends `Service`, which exposes `serve()`, `run()`, and
`handleRequest()`.

```php
<?php
require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(
    name:  'My Agent',
    route: '/agent',
    port:  3000,
);

$agent->promptAddSection('Role', 'You are a helpful assistant.');

// Blocks, serving the built-in PHP HTTP server on host:port.
$agent->run();
```

### Service Methods

| Method | Description |
|--------|-------------|
| `serve(): void` | Auto-detect the execution mode (server / CGI / serverless) and serve. |
| `run(): void` | Run the built-in blocking HTTP server loop. |
| `handleRequest(string $method, string $path, array $headers = [], ?string $body = null): array` | Handle one request; returns `[status, headers, body]`. Useful for embedding in another framework or a custom front controller. |
| `dispatchFromGlobals(): void` | Handle the current request from PHP superglobals (CGI/FastCGI front controller). |
| `renderSwml(?array $requestBody = null, array $headers = []): array` | Render the SWML document for a request. |

### Constructor Parameters

```php
use SignalWire\Agent\AgentBase;

new AgentBase(
    name:              'My Agent',  // required
    route:             '/agent',    // URL route (default '/')
    host:              '0.0.0.0',   // bind host (default '0.0.0.0')
    port:              3000,        // bind port (default PORT env or 3000)
    basicAuthUser:     null,        // overrides SWML_BASIC_AUTH_USER
    basicAuthPassword: null,        // overrides SWML_BASIC_AUTH_PASSWORD
);
```

## Authentication

Every request is protected with HTTP Basic Authentication. Credentials are
resolved in this order:

1. The `basicAuthUser` / `basicAuthPassword` constructor arguments.
2. The `SWML_BASIC_AUTH_USER` / `SWML_BASIC_AUTH_PASSWORD` environment variables.
3. Auto-generated random credentials (the SDK logs a warning, since a
   regenerated-per-process password is the usual cause of unexpected HTTP 401s).

```php
use SignalWire\Agent\AgentBase;

$agent = new AgentBase(
    name:              'Secured Agent',
    basicAuthUser:     'admin',
    basicAuthPassword: 'super-secret',
);

// Inspect / validate at runtime
[$user, $pass] = $agent->getBasicAuthCredentials();
$ok = $agent->validateBasicAuth($user, $pass);
```

Internally, credential comparisons use PHP's built-in timing-safe `hash_equals` string-compare, so they are not vulnerable to timing attacks.

## Hosting Multiple Agents

`AgentServer` registers multiple agents, each at its own route, on a single
process.

```php
<?php
require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\Server\AgentServer;

$server = new AgentServer(host: '0.0.0.0', port: 3000);

$sales = new AgentBase(name: 'Sales', route: '/sales');
$support = new AgentBase(name: 'Support', route: '/support');

$server->register($sales);
$server->register($support, '/support');

$server->run(); // serves both agents
```

### AgentServer Methods

| Method | Description |
|--------|-------------|
| `register(AgentBase $agent, ?string $route = null): self` | Register an agent at a route. |
| `unregister(string $route): self` | Remove an agent. |
| `getAgents(): array` | List registered routes. |
| `getAgent(string $route): ?AgentBase` | Look up an agent by route. |
| `serveStaticFiles(string $directory, string $urlPrefix): self` | Serve static files from a directory under a URL prefix. |
| `setupSipRouting(string $route = '/sip', bool $auto_map = true): self` | Enable central SIP-based routing. |
| `handleRequest(string $method, string $path, array $headers = [], ?string $body = null): array` | Route one request to the matching agent. |
| `run(): void` / `serve(): void` | Start the server. |

## Serving Static Files

Call `serveStaticFiles(string $directory, string $urlPrefix)` on an `AgentServer`
after registering your agents to mount a directory of static assets under a
URL prefix. For example, mounting `./public` at `/assets` serves
`./public/logo.png` at `http://localhost:3000/assets/logo.png`. The method
throws `\RuntimeException` if the directory does not exist, and returns the
server for chaining.

## Serverless and CGI

For CGI/FastCGI, Lambda, Google Cloud Functions, and Azure Functions, use
`serve()` (which auto-detects the mode) or the `SignalWire\Serverless\Adapter`
helpers directly. See [Cloud Functions Guide](cloud_functions_guide.md).

## Deployment Behind a Reverse Proxy

When the agent runs behind Nginx/Apache, set `SWML_PROXY_URL_BASE` so generated
URLs use the external host:

```bash
export SWML_PROXY_URL_BASE="https://agents.example.com"
```

```nginx
location /agent {
    proxy_pass http://127.0.0.1:3000;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```
