# REST Client Reference (PHP)

## Constructor

`RestClient` takes positional `projectId`, `token`, and `space` arguments.
Each falls back to its environment variable (`SIGNALWIRE_PROJECT_ID`,
`SIGNALWIRE_API_TOKEN`, `SIGNALWIRE_SPACE`) when an empty string is passed.

```php
use SignalWire\REST\RestClient;

$client = new RestClient(
    'your-project-id',        // projectId (required)
    'your-api-token',         // token (required)
    'example.signalwire.com', // space hostname or full base URL (required)
);
```

## Namespaces

All API surfaces are accessed as method calls that return the namespace object.
There are 21 namespaces:

| Accessor | Description |
|----------|-------------|
| `$client->fabric()` | Fabric API: AI agents, SWML, subscribers, call flows, etc. |
| `$client->calling()` | REST call control commands |
| `$client->phoneNumbers()` | Phone number search, purchase, manage |
| `$client->video()` | Video rooms, sessions, conferences, streams |
| `$client->datasphere()` | Document upload and semantic search |
| `$client->registry()` | 10DLC brands, campaigns, orders, numbers |
| `$client->queues()` | Call queue management |
| `$client->recordings()` | Call recording access |
| `$client->mfa()` | Multi-factor authentication |
| `$client->numberGroups()` | Phone number group management |
| `$client->lookup()` | Phone number carrier lookup |
| `$client->verifiedCallers()` | Verified caller ID management |
| `$client->sipProfile()` | SIP profile configuration |
| `$client->shortCodes()` | Short code management |
| `$client->addresses()` | Address management |
| `$client->importedNumbers()` | Imported phone numbers |
| `$client->logs()` | Call, message, fax, conference logs |
| `$client->project()` | Project-level token management |
| `$client->pubsub()` | PubSub token creation |
| `$client->chat()` | Chat token creation |

## Return Values

All methods return associative arrays (decoded JSON). No wrapper objects:

```php
$agent = $client->fabric()->aiAgents()->create([
    'name'   => 'Bot',
    'prompt' => ['text' => 'You are helpful.'],
]);

echo $agent['id'];     // Direct access
echo $agent['name'];   // No ->getId() or ->getName()
```

List operations return paginated results:

```php
$numbers = $client->phoneNumbers()->list();
foreach (($numbers['data'] ?? []) as $num) {
    echo $num['number'] . "\n";
}
```

## Error Handling

API errors throw `SignalWireRestError`:

```php
use SignalWire\REST\SignalWireRestError;

try {
    $client->fabric()->aiAgents()->get('nonexistent-id');
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

$client = new RestClient(
    $_ENV['SIGNALWIRE_PROJECT_ID'] ?? '',
    $_ENV['SIGNALWIRE_API_TOKEN']  ?? '',
    $_ENV['SIGNALWIRE_SPACE']      ?? '',
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
$agent = safe('Create agent', fn() => $client->fabric()->aiAgents()->create([
    'name'   => 'Demo Bot',
    'prompt' => ['text' => 'You are helpful.'],
]));

// List numbers
safe('List numbers', fn() => $client->phoneNumbers()->list());

// Clean up
if ($agent) {
    safe('Delete agent', fn() => $client->fabric()->aiAgents()->delete($agent['id']));
}
```
