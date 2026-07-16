# REST Getting Started (PHP)

## Installation

```bash
composer require signalwire/sdk
```

## Configuration

Set your SignalWire credentials:

```bash
export SIGNALWIRE_PROJECT_ID=your-project-id
export SIGNALWIRE_API_TOKEN=your-api-token
export SIGNALWIRE_SPACE=example.signalwire.com
```

## Creating the Client

The `RestClient` constructor takes positional `projectId`, `token`, and `space`
arguments (each falls back to the matching environment variable when empty).

```php
<?php
require 'vendor/autoload.php';

use SignalWire\REST\RestClient;

$client = new RestClient(
    $_ENV['SIGNALWIRE_PROJECT_ID'] ?? '',
    $_ENV['SIGNALWIRE_API_TOKEN']  ?? '',
    $_ENV['SIGNALWIRE_SPACE']      ?? '',
);
```

## First API Call

Namespaces are accessed via method calls (`$client->phoneNumbers()`), and
`create`/`search`/`update` take a single associative array.

```php
// List phone numbers
$numbers = $client->phoneNumbers()->list();
foreach (($numbers['data'] ?? []) as $num) {
    echo "- " . ($num['number'] ?? 'unknown') . "\n";
}

// Search available numbers
$available = $client->phoneNumbers()->search(['areacode' => '512', 'max_results' => 3]);
foreach (($available['data'] ?? []) as $num) {
    echo "- " . ($num['e164'] ?? $num['number'] ?? 'unknown') . "\n";
}
```

## Error Handling

All REST methods throw `SignalWireRestError` on failure:

```php
use SignalWire\REST\SignalWireRestError;

try {
    $agent = $client->fabric()->aiAgents()->create([
        'name'   => 'Test Bot',
        'prompt' => ['text' => 'You are helpful.'],
    ]);
    echo "Created agent: " . $agent['id'] . "\n";
} catch (SignalWireRestError $e) {
    echo "API error: " . $e->getMessage() . "\n";
}
```

## Helper Pattern

For scripts that call many APIs, use a safe() helper:

```php
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

safe('List agents', fn() => $client->fabric()->aiAgents()->list());
safe('List numbers', fn() => $client->phoneNumbers()->list());
```

## Next Steps

- [Client Reference](client-reference.md) -- all namespaces and methods
- [Fabric Resources](fabric.md) -- AI agents, SWML scripts, subscribers
- [Calling Commands](calling.md) -- REST call control
- [All Namespaces](namespaces.md) -- phone numbers, video, datasphere, etc.
