# RELAY Getting Started (PHP)

## Installation

```bash
composer require signalwire/signalwire-php
```

## Configuration

Set your SignalWire credentials:

```bash
export SIGNALWIRE_PROJECT_ID=your-project-id
export SIGNALWIRE_API_TOKEN=your-api-token
export SIGNALWIRE_SPACE=example.signalwire.com
```

## First Inbound Call

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

$client->onCall(function ($call) {
    echo "Incoming call from " . $call->callId() . "\n";
    $call->answer();

    $action = $call->play(media: [
        ['type' => 'tts', 'params' => ['text' => 'Hello from SignalWire!']],
    ]);
    $action->wait();

    $call->hangup();
    echo "Call ended.\n";
});

$client->connectWs() or die("Connection failed\n");
$client->authenticate();

echo "Waiting for inbound calls on context 'default' ...\n";
$client->run();
```

## First Outbound Call

```php
<?php
require 'vendor/autoload.php';

use SignalWire\Relay\Client;

$client = new Client(
    project: $_ENV['SIGNALWIRE_PROJECT_ID'],
    token:   $_ENV['SIGNALWIRE_API_TOKEN'],
    host:    $_ENV['SIGNALWIRE_SPACE'] ?? 'relay.signalwire.com',
);

$client->connectWs() or die("Connection failed\n");
$client->authenticate();

$call = $client->dial(
    devices: [[
        ['type' => 'phone', 'params' => [
            'to_number'   => '+15551234567',
            'from_number' => '+15559876543',
        ]],
    ]],
    timeout: 30,
);

echo "Call answered -- playing TTS\n";
$action = $call->play(media: [
    ['type' => 'tts', 'params' => ['text' => 'Hello from SignalWire!']],
]);
$action->wait();

$call->hangup();
$client->disconnectWs();
```

## Connection Lifecycle

1. `connectWs()` -- Establish WebSocket connection
2. `authenticate()` -- Authenticate with project credentials
3. `onCall($callback)` -- Register handler for inbound calls
4. `run()` -- Enter event loop (blocks until interrupted)

## Contexts

Contexts route inbound calls to your handler. Set them in the constructor:

```php
$client = new Client(
    project:  $_ENV['SIGNALWIRE_PROJECT_ID'],
    token:    $_ENV['SIGNALWIRE_API_TOKEN'],
    contexts: ['default', 'sales', 'support'],
);
```

Only calls tagged with one of your contexts will be delivered.

## Next Steps

- [Call Methods Reference](call-methods.md) -- all methods on the Call object
- [Events](events.md) -- handling call events
- [Messaging](messaging.md) -- SMS/MMS
- [Client Reference](client-reference.md) -- full Client API
