# RELAY Messaging Guide (PHP)

## Overview

The RELAY client supports SMS and MMS messaging. You can send outbound messages, receive inbound messages, and track delivery state.

## Sending Messages

### Basic SMS

`sendMessage()` takes a single params array and returns a `Message` tracking
object.

<!-- snippet: no-run connect() opens a live WebSocket to SIGNALWIRE_SPACE and sendMessage() places a real send over it — cannot reach the loopback mock standalone -->
```php
<?php
require 'vendor/autoload.php';

use SignalWire\Relay\Client;

$client = new Client([
    'project'  => $_ENV['SIGNALWIRE_PROJECT_ID'],
    'token'    => $_ENV['SIGNALWIRE_API_TOKEN'],
    'host'     => $_ENV['SIGNALWIRE_SPACE'] ?? 'relay.signalwire.com',
    'contexts' => ['default'],
]);

$client->connect();

$message = $client->sendMessage([
    'from_number' => '+15559876543',
    'to_number'   => '+15551234567',
    'context'     => 'default',
    'body'        => 'Hello from SignalWire PHP!',
]);

echo "Message ID: " . $message->getMessageId() . "\n";
echo "State: "      . $message->getState()     . "\n";

$client->disconnect();
```

### MMS with Media

```php
$message = $client->sendMessage([
    'from_number' => '+15559876543',
    'to_number'   => '+15551234567',
    'context'     => 'default',
    'body'        => 'Check out this photo!',
    'media'       => ['https://example.com/photo.jpg'],
]);
```

## Receiving Messages

Register a message handler to process inbound SMS/MMS:

```php
$client->onMessage(function ($message) {
    echo "From: " . $message->getFromNumber() . "\n";
    echo "To: "   . $message->getToNumber()   . "\n";
    echo "Body: " . $message->getBody()       . "\n";
    echo "State: ". $message->getState()      . "\n";

    // Access media attachments
    foreach ($message->getMedia() as $url) {
        echo "Media: {$url}\n";
    }
});

$client->connect();
echo "Waiting for inbound messages...\n";
$client->run();
```

## Message Object

`SignalWire\Relay\Message` provides:

| Method | Description |
|--------|-------------|
| `$msg->getMessageId()` | Unique message identifier |
| `$msg->getFromNumber()` | Sender number |
| `$msg->getToNumber()` | Recipient number |
| `$msg->getBody()` | Message text |
| `$msg->getMedia()` | Array of media URLs |
| `$msg->getState()` | Current delivery state |
| `$msg->getDirection()` | `inbound` or `outbound` |
| `$msg->getContext()` | Context the message was received on |
| `$msg->getTags()` | Message tags |

## Delivery States

| State | Description |
|-------|-------------|
| `received` | Inbound message received |
| `queued` | Outbound message queued for delivery |
| `sent` | Outbound message sent to carrier |
| `delivered` | Outbound message confirmed delivered |
| `undelivered` | Outbound message could not be delivered |
| `failed` | Message processing failed |

## Tracking Delivery

Outbound messages progress through states. The `sendMessage()` result reflects the initial state:

```php
$message = $client->sendMessage([
    'from_number' => '+15559876543',
    'to_number'   => '+15551234567',
    'context'     => 'default',
    'body'        => 'Order confirmed.',
]);

echo "Initial state: " . $message->getState() . "\n";
// Typically "queued" immediately after send
```

## Message Contexts

Messages are routed to your handler based on context, similar to calls:

```php
$client = new Client([
    'project'  => $_ENV['SIGNALWIRE_PROJECT_ID'],
    'token'    => $_ENV['SIGNALWIRE_API_TOKEN'],
    'contexts' => ['default', 'notifications'],
]);
```

## Best Practices

1. Register message handlers before calling `$client->run()`
2. Use separate contexts for different message types
3. Handle media attachments when processing MMS
4. Check message state for delivery confirmation
5. Use environment variables for phone numbers in production
