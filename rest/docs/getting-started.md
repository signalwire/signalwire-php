# REST Getting Started (PHP)

## Installation

```bash
composer require signalwire/signalwire-php
```

## Configuration

Set your SignalWire credentials:

```bash
export SIGNALWIRE_PROJECT_ID=your-project-id
export SIGNALWIRE_API_TOKEN=your-api-token
export SIGNALWIRE_SPACE=example.signalwire.com
```

## Creating the Client

```php
<?php
require 'vendor/autoload.php';

use SignalWire\REST\RestClient;

$client = new RestClient(
    project: $_ENV['SIGNALWIRE_PROJECT_ID'] ?? die("Set SIGNALWIRE_PROJECT_ID\n"),
    token:   $_ENV['SIGNALWIRE_API_TOKEN']  ?? die("Set SIGNALWIRE_API_TOKEN\n"),
    host:    $_ENV['SIGNALWIRE_SPACE']      ?? die("Set SIGNALWIRE_SPACE\n"),
);
```

## First API Call

```php
// List phone numbers
$numbers = $client->phoneNumbers->list();
foreach (($numbers['data'] ?? []) as $num) {
    echo "- " . ($num['number'] ?? 'unknown') . "\n";
}

// Search available numbers
$available = $client->phoneNumbers->search(areaCode: '512', maxResults: 3);
foreach (($available['data'] ?? []) as $num) {
    echo "- " . ($num['e164'] ?? $num['number'] ?? 'unknown') . "\n";
}
```

## Error Handling

All REST methods throw `SignalWireRestError` on failure:

```php
use SignalWire\REST\SignalWireRestError;

try {
    $agent = $client->fabric->aiAgents->create(
        name:   'Test Bot',
        prompt: ['text' => 'You are helpful.'],
    );
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

safe('List agents', fn() => $client->fabric->aiAgents->list());
safe('List numbers', fn() => $client->phoneNumbers->list());
```

## Next Steps

- [Client Reference](client-reference.md) -- all namespaces and methods
- [Fabric Resources](fabric.md) -- AI agents, SWML scripts, subscribers
- [Calling Commands](calling.md) -- REST call control
- [Compatibility API](compat.md) -- Twilio-compatible LAML
- [All Namespaces](namespaces.md) -- phone numbers, video, datasphere, etc.
