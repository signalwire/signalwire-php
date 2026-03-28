# Call Methods Reference (PHP)

## Overview

The `SignalWire\Relay\Call` object provides methods for controlling a live call. Methods return `Action` objects for controllable operations.

## Call Properties

| Property | Type | Description |
|----------|------|-------------|
| `$call->callId()` | string | Unique call identifier |
| `$call->state()` | string | Current call state |
| `$call->device()` | array | Device information (to/from numbers) |
| `$call->direction()` | string | `inbound` or `outbound` |

## Basic Control

### answer()

Answer an inbound call.

```php
$call->answer();
```

### hangup()

End the call.

```php
$call->hangup();
```

## Media

### play()

Play audio or TTS. Returns an `Action`.

```php
// TTS
$action = $call->play(media: [
    ['type' => 'tts', 'params' => ['text' => 'Hello!']],
]);
$action->wait();

// Audio file
$action = $call->play(media: [
    ['type' => 'audio', 'params' => ['url' => 'https://example.com/audio.mp3']],
]);
$action->wait();

// Silence
$action = $call->play(media: [
    ['type' => 'silence', 'params' => ['duration' => 2]],
]);
```

### record()

Record the call. Returns an `Action`.

```php
$action = $call->record(beep: true, format: 'mp3', direction: 'both');
$action->wait();
$url = $action->url(); // Recording URL
```

### playAndCollect()

Play audio and collect DTMF or speech input.

```php
$action = $call->playAndCollect(
    media: [
        ['type' => 'tts', 'params' => ['text' => 'Press 1 for sales, 2 for support.']],
    ],
    collect: [
        'digits' => ['max' => 1, 'digit_timeout' => 5.0],
        'initial_timeout' => 10.0,
    ],
);

$result = $action->wait();
$digits = $result->params()['result']['params']['digits'] ?? '';
```

## Call Connection

### connect()

Connect the call to another party (bridge).

```php
$call->connect(
    devices: [[
        ['type' => 'phone', 'params' => [
            'to_number'   => '+15551234567',
            'from_number' => '+15559876543',
            'timeout'     => 30,
        ]],
    ]],
    ringback: [
        ['type' => 'tts', 'params' => ['text' => 'Please wait.']],
    ],
);
```

### disconnect()

Disconnect a bridged call.

```php
$call->disconnect();
```

## Detection

### detect()

Run detection (machine, fax, digit).

```php
$action = $call->detect(type: 'machine', timeout: 30);
$result = $action->wait();
```

### detectStop()

Stop a running detection.

```php
$call->detectStop();
```

## Fax

### sendFax()

```php
$action = $call->sendFax(document: 'https://example.com/invoice.pdf');
$action->wait();
```

### receiveFax()

```php
$action = $call->receiveFax();
$result = $action->wait();
$faxUrl = $result->params()['fax']['document'] ?? '';
```

## Tap

### tap()

Fork call media to a WebSocket or RTP endpoint.

```php
$action = $call->tap(
    tap:    ['type' => 'audio', 'params' => ['direction' => 'both']],
    device: ['type' => 'ws', 'params' => ['uri' => 'wss://example.com/tap']],
);
```

### tapStop()

```php
$call->tapStop();
```

## Action Objects

All asynchronous methods return `Action` objects:

| Method | Description |
|--------|-------------|
| `$action->wait($timeout)` | Block until completion or timeout |
| `$action->stop()` | Stop the operation |
| `$action->pause()` | Pause (play, record) |
| `$action->resume()` | Resume (play, record) |
| `$action->completed()` | Check if completed |
| `$action->url()` | Get result URL (record) |

## Call States

| State | Description |
|-------|-------------|
| `created` | Call object created |
| `ringing` | Call is ringing |
| `answered` | Call has been answered |
| `ending` | Call is being terminated |
| `ended` | Call has ended |
