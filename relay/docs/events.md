# RELAY Events Reference (PHP)

## Overview

The RELAY client dispatches typed events for call state changes, media events, and messaging. Events are delivered through callbacks registered on the client or call objects.

## Call Events

### Registering Call Handlers

```php
$client->onCall(function ($call) {
    echo "New call: " . $call->callId() . "\n";
    // Handle the call...
});
```

### Call State Events

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
    $action = $call->play(media: [
        ['type' => 'tts', 'params' => ['text' => 'Hello!']],
    ]);
    $action->wait();

    $call->hangup();

    // Wait for ended state
    while ($call->state() !== 'ended') {
        $client->readOnce();
    }
    echo "Call fully ended.\n";
});
```

## Event Objects

`SignalWire\Relay\Event` provides typed access to event data:

| Method | Description |
|--------|-------------|
| `$event->type()` | Event type string |
| `$event->params()` | Event parameters as array |
| `$event->callId()` | Call ID associated with event |

## Action Events

Actions from `play()`, `record()`, `playAndCollect()`, etc. produce completion events:

```php
$action = $call->play(media: [
    ['type' => 'tts', 'params' => ['text' => 'Testing']],
]);

$event = $action->wait();
$resultType = $event->params()['result']['type'] ?? '';
echo "Play result: {$resultType}\n";
```

### Collect Events

```php
$action = $call->playAndCollect(
    media: [['type' => 'tts', 'params' => ['text' => 'Enter your PIN.']]],
    collect: ['digits' => ['max' => 4, 'digit_timeout' => 5.0]],
);

$event = $action->wait();
$result = $event->params()['result'] ?? [];
$type   = $result['type'] ?? '';
$digits = ($result['params'] ?? [])['digits'] ?? '';
echo "Collected: type={$type} digits={$digits}\n";
```

### Record Events

```php
$action = $call->record(beep: true, format: 'mp3');
$event = $action->wait();
$url = $action->url();
echo "Recording saved at: {$url}\n";
```

### Detect Events

```php
$action = $call->detect(type: 'machine', timeout: 30);
$event = $action->wait();
$detected = $event->params()['detect']['type'] ?? '';
echo "Detected: {$detected}\n";
```

## Message Events

For inbound messaging:

```php
$client->onMessage(function ($message) {
    echo "From: " . $message->from() . "\n";
    echo "Body: " . $message->body() . "\n";
    echo "State: " . $message->state() . "\n";
});
```

### Message States

| State | Description |
|-------|-------------|
| `received` | Inbound message received |
| `queued` | Outbound message queued |
| `initiated` | Outbound message initiated |
| `sent` | Outbound message sent |
| `delivered` | Outbound message delivered |
| `undelivered` | Outbound message failed |
| `failed` | Message failed |

## Event Constants

Event type constants are defined in `SignalWire\Relay\Constants`:

```php
use SignalWire\Relay\Constants;

// Constants::CALL_STATE_ANSWERED
// Constants::CALL_STATE_ENDED
// Constants::EVENT_CALL_RECEIVED
// Constants::EVENT_MESSAGE_RECEIVED
```

## Error Events

Connection and protocol errors are logged and trigger reconnection:

```php
// Set debug logging to see all events
// export SIGNALWIRE_LOG_LEVEL=debug
```

## Best Practices

1. Always check `$call->state()` before performing operations
2. Use `$action->wait()` to synchronize with async operations
3. Handle the `ended` state to clean up resources
4. Register message handlers before calling `$client->run()`
