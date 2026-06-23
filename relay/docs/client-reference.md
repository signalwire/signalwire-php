# RELAY Client Reference (PHP)

## Constructor

The `Client` constructor takes a single options array (not named scalar
arguments).

```php
use SignalWire\Relay\Client;

$client = new Client([
    'project'  => 'your-project-id',  // SignalWire project ID (required unless jwt_token)
    'token'    => 'your-api-token',   // SignalWire API token (required unless jwt_token)
    'host'     => 'example.signalwire.com', // Space hostname (or SIGNALWIRE_SPACE)
    'contexts' => ['default'],        // Call/message contexts to subscribe to
    // 'jwt_token' => '...',          // Alternative: authenticate with a JWT
    // 'scheme'    => 'wss',          // Transport scheme (default 'wss')
    // 'ca_file'   => '/path/ca.pem', // CA bundle for wss:// peer verification
]);
```

Construction throws `\InvalidArgumentException` unless either `project` + `token`
or a non-empty `jwt_token` is supplied.

## Connection Methods

| Method | Description |
|--------|-------------|
| `connect(): void` | Open the WebSocket and run the `signalwire.connect` authentication handshake. Throws on transport or auth failure. |
| `disconnect(): void` | Close the WebSocket connection gracefully. |
| `run(): void` | Enter the event loop. Blocks until disconnected; auto-reconnects on transport errors. |
| `readOnce(): void` | Read and process a single WebSocket frame. |
| `receive(array $contexts): void` | Subscribe to additional inbound contexts. |
| `unreceive(array $contexts): void` | Unsubscribe from contexts. |

### Connection Flow

`connect()` performs both the transport handshake and authentication, so no
separate `authenticate()` call is needed.

```php
$client->connect();
echo "Protocol: " . $client->protocol . "\n"; // public property
$client->run(); // Blocks here
```

## Event Registration

| Method | Description |
|--------|-------------|
| `onCall(callable $callback): self` | Register handler for inbound calls |
| `onMessage(callable $callback): self` | Register handler for inbound messages |

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
    echo "Message from " . $message->getFromNumber() . ": " . $message->getBody() . "\n";
});
```

## Outbound Operations

### dial()

Place an outbound call. Signature: `dial(array $devices, array $opts = []): Call`.

```php
$call = $client->dial(
    [[
        ['type' => 'phone', 'params' => [
            'to_number'   => '+15551234567',
            'from_number' => '+15559876543',
        ]],
    ]],
    ['dial_timeout' => 30.0],
);
```

`$devices` is a nested array: the outer array holds parallel "legs", each inner
array holds serial steps within a leg. Recognised `$opts` keys: `tag`,
`dial_timeout` (seconds, default 30.0), `max_duration`; any other key is
forwarded as a top-level dial param.

### sendMessage()

Send an outbound SMS/MMS. Signature: `sendMessage(array $params): Message`.

```php
$message = $client->sendMessage([
    'from_number' => '+15559876543',
    'to_number'   => '+15551234567',
    'context'     => 'default',
    'body'        => 'Hello!',
    'media'       => [],  // Optional MMS media URLs
]);

echo "Message ID: " . $message->getMessageId() . "\n";
echo "State: "      . $message->getState()     . "\n";
```

## Properties

| Property | Description |
|----------|-------------|
| `$client->protocol` | Negotiated protocol identifier after auth (nullable) |
| `$client->sessionId` | Server-assigned session id after auth (nullable) |
| `$client->connected` | Whether the WebSocket is currently connected |
| `$client->contexts` | Subscribed contexts |

## Auto-Reconnect

The client automatically reconnects with exponential backoff if the WebSocket
connection drops. No manual intervention is required. Reconnection behavior:

1. First retry after 1 second
2. Subsequent retries: exponential backoff up to 30 seconds
3. Re-authentication happens automatically after reconnect
4. Context subscriptions are restored

## Error Handling

`connect()` throws on failure rather than returning a status flag:

```php
try {
    $client->connect();
} catch (\Throwable $e) {
    echo "Failed to connect: " . $e->getMessage() . "\n";
    exit(1);
}
```

## Thread Safety

The RELAY client is single-threaded. The `run()` method processes events
sequentially. Long-running operations in callbacks block event processing.
Keep handlers fast or offload work to background processes.

## Full Example

```php
<?php
require 'vendor/autoload.php';

use SignalWire\Relay\Client;

$client = new Client([
    'project'  => $_ENV['SIGNALWIRE_PROJECT_ID'] ?? die("Set SIGNALWIRE_PROJECT_ID\n"),
    'token'    => $_ENV['SIGNALWIRE_API_TOKEN']  ?? die("Set SIGNALWIRE_API_TOKEN\n"),
    'host'     => $_ENV['SIGNALWIRE_SPACE']      ?? 'relay.signalwire.com',
    'contexts' => ['default'],
]);

$client->onCall(function ($call) {
    $call->answer();
    $action = $call->play([
        ['type' => 'tts', 'params' => ['text' => 'Welcome!']],
    ]);
    $action->wait();
    $call->hangup();
});

$client->onMessage(function ($msg) {
    echo "SMS from " . $msg->getFromNumber() . ": " . $msg->getBody() . "\n";
});

$client->connect();
echo "Listening for calls and messages...\n";
$client->run();
```
