# SWAIG Reference (PHP)

SWAIG (SignalWire AI Gateway) is the platform's AI tool-calling system. It connects the AI's decisions to actions like call transfers, SMS, recordings, and API calls, with native access to the media stack. This document covers the `FunctionResult` class and the SWAIG post_data format.

## FunctionResult Methods

### Basic Construction

```php
use SignalWire\SWAIG\FunctionResult;

$result = new FunctionResult("Hello, I'll help you with that");
$result = new FunctionResult("Processing...", postProcess: true);
```

### Call Control Actions

#### connect()

Transfer/connect call to another destination using SWML.

```php
$result->connect('+15551234567', final: true);
$result->connect('support@company.com', final: false, fromAddr: '+15559876543');
```

#### sendSms()

Send SMS message to a PSTN phone number.

```php
$result->sendSms(
    toNumber:   '+15551234567',
    fromNumber: '+15559876543',
    body:       'Your order has been confirmed!',
);

// With media
$result->sendSms(
    toNumber:   '+15551234567',
    fromNumber: '+15559876543',
    media:      ['https://example.com/receipt.jpg'],
    tags:       ['order', 'confirmation'],
);
```

#### recordCall()

Start background call recording.

```php
$result->recordCall();
$result->recordCall(controlId: 'support_001', stereo: true, format: 'mp3');
```

#### stopRecordCall()

```php
$result->stopRecordCall();
$result->stopRecordCall('support_001');
```

#### joinRoom()

```php
$result->joinRoom('support_team_room');
```

#### sipRefer()

```php
$result->sipRefer('sip:support@company.com');
```

#### joinConference()

```php
$result->joinConference('daily_standup', record: 'record-from-start', maxParticipants: 10);
```

#### tap() / stopTap()

```php
$result->tap('wss://example.com/tap', controlId: 'tap_001', direction: 'both');
$result->stopTap('tap_001');
```

#### pay()

```php
$result->pay(
    paymentConnectorUrl: 'https://api.example.com/accept-payment',
    chargeAmount:        '10.99',
    description:         'Monthly subscription',
);
```

#### hangup() / hold() / stop()

```php
$result->hangup();
$result->hold(60);
$result->stop();
```

### Speech and Audio

```php
$result->say('Please hold while I look that up.');
$result->playBackgroundFile('hold_music.wav');
$result->playBackgroundFile('announcement.mp3', wait: true);
$result->stopBackgroundFile();
$result->setEndOfSpeechTimeout(2000);
$result->setSpeechEventTimeout(3000);
```

### Data Management

```php
$result->updateGlobalData(['user_name' => 'John', 'step' => 2]);
$result->removeGlobalData('temporary_data');
$result->removeGlobalData(['step', 'temp_value']);
$result->setMetadata(['session_id' => 'abc123']);
$result->removeMetadata('temp_session_data');
```

### Function and Behavior Control

```php
$result->toggleFunctions([
    ['function' => 'transfer_call', 'active' => false],
    ['function' => 'lookup_info',   'active' => true],
]);

$result->switchContext(
    systemPrompt: 'You are now a billing specialist.',
    userPrompt:   'The user needs help with their invoice.',
    consolidate:  true,
);

$result->updateSettings([
    'temperature' => 0.7,
    'max-tokens'  => 2048,
]);

$result->simulateUserInput('Yes, I want to speak to billing');
$result->waitForUser(true);
$result->waitForUser(timeout: 30);
$result->replaceInHistory();
$result->replaceInHistory("I've saved your profile.");
```

### Method Chaining

All action methods return `$this`:

```php
$result = (new FunctionResult('Transferring you to billing.'))
    ->setMetadata(['transfer_reason' => 'billing_inquiry'])
    ->updateGlobalData(['last_action' => 'transfer_to_billing'])
    ->connect('+15551234567', final: true);
```

### Low-Level

```php
$result->addAction('custom_action', ['param' => 'value']);
$result->addActions([['say' => 'Hello'], ['hold' => 300]]);
$resultArray = $result->toArray();
```

---

## Post Data Reference

The `post_data` is the JSON payload sent to SWAIG function handlers. Access it via the second parameter (`$rawData`) of your handler callback.

### Base Keys (All Functions)

| Key | Type | Description |
|-----|------|-------------|
| `app_name` | string | Agent application name |
| `function` | string | SWAIG function name being called |
| `call_id` | string | UUID of the current call |
| `ai_session_id` | string | UUID of the AI session |
| `caller_id_name` | string | Caller ID name |
| `caller_id_num` | string | Caller ID number |
| `argument` | object | Parsed function arguments |
| `purpose` | string | Function description |
| `content_type` | string | Always `text/swaig` |
| `global_data` | object | Application global data |
| `project_id` | string | SignalWire project ID |

### Webhook-Only Keys

| Key | Present When |
|-----|-------------|
| `meta_data_token` | Function has metadata token |
| `meta_data` | Function has metadata token |
| `SWMLVars` | `swaig_post_swml_vars` parameter set |
| `call_log` | `swaig_post_conversation` is true |
| `raw_call_log` | `swaig_post_conversation` is true |

### DataMap-Specific Keys

| Key | Description |
|-----|-------------|
| `prompt_vars` | Template variables from call context |
| `args` | First parsed argument object |
| `input` | Copy of entire post_data |

### SWML Parameters Controlling post_data

| Parameter | Default | Purpose |
|-----------|---------|---------|
| `swaig_allow_swml` | true | Allow functions to execute SWML |
| `swaig_allow_settings` | true | Allow functions to modify AI settings |
| `swaig_post_conversation` | false | Include conversation history |
| `swaig_set_global_data` | true | Allow functions to modify global_data |
| `swaig_post_swml_vars` | false | Include SWML variables |

## Related Documentation

- [API Reference](api_reference.md) -- Complete AgentBase and FunctionResult API
- [Contexts Guide](contexts_guide.md) -- Context switching and steps
- [DataMap Guide](datamap_guide.md) -- DataMap with FunctionResult outputs
- [Agent Guide](agent_guide.md) -- General agent development
