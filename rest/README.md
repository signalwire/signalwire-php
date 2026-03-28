# SignalWire REST Client (PHP)

Synchronous REST client for managing SignalWire resources, controlling live calls, and interacting with every SignalWire API surface from PHP. No WebSocket required -- just standard HTTP requests.

## Quick Start

```php
<?php
require 'vendor/autoload.php';

use SignalWire\REST\RestClient;

$client = new RestClient(
    project: $_ENV['SIGNALWIRE_PROJECT_ID'],
    token:   $_ENV['SIGNALWIRE_API_TOKEN'],
    host:    $_ENV['SIGNALWIRE_SPACE'],
);

// Create an AI agent
$agent = $client->fabric->aiAgents->create(
    name:   'Support Bot',
    prompt: ['text' => 'You are a helpful support agent.'],
);

// Search for a phone number
$results = $client->phoneNumbers->search(areaCode: '512');

// Place a call via REST
$client->calling->dial(
    from: '+15559876543',
    to:   '+15551234567',
    url:  'https://example.com/call-handler',
);
```

## Features

- Single `RestClient` with namespaced sub-objects for every API
- All calling commands: dial, play, record, collect, detect, tap, stream, AI, transcribe, and more
- Full Fabric API: resource types with CRUD + addresses, tokens, and generic resources
- Datasphere: document management and semantic search
- Video: rooms, sessions, recordings, conferences, tokens, streams
- Compatibility API: full Twilio-compatible LAML surface
- Phone number management, 10DLC registry, MFA, logs, and more
- Lightweight HTTP via cURL
- Array returns -- raw data, no wrapper objects to learn

## Documentation

- [Getting Started](docs/getting-started.md) -- installation, configuration, first API call
- [Client Reference](docs/client-reference.md) -- RestClient constructor, namespaces, error handling
- [Fabric Resources](docs/fabric.md) -- managing AI agents, SWML scripts, subscribers, call flows, and more
- [Calling Commands](docs/calling.md) -- REST-based call control (dial, play, record, collect, AI, etc.)
- [Compatibility API](docs/compat.md) -- Twilio-compatible LAML endpoints
- [All Namespaces](docs/namespaces.md) -- phone numbers, video, datasphere, logs, registry, and more

## Examples

| File | Description |
|------|-------------|
| [rest_10dlc_registration.php](examples/rest_10dlc_registration.php) | 10DLC brand and campaign compliance registration |
| [rest_calling_ivr_and_ai.php](examples/rest_calling_ivr_and_ai.php) | IVR input, AI operations, live transcription, tap, stream |
| [rest_calling_play_and_record.php](examples/rest_calling_play_and_record.php) | Media operations: play, record, transcribe, denoise |
| [rest_compat_laml.php](examples/rest_compat_laml.php) | Twilio-compatible LAML migration |
| [rest_datasphere_search.php](examples/rest_datasphere_search.php) | Upload document, run semantic search |
| [rest_fabric_conferences_and_routing.php](examples/rest_fabric_conferences_and_routing.php) | Conferences, cXML resources, generic routing, tokens |
| [rest_fabric_subscribers_and_sip.php](examples/rest_fabric_subscribers_and_sip.php) | Provision SIP-enabled users on Fabric |
| [rest_fabric_swml_and_callflows.php](examples/rest_fabric_swml_and_callflows.php) | SWML scripts and call flows |
| [rest_manage_resources.php](examples/rest_manage_resources.php) | Create AI agent, assign number, place test call |
| [rest_phone_number_management.php](examples/rest_phone_number_management.php) | Full phone number inventory lifecycle |
| [rest_queues_mfa_and_recordings.php](examples/rest_queues_mfa_and_recordings.php) | Call queues, recording review, MFA verification |
| [rest_video_rooms.php](examples/rest_video_rooms.php) | Video rooms, sessions, conferences, streams |

## Environment Variables

| Variable | Description |
|----------|-------------|
| `SIGNALWIRE_PROJECT_ID` | Project ID for authentication |
| `SIGNALWIRE_API_TOKEN` | API token for authentication |
| `SIGNALWIRE_SPACE` | Space hostname (e.g. `example.signalwire.com`) |
| `SIGNALWIRE_LOG_LEVEL` | Log level (`debug` for HTTP request details) |

## Module Structure

```
src/SignalWire/REST/
    RestClient.php           # Main client -- namespace wiring, lazy builders
    HttpClient.php           # cURL wrapper with auth, JSON, error handling
    CrudResource.php         # Generic CRUD resource helper
    SignalWireRestError.php   # REST error class
    Namespaces/
        Fabric.php           # AI agents, SWML scripts, subscribers, call flows, etc.
        Calling.php          # REST-based call control commands
        ... and more
```
