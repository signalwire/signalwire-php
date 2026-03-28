# REST Client Reference (PHP)

## Constructor

```php
use SignalWire\REST\RestClient;

$client = new RestClient(
    project: string,   // SignalWire project ID (required)
    token:   string,   // SignalWire API token (required)
    host:    string,   // Space hostname, e.g. 'example.signalwire.com' (required)
);
```

## Namespaces

All API surfaces are accessed as properties:

| Property | Description |
|----------|-------------|
| `$client->fabric` | Fabric API: AI agents, SWML, subscribers, call flows, etc. |
| `$client->calling` | REST call control commands |
| `$client->phoneNumbers` | Phone number search, purchase, manage |
| `$client->compat` | Twilio-compatible LAML API |
| `$client->video` | Video rooms, sessions, conferences, streams |
| `$client->datasphere` | Document upload and semantic search |
| `$client->registry` | 10DLC brands, campaigns, orders |
| `$client->queues` | Call queue management |
| `$client->recordings` | Call recording access |
| `$client->mfa` | Multi-factor authentication |
| `$client->numberGroups` | Phone number group management |
| `$client->lookup` | Phone number carrier lookup |
| `$client->verifiedCallers` | Verified caller ID management |
| `$client->sipProfile` | SIP profile configuration |
| `$client->shortCodes` | Short code management |
| `$client->addresses` | Regulatory address management |
| `$client->logs` | Call, message, fax, conference logs |
| `$client->projectNs` | Project-level token management |
| `$client->pubsub` | PubSub token creation |
| `$client->chat` | Chat token creation |

## Return Values

All methods return associative arrays (decoded JSON). No wrapper objects:

```php
$agent = $client->fabric->aiAgents->create(
    name: 'Bot',
    prompt: ['text' => 'You are helpful.'],
);

echo $agent['id'];     // Direct access
echo $agent['name'];   // No ->getId() or ->getName()
```

List operations return paginated results:

```php
$numbers = $client->phoneNumbers->list();
foreach (($numbers['data'] ?? []) as $num) {
    echo $num['number'] . "\n";
}
```

## Error Handling

API errors throw `SignalWireRestError`:

```php
use SignalWire\REST\SignalWireRestError;

try {
    $client->fabric->aiAgents->get('nonexistent-id');
} catch (SignalWireRestError $e) {
    echo "HTTP " . $e->getCode() . ": " . $e->getMessage() . "\n";
}
```

## Authentication

The client sends HTTP Basic Authentication on every request using your project ID and API token. Credentials are set once in the constructor.

## HTTP Transport

The `HttpClient` class handles:

- JSON serialization/deserialization
- HTTP Basic Auth headers
- Content-Type headers
- Error response parsing
- cURL-based HTTP requests

## Lazy Loading

Namespace objects are created lazily on first access. No unnecessary connections or allocations happen until you use a namespace.

## Thread Safety

The REST client is stateless per-request and safe to use in concurrent contexts. Each HTTP call is independent.

## Complete Example

```php
<?php
require 'vendor/autoload.php';

use SignalWire\REST\RestClient;
use SignalWire\REST\SignalWireRestError;

$client = new RestClient(
    project: $_ENV['SIGNALWIRE_PROJECT_ID'] ?? die("Set SIGNALWIRE_PROJECT_ID\n"),
    token:   $_ENV['SIGNALWIRE_API_TOKEN']  ?? die("Set SIGNALWIRE_API_TOKEN\n"),
    host:    $_ENV['SIGNALWIRE_SPACE']      ?? die("Set SIGNALWIRE_SPACE\n"),
);

function safe(string $label, callable $fn): mixed
{
    try {
        $result = $fn();
        echo "  {$label}: OK\n";
        return $result;
    } catch (\Exception $e) {
        echo "  {$label}: failed ({$e->getMessage()})\n";
        return null;
    }
}

// Create agent
$agent = safe('Create agent', fn() => $client->fabric->aiAgents->create(
    name: 'Demo Bot',
    prompt: ['text' => 'You are helpful.'],
));

// List numbers
safe('List numbers', fn() => $client->phoneNumbers->list());

// Clean up
if ($agent) {
    safe('Delete agent', fn() => $client->fabric->aiAgents->delete($agent['id']));
}
```
