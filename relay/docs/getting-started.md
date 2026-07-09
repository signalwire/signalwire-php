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

The `Client` constructor takes a single options array. `connect()` opens the
WebSocket *and* runs the authentication handshake — there is no separate
connect/authenticate step.

<!-- snippet: no-run connect()/run() open a live WebSocket to SIGNALWIRE_SPACE and block serving inbound calls — a long-running server, not a standalone script -->
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

$client->onCall(function ($call) {
    echo "Incoming call: " . $call->callId . "\n";
    $call->answer();

    $action = $call->play([
        ['type' => 'tts', 'params' => ['text' => 'Hello from SignalWire!']],
    ]);
    $action->wait();

    $call->hangup();
    echo "Call ended.\n";
});

$client->connect();

echo "Waiting for inbound calls on context 'default' ...\n";
$client->run();
```

## First Outbound Call

<!-- snippet: no-run connect() opens a live WebSocket to SIGNALWIRE_SPACE and dial() places a real outbound call — cannot reach the loopback mock standalone -->
```php
<?php
require 'vendor/autoload.php';

use SignalWire\Relay\Client;

$client = new Client([
    'project' => $_ENV['SIGNALWIRE_PROJECT_ID'],
    'token'   => $_ENV['SIGNALWIRE_API_TOKEN'],
    'host'    => $_ENV['SIGNALWIRE_SPACE'] ?? 'relay.signalwire.com',
]);

$client->connect();

$call = $client->dial(
    [[
        ['type' => 'phone', 'params' => [
            'to_number'   => '+15551234567',
            'from_number' => '+15559876543',
        ]],
    ]],
    ['dial_timeout' => 30.0],
);

echo "Call answered -- playing TTS\n";
$action = $call->play([
    ['type' => 'tts', 'params' => ['text' => 'Hello from SignalWire!']],
]);
$action->wait();

$call->hangup();
$client->disconnect();
```

## Connection Lifecycle

1. `connect()` -- Open the WebSocket and run the `signalwire.connect`
   authentication handshake in one call.
2. `onCall($callback)` -- Register a handler for inbound calls (call before `run()`).
3. `run()` -- Enter the event loop (blocks until disconnected).
4. `disconnect()` -- Close the WebSocket gracefully.

## Contexts

Contexts route inbound calls to your handler. Set them in the options array:

```php
$client = new Client([
    'project'  => $_ENV['SIGNALWIRE_PROJECT_ID'],
    'token'    => $_ENV['SIGNALWIRE_API_TOKEN'],
    'contexts' => ['default', 'sales', 'support'],
]);
```

Only calls tagged with one of your contexts will be delivered. You can also
subscribe dynamically after connecting with `$client->receive(['billing'])`
and unsubscribe with `$client->unreceive(['sales'])`.

## Next Steps

- [Call Methods Reference](call-methods.md) -- all methods on the Call object
- [Events](events.md) -- handling call events
- [Messaging](messaging.md) -- SMS/MMS
- [Client Reference](client-reference.md) -- full Client API
