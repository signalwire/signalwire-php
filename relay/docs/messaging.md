# RELAY Messaging Guide (PHP)

## Overview

The RELAY client supports SMS and MMS messaging. You can send outbound messages, receive inbound messages, and track delivery state.

## Sending Messages

### Basic SMS

```php
<?php
require 'vendor/autoload.php';

use SignalWire\Relay\Client;

$client = new Client(
    project:  $_ENV['SIGNALWIRE_PROJECT_ID'],
    token:    $_ENV['SIGNALWIRE_API_TOKEN'],
    host:     $_ENV['SIGNALWIRE_SPACE'] ?? 'relay.signalwire.com',
    contexts: ['default'],
);

$client->connectWs() or die("Connection failed\n");
$client->authenticate();

$result = $client->sendMessage(
    from:    '+15559876543',
    to:      '+15551234567',
    context: 'default',
    body:    'Hello from SignalWire PHP!',
);

echo "Message ID: " . $result->messageId() . "\n";
echo "State: " . $result->state() . "\n";

$client->disconnectWs();
```

### MMS with Media

```php
$result = $client->sendMessage(
    from:    '+15559876543',
    to:      '+15551234567',
    context: 'default',
    body:    'Check out this photo!',
    media:   ['https://example.com/photo.jpg'],
);
```

## Receiving Messages

Register a message handler to process inbound SMS/MMS:

```php
$client->onMessage(function ($message) {
    echo "From: " . $message->from() . "\n";
    echo "To: " . $message->to() . "\n";
    echo "Body: " . $message->body() . "\n";
    echo "State: " . $message->state() . "\n";

    // Access media attachments
    $media = $message->media();
    foreach ($media as $url) {
        echo "Media: {$url}\n";
    }
});

$client->connectWs() or die("Connection failed\n");
$client->authenticate();
echo "Waiting for inbound messages...\n";
$client->run();
```

## Message Object

`SignalWire\Relay\Message` provides:

| Method | Description |
|--------|-------------|
| `$msg->messageId()` | Unique message identifier |
| `$msg->from()` | Sender number |
| `$msg->to()` | Recipient number |
| `$msg->body()` | Message text |
| `$msg->media()` | Array of media URLs |
| `$msg->state()` | Current delivery state |
| `$msg->direction()` | `inbound` or `outbound` |
| `$msg->context()` | Context the message was received on |

## Delivery States

| State | Description |
|-------|-------------|
| `received` | Inbound message received |
| `queued` | Outbound message queued for delivery |
| `initiated` | Outbound message initiated |
| `sent` | Outbound message sent to carrier |
| `delivered` | Outbound message confirmed delivered |
| `undelivered` | Outbound message could not be delivered |
| `failed` | Message processing failed |

## Tracking Delivery

Outbound messages progress through states. The `sendMessage()` result reflects the initial state:

```php
$result = $client->sendMessage(
    from:    '+15559876543',
    to:      '+15551234567',
    context: 'default',
    body:    'Order confirmed.',
);

echo "Initial state: " . $result->state() . "\n";
// Typically "queued" immediately after send
```

## Message Contexts

Messages are routed to your handler based on context, similar to calls:

```php
$client = new Client(
    project:  $_ENV['SIGNALWIRE_PROJECT_ID'],
    token:    $_ENV['SIGNALWIRE_API_TOKEN'],
    contexts: ['default', 'notifications'],
);
```

## Best Practices

1. Register message handlers before calling `$client->run()`
2. Use separate contexts for different message types
3. Handle media attachments when processing MMS
4. Check message state for delivery confirmation
5. Use environment variables for phone numbers in production
