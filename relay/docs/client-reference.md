# RELAY Client Reference (PHP)

## Constructor

```php
use SignalWire\Relay\Client;

$client = new Client(
    project:  string,       // SignalWire project ID (required)
    token:    string,       // SignalWire API token (required)
    host:     string,       // Space hostname (default: 'relay.signalwire.com')
    contexts: array,        // Call/message contexts to subscribe to
);
```

## Connection Methods

| Method | Description |
|--------|-------------|
| `connectWs(): bool` | Establish WebSocket connection. Returns true on success. |
| `authenticate(): void` | Authenticate with project credentials via Blade protocol. |
| `disconnectWs(): void` | Close WebSocket connection. |
| `run(): void` | Enter the event loop. Blocks until interrupted (Ctrl+C). |
| `readOnce(): void` | Read and process a single WebSocket message. |

### Connection Flow

```php
$client->connectWs() or die("Connection failed\n");
$client->authenticate();
echo "Protocol: " . $client->protocol() . "\n";
$client->run(); // Blocks here
```

## Event Registration

| Method | Description |
|--------|-------------|
| `onCall(callable $callback): void` | Register handler for inbound calls |
| `onMessage(callable $callback): void` | Register handler for inbound messages |

### Call Handler

```php
$client->onCall(function (SignalWire\Relay\Call $call) {
    $call->answer();
    // ... handle the call
    $call->hangup();
});
```

### Message Handler

```php
$client->onMessage(function (SignalWire\Relay\Message $message) {
    echo "Message from " . $message->from() . ": " . $message->body() . "\n";
});
```

## Outbound Operations

### dial()

Place an outbound call.

```php
$call = $client->dial(
    devices: [[
        ['type' => 'phone', 'params' => [
            'to_number'   => '+15551234567',
            'from_number' => '+15559876543',
        ]],
    ]],
    timeout: 30,
);
```

`devices` is a nested array: outer array is serial attempts, inner array is parallel attempts.

### sendMessage()

Send an outbound SMS/MMS.

```php
$result = $client->sendMessage(
    from:    '+15559876543',
    to:      '+15551234567',
    context: 'default',
    body:    'Hello!',
    media:   [],  // Optional MMS media URLs
);
```

## Properties

| Property | Description |
|----------|-------------|
| `$client->protocol()` | Blade protocol identifier after auth |

## Auto-Reconnect

The client automatically reconnects with exponential backoff if the WebSocket connection drops. No manual intervention is required. Reconnection behavior:

1. First retry: immediate
2. Subsequent retries: exponential backoff up to 30 seconds
3. Re-authentication happens automatically after reconnect
4. Context subscriptions are restored

## Error Handling

```php
$connected = $client->connectWs();
if (!$connected) {
    echo "Failed to connect to " . $_ENV['SIGNALWIRE_SPACE'] . "\n";
    exit(1);
}

try {
    $client->authenticate();
} catch (\Exception $e) {
    echo "Authentication failed: " . $e->getMessage() . "\n";
    exit(1);
}
```

## Thread Safety

The RELAY client is single-threaded. The `run()` method processes events sequentially. Long-running operations in callbacks block event processing. Keep handlers fast or offload work to background processes.

## Full Example

```php
<?php
require 'vendor/autoload.php';

use SignalWire\Relay\Client;

$client = new Client(
    project:  $_ENV['SIGNALWIRE_PROJECT_ID'] ?? die("Set SIGNALWIRE_PROJECT_ID\n"),
    token:    $_ENV['SIGNALWIRE_API_TOKEN']  ?? die("Set SIGNALWIRE_API_TOKEN\n"),
    host:     $_ENV['SIGNALWIRE_SPACE']      ?? 'relay.signalwire.com',
    contexts: ['default'],
);

$client->onCall(function ($call) {
    $call->answer();
    $action = $call->play(media: [
        ['type' => 'tts', 'params' => ['text' => 'Welcome!']],
    ]);
    $action->wait();
    $call->hangup();
});

$client->onMessage(function ($msg) {
    echo "SMS from " . $msg->from() . ": " . $msg->body() . "\n";
});

$client->connectWs() or die("Connection failed\n");
$client->authenticate();
echo "Listening for calls and messages...\n";
$client->run();
```
