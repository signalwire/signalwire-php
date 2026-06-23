# Call Methods Reference (PHP)

## Overview

The `SignalWire\Relay\Call` object provides methods for controlling a live call. Media methods return `Action` objects for controllable operations.

## Call Properties

State and metadata are exposed as public properties, not getter methods.

| Property | Type | Description |
|----------|------|-------------|
| `$call->callId` | ?string | Unique call identifier |
| `$call->state` | string | Current call state |
| `$call->device` | array | Device information (to/from numbers) |
| `$call->direction` | ?string | `inbound` or `outbound` |
| `$call->context` | ?string | Context the call arrived on |

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

Play audio or TTS. Signature: `play(array $media, array $opts = []): PlayAction`.

```php
// TTS
$action = $call->play([
    ['type' => 'tts', 'params' => ['text' => 'Hello!']],
]);
$action->wait();

// Audio file
$action = $call->play([
    ['type' => 'audio', 'params' => ['url' => 'https://example.com/audio.mp3']],
]);
$action->wait();

// Silence
$action = $call->play([
    ['type' => 'silence', 'params' => ['duration' => 2]],
]);
```

Convenience helpers are also available: `playTts(string $text, array $opts = [])`,
`playAudio(string $url, array $opts = [])`, `playSilence(int|float $duration, array $opts = [])`,
and `playRingtone(string $name, array $opts = [])`.

### record()

Record the call. Signature: `record(array $audio, array $opts = []): RecordAction`.

```php
$action = $call->record(['format' => 'mp3', 'beep' => true, 'direction' => 'both']);
$action->wait();
$url = $action->getUrl(); // Recording URL
```

### playAndCollect()

Play audio and collect DTMF or speech input. Signature:
`playAndCollect(array $media, array $collect, array $opts = []): CollectAction`.

```php
$action = $call->playAndCollect(
    [
        ['type' => 'tts', 'params' => ['text' => 'Press 1 for sales, 2 for support.']],
    ],
    [
        'digits' => ['max' => 1, 'digit_timeout' => 5.0],
        'initial_timeout' => 10.0,
    ],
);

$action->wait();
$digits = $action->getCollectResult()['params']['digits'] ?? '';
```

## Call Connection

### connect()

Connect (bridge) the call to another party. Signature: `connect(array $params): array`.

```php
$call->connect([
    'devices' => [[
        ['type' => 'phone', 'params' => [
            'to_number'   => '+15551234567',
            'from_number' => '+15559876543',
            'timeout'     => 30,
        ]],
    ]],
    'ringback' => [
        ['type' => 'tts', 'params' => ['text' => 'Please wait.']],
    ],
]);
```

### disconnect()

Disconnect a bridged call.

```php
$call->disconnect();
```

## Detection

### detect()

Run detection (machine, fax, digit). Signature: `detect(array $detect, array $opts = []): DetectAction`.

```php
$action = $call->detect(['type' => 'machine'], ['timeout' => 30]);
$action->wait();
$result = $action->getDetectResult();
```

Convenience helpers: `detectDigit(array $opts = [])`,
`detectAnsweringMachine(array $opts = [])`, `detectFax(array $opts = [])`.

## Fax

### sendFax()

Signature: `sendFax(string $document, ?string $identity = null, array $opts = []): FaxAction`.

```php
$action = $call->sendFax('https://example.com/invoice.pdf');
$action->wait();
```

### receiveFax()

Signature: `receiveFax(array $opts = []): FaxAction`.

```php
$action = $call->receiveFax();
$event  = $action->wait();
$faxUrl = $event->getParams()['fax']['document'] ?? '';
```

## Tap

### tap()

Fork call media to a WebSocket or RTP endpoint. Signature:
`tap(array $tap, array $device, array $opts = []): TapAction`.

```php
$action = $call->tap(
    ['type' => 'audio', 'params' => ['direction' => 'both']],
    ['type' => 'ws', 'params' => ['uri' => 'wss://example.com/tap']],
);
```

### tap stop

Stop a running tap with `$action->stop()`.

## Action Objects

All media methods return `Action` subclasses:

| Method | Description |
|--------|-------------|
| `$action->wait($timeout)` | Block until completion or timeout; returns the completion `Event` (or `null`) |
| `$action->stop()` | Stop the operation |
| `$action->pause()` | Pause (play, record) |
| `$action->resume()` | Resume (play, record) |
| `$action->isDone()` | Whether the operation has completed |
| `$action->getUrl()` | Get result URL (record) |
| `$action->getResult()` | Get the raw result payload |

## Call States

| State | Description |
|-------|-------------|
| `created` | Call object created |
| `ringing` | Call is ringing |
| `answered` | Call has been answered |
| `ending` | Call is being terminated |
| `ended` | Call has ended |
