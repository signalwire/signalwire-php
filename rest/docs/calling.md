# REST Calling Commands (PHP)

## Overview

The `$client->calling` namespace provides REST-based call control: dial, play, record, collect, detect, AI, transcribe, tap, stream, and more. These commands operate on active calls identified by `$callId`.

## Placing a Call

```php
$call = $client->calling->dial(
    from: '+15559876543',
    to:   '+15551234567',
    url:  'https://example.com/call-handler',
);
$callId = $call['id'];
```

## Media Playback

```php
// Play TTS
$client->calling->play($callId,
    play: [['type' => 'tts', 'text' => 'Welcome to SignalWire.']],
);

// Playback control
$client->calling->playPause($callId);
$client->calling->playResume($callId);
$client->calling->playVolume($callId, volume: 2.0);
$client->calling->playStop($callId);
```

## Recording

```php
// Start recording
$client->calling->record($callId, beep: true, format: 'mp3');

// Recording control
$client->calling->recordPause($callId);
$client->calling->recordResume($callId);
$client->calling->recordStop($callId);
```

## Input Collection

```php
// Collect DTMF
$client->calling->collect($callId,
    digits: ['max' => 4, 'terminators' => '#'],
    play:   [['type' => 'tts', 'text' => 'Enter your PIN followed by pound.']],
);

$client->calling->collectStartInputTimers($callId);
$client->calling->collectStop($callId);
```

## Detection

```php
// Answering machine detection
$client->calling->detect($callId, type: 'machine');
$client->calling->detectStop($callId);
```

## Transcription

```php
$client->calling->transcribe($callId, language: 'en-US');
$client->calling->transcribeStop($callId);
```

## Live Transcription and Translation

```php
$client->calling->liveTranscribe($callId, language: 'en-US');
$client->calling->liveTranslate($callId, language: 'es');
```

## AI Operations

```php
$client->calling->aiMessage($callId, message: 'Customer wants to check their balance.');
$client->calling->aiHold($callId);
$client->calling->aiUnhold($callId);
$client->calling->aiStop($callId);
```

## Denoise

```php
$client->calling->denoise($callId);
$client->calling->denoiseStop($callId);
```

## Tap (Media Fork)

```php
$client->calling->tap($callId,
    tap:    ['type' => 'audio', 'direction' => 'both'],
    device: ['type' => 'rtp', 'addr' => '192.168.1.100', 'port' => 9000],
);
$client->calling->tapStop($callId);
```

## Stream (WebSocket)

```php
$client->calling->stream($callId, url: 'wss://example.com/audio-stream');
$client->calling->streamStop($callId);
```

## Transfer and Connection

```php
$client->calling->transfer($callId, dest: '+15559999999');
$client->calling->connect($callId,
    devices: [['type' => 'phone', 'params' => ['to_number' => '+15551234567']]],
);
$client->calling->disconnect($callId);
```

## SIP Refer

```php
$client->calling->refer($callId, sipUri: 'sip:support@example.com');
```

## User Events

```php
$client->calling->userEvent($callId,
    eventName: 'agent_note',
    data:      ['note' => 'VIP caller'],
);
```

## Call Management

```php
$client->calling->update(callId: $callId, metadata: ['priority' => 'high']);
$client->calling->end($callId, reason: 'hangup');
```

## Fax

```php
$client->calling->sendFax($callId, document: 'https://example.com/doc.pdf');
$client->calling->sendFaxStop($callId);
$client->calling->receiveFaxStop($callId);
```

## Complete Example

```php
<?php
require 'vendor/autoload.php';

use SignalWire\REST\RestClient;

$client = new RestClient(
    project: $_ENV['SIGNALWIRE_PROJECT_ID'] ?? die("Set SIGNALWIRE_PROJECT_ID\n"),
    token:   $_ENV['SIGNALWIRE_API_TOKEN']  ?? die("Set SIGNALWIRE_API_TOKEN\n"),
    host:    $_ENV['SIGNALWIRE_SPACE']      ?? die("Set SIGNALWIRE_SPACE\n"),
);

// Dial a call
$call = $client->calling->dial(
    from: '+15559876543',
    to:   '+15551234567',
    url:  'https://example.com/handler',
);
$callId = $call['id'];

// Play a greeting
$client->calling->play($callId,
    play: [['type' => 'tts', 'text' => 'Welcome to our service.']],
);

// Record the call
$client->calling->record($callId, beep: true, format: 'mp3');

// End the call
$client->calling->end($callId, reason: 'hangup');
```
