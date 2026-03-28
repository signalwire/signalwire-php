# SignalWire RELAY Client (PHP)

Real-time call control and messaging over WebSocket. The RELAY client connects to SignalWire via the Blade protocol (JSON-RPC 2.0 over WebSocket) and gives you imperative control over live phone calls and SMS/MMS messaging.

## Quick Start

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
    echo "Incoming call: " . $call->callId() . "\n";
    $call->answer();

    $action = $call->play(media: [
        ['type' => 'tts', 'params' => ['text' => 'Welcome to SignalWire!']],
    ]);
    $action->wait();

    $call->hangup();
});

$client->connectWs();
$client->authenticate();
echo "Waiting for inbound calls on context 'default' ...\n";
$client->run();
```

## Features

- Synchronous blocking API (no async/await required)
- Auto-reconnect with exponential backoff
- All calling methods: play, record, collect, connect, detect, fax, tap, stream, AI, conferencing, queues, and more
- SMS/MMS messaging: send outbound messages, receive inbound messages, track delivery state
- Action objects with `wait()`, `stop()`, `pause()`, `resume()` for controllable operations
- Typed event classes for all call events
- Dynamic context subscription/unsubscription

## Documentation

- [Getting Started](docs/getting-started.md) -- installation, configuration, first call
- [Call Methods Reference](docs/call-methods.md) -- every method available on a Call object
- [Events](docs/events.md) -- event types, typed event classes, call states
- [Messaging](docs/messaging.md) -- sending and receiving SMS/MMS messages
- [Client Reference](docs/client-reference.md) -- Client configuration, methods, connection behavior

## Examples

| File | Description |
|------|-------------|
| [relay_answer_and_welcome.php](examples/relay_answer_and_welcome.php) | Answer an inbound call and play a TTS greeting |
| [relay_dial_and_play.php](examples/relay_dial_and_play.php) | Dial an outbound number, wait for answer, and play TTS |
| [relay_ivr_connect.php](examples/relay_ivr_connect.php) | IVR menu with DTMF collection, playback, and call connect |

## Environment Variables

| Variable | Description |
|----------|-------------|
| `SIGNALWIRE_PROJECT_ID` | Project ID for authentication |
| `SIGNALWIRE_API_TOKEN` | API token for authentication |
| `SIGNALWIRE_SPACE` | Space hostname (default: `relay.signalwire.com`) |
| `SIGNALWIRE_LOG_LEVEL` | Log level (`debug` for WebSocket traffic) |

## Module Structure

```
src/SignalWire/Relay/
    Client.php       # RELAY client -- WebSocket connection, auth, event dispatch
    Call.php         # Call object -- all calling methods
    Action.php       # Action classes for controllable operations
    Event.php        # Typed event classes
    Message.php      # SMS/MMS message tracking
    Constants.php    # Protocol constants, call states, event types
```
