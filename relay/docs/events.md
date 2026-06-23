# RELAY Events Reference (PHP)

## Overview

The RELAY client dispatches typed events for call state changes, media events, and messaging. Events are delivered through callbacks registered on the client or call objects.

## Call Events

### Registering Call Handlers

```php
$client->onCall(function ($call) {
    echo "New call: " . $call->callId . "\n";
    // Handle the call...
});
```

### Call State Events

The current state is the public `$call->state` property.

| State | Description |
|-------|-------------|
| `created` | Call object created, not yet ringing |
| `ringing` | Call is ringing at the destination |
| `answered` | Remote party answered |
| `ending` | Call is being terminated |
| `ended` | Call has fully ended |

### Monitoring State Changes

```php
$client->onCall(function ($call) use ($client) {
    $call->answer();

    // Play audio
    $action = $call->play([
        ['type' => 'tts', 'params' => ['text' => 'Hello!']],
    ]);
    $action->wait();

    $call->hangup();

    // Wait for ended state
    while ($call->state !== 'ended') {
        $client->readOnce();
    }
    echo "Call fully ended.\n";
});
```

## Event Objects

`SignalWire\Relay\Event` provides typed access to event data:

| Method | Description |
|--------|-------------|
| `$event->getEventType()` | Event type string |
| `$event->getParams()` | Event parameters as array |
| `$event->getCallId()` | Call ID associated with event |
| `$event->getControlId()` | Control ID of the originating action |
| `$event->getState()` | State carried by the event (if any) |

## Action Events

Actions from `play()`, `record()`, `playAndCollect()`, etc. produce completion
events. `$action->wait()` returns the completion `Event` (or `null` on timeout).

```php
$action = $call->play([
    ['type' => 'tts', 'params' => ['text' => 'Testing']],
]);

$event = $action->wait();
$resultType = $event->getParams()['result']['type'] ?? '';
echo "Play result: {$resultType}\n";
```

### Collect Events

```php
$action = $call->playAndCollect(
    [['type' => 'tts', 'params' => ['text' => 'Enter your PIN.']]],
    ['digits' => ['max' => 4, 'digit_timeout' => 5.0]],
);

$result = $action->getCollectResult() ?? [];
$type   = $result['type'] ?? '';
$digits = ($result['params'] ?? [])['digits'] ?? '';
echo "Collected: type={$type} digits={$digits}\n";
```

### Record Events

```php
$action = $call->record(['beep' => true, 'format' => 'mp3']);
$action->wait();
$url = $action->getUrl();
echo "Recording saved at: {$url}\n";
```

### Detect Events

```php
$action = $call->detect(['type' => 'machine'], ['timeout' => 30]);
$action->wait();
$detected = $action->getDetectResult()['type'] ?? '';
echo "Detected: {$detected}\n";
```

## Message Events

For inbound messaging:

```php
$client->onMessage(function ($message) {
    echo "From: " . $message->getFromNumber() . "\n";
    echo "Body: " . $message->getBody()       . "\n";
    echo "State: ". $message->getState()      . "\n";
});
```

### Message States

| State | Description |
|-------|-------------|
| `received` | Inbound message received |
| `queued` | Outbound message queued |
| `sent` | Outbound message sent |
| `delivered` | Outbound message delivered |
| `undelivered` | Outbound message could not be delivered |
| `failed` | Message failed |

## Event Constants

Call and message state constants are defined in `SignalWire\Relay\Constants`:

```php
use SignalWire\Relay\Constants;

// Constants::CALL_STATE_ANSWERED    === 'answered'
// Constants::CALL_STATE_ENDED       === 'ended'
// Constants::MESSAGE_STATE_RECEIVED === 'received'
// Constants::MESSAGE_STATE_DELIVERED === 'delivered'
```

## Error Events

Connection and protocol errors are logged and trigger reconnection. Set
`SIGNALWIRE_LOG_LEVEL=debug` to see all WebSocket traffic.

## Best Practices

1. Always check `$call->state` before performing operations
2. Use `$action->wait()` to synchronize with async operations
3. Handle the `ended` state to clean up resources
4. Register message handlers before calling `$client->run()`
