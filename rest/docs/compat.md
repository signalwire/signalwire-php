# Compatibility API (PHP)

## Overview

The Compatibility API provides a Twilio-compatible LAML surface. It supports phone numbers, messaging, calls, conferences, queues, recordings, faxes, applications, and more. Access it via `$client->compat`.

## Phone Numbers

```php
// Search
$client->compat->phoneNumbers->searchLocal('US', AreaCode: '512');
$client->compat->phoneNumbers->searchTollFree('US');
$client->compat->phoneNumbers->listAvailableCountries();

// Purchase
$num = $client->compat->phoneNumbers->purchase(PhoneNumber: '+15125551234');

// Delete
$client->compat->phoneNumbers->delete($num['sid']);
```

## Messaging

```php
// Send SMS
$msg = $client->compat->messages->create(
    From: '+15559876543',
    To:   '+15551234567',
    Body: 'Hello from SignalWire!',
);

// List / Get / Media
$messages = $client->compat->messages->list();
$detail   = $client->compat->messages->get($msg['sid']);
$media    = $client->compat->messages->listMedia($msg['sid']);
```

## Calls

```php
// Create outbound call
$call = $client->compat->calls->create(
    From: '+15559876543',
    To:   '+15551234567',
    Url:  'https://example.com/voice-handler',
);

// Start recording/streaming on active call
$client->compat->calls->startRecording($call['sid']);
$client->compat->calls->startStream($call['sid'], Url: 'wss://example.com/stream');
```

## LaML Bins

```php
$laml = $client->compat->lamlBins->create(
    Name:     'Hold Music',
    Contents: '<Response><Say>Please hold.</Say></Response>',
);
$client->compat->lamlBins->delete($laml['sid']);
```

## Applications

```php
$app = $client->compat->applications->create(
    FriendlyName: 'Demo App',
    VoiceUrl:     'https://example.com/voice',
);
$client->compat->applications->delete($app['sid']);
```

## Conferences

```php
$confs = $client->compat->conferences->list();
if (!empty($confs['data'])) {
    $confSid = $confs['data'][0]['sid'];
    $detail  = $client->compat->conferences->get($confSid);
    $parts   = $client->compat->conferences->listParticipants($confSid);
    $recs    = $client->compat->conferences->listRecordings($confSid);
}
```

## Queues

```php
$queue = $client->compat->queues->create(FriendlyName: 'support-queue');
$members = $client->compat->queues->listMembers($queue['sid']);
$client->compat->queues->delete($queue['sid']);
```

## Recordings and Transcriptions

```php
$recordings     = $client->compat->recordings->list();
$transcriptions = $client->compat->transcriptions->list();

if (!empty($recordings['data'])) {
    $detail = $client->compat->recordings->get($recordings['data'][0]['sid']);
}
```

## Faxes

```php
$fax = $client->compat->faxes->create(
    From:     '+15559876543',
    To:       '+15551234567',
    MediaUrl: 'https://example.com/document.pdf',
);
$detail = $client->compat->faxes->get($fax['sid']);
```

## Accounts and Tokens

```php
$accounts = $client->compat->accounts->list();

$token = $client->compat->tokens->create(name: 'demo-token');
$client->compat->tokens->delete($token['id']);
```

## Complete Migration Example

```php
<?php
require 'vendor/autoload.php';

use SignalWire\REST\RestClient;

$client = new RestClient(
    project: $_ENV['SIGNALWIRE_PROJECT_ID'] ?? die("Set SIGNALWIRE_PROJECT_ID\n"),
    token:   $_ENV['SIGNALWIRE_API_TOKEN']  ?? die("Set SIGNALWIRE_API_TOKEN\n"),
    host:    $_ENV['SIGNALWIRE_SPACE']      ?? die("Set SIGNALWIRE_SPACE\n"),
);

// Search for a local number (Twilio-compatible)
$available = $client->compat->phoneNumbers->searchLocal('US', AreaCode: '512');

// Send an SMS (Twilio-compatible)
$msg = $client->compat->messages->create(
    From: '+15559876543',
    To:   '+15551234567',
    Body: 'Migrated from Twilio to SignalWire!',
);
echo "Message SID: " . $msg['sid'] . "\n";

// Place a call (Twilio-compatible)
$call = $client->compat->calls->create(
    From: '+15559876543',
    To:   '+15551234567',
    Url:  'https://example.com/voice-handler',
);
echo "Call SID: " . $call['sid'] . "\n";
```
