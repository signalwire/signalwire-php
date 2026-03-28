# DataMap Guide (PHP)

## Overview

The DataMap system provides declarative SWAIG tools that execute on SignalWire's servers. DataMap tools can call REST APIs, match patterns, and generate responses without requiring your own webhook infrastructure.

## Creating a DataMap Tool

```php
<?php
require 'vendor/autoload.php';

use SignalWire\DataMap\DataMap;
use SignalWire\SWAIG\FunctionResult;

$weather = (new DataMap('get_weather'))
    ->description('Get weather for a location')
    ->parameter('location', 'string', 'City name or location', required: true)
    ->webhook('GET', 'https://api.weather.com/v1/current?key=API_KEY&q=${args.location}')
    ->output(new FunctionResult(
        'Current weather in ${args.location}: ${response.current.condition.text}, ${response.current.temp_f}F'
    ));

$agent->registerSwaigFunction($weather->toSwaigFunction());
```

## Webhook DataMaps

Webhook DataMaps make HTTP requests to external APIs:

```php
$lookup = (new DataMap('lookup_order'))
    ->description('Look up an order by ID')
    ->parameter('order_id', 'string', 'Order number', required: true)
    ->webhook('GET', 'https://api.example.com/orders/${args.order_id}', [
        'Authorization' => 'Bearer ${global_data.api_key}',
    ])
    ->output(new FunctionResult(
        'Order ${args.order_id}: status is ${response.status}, shipped on ${response.ship_date}.'
    ));
```

### Variable Expansion

DataMap supports template variable expansion:

- `${args.location}` -- Function argument values
- `${response.current.temp_f}` -- API response fields (dot notation)
- `${global_data.api_key}` -- Global data values
- `${prompt_vars.caller_id_number}` -- Call context variables
- `${enc:url:variable}` -- URL-encoded variable
- `@{strftime %Y-%m-%d}` -- Built-in functions

## Expression DataMaps

Expression DataMaps match patterns without making API calls:

```php
$fileControl = (new DataMap('file_control'))
    ->description('Control audio/video playback')
    ->parameter('command', 'string', 'Playback command', required: true,
        enum: ['play', 'pause', 'stop', 'next', 'previous'])
    ->expression(
        '${args.command}',
        'play|resume',
        new FunctionResult('Playback started'),
        nomatchOutput: new FunctionResult('Playback stopped'),
    );
```

The expression evaluates `${args.command}` against the regex pattern `play|resume`. If it matches, the match output is returned; otherwise, the nomatch output is used.

## Combining with Regular Tools

You can mix DataMap tools with regular SWAIG functions:

```php
// DataMap tool (runs on SignalWire's servers)
$weather = (new DataMap('get_weather'))
    ->description('Get weather for a location')
    ->parameter('location', 'string', 'City name', required: true)
    ->webhook('GET', 'https://api.weather.com/v1/current?q=${args.location}')
    ->output(new FunctionResult('Weather: ${response.current.condition.text}'));

$agent->registerSwaigFunction($weather->toSwaigFunction());

// Regular SWAIG tool (runs on your server)
$agent->defineTool(
    name:        'echo_test',
    description: 'Echo a message back',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'message' => ['type' => 'string', 'description' => 'Message to echo'],
        ],
    ],
    handler: function (array $args, array $raw): FunctionResult {
        return new FunctionResult("Echo: " . ($args['message'] ?? 'nothing'));
    },
);
```

## Output with Actions

DataMap outputs can include actions via FunctionResult:

```php
$transfer = (new DataMap('route_call'))
    ->description('Route call to department')
    ->parameter('department', 'string', 'Department name', required: true)
    ->expression(
        '${args.department}',
        'sales',
        (new FunctionResult('Transferring to sales.'))->connect('+15551001001'),
        nomatchOutput: (new FunctionResult('Transferring to support.'))->connect('+15551002002'),
    );
```

## prompt_vars Reference

Available in DataMap templates via `${prompt_vars.*}`:

| Key | Description |
|-----|-------------|
| `call_direction` | `inbound` or `outbound` |
| `caller_id_name` | Caller's name |
| `caller_id_number` | Caller's number |
| `local_date` | Current date |
| `local_time` | Current time with timezone |
| `time_of_day` | `morning`, `afternoon`, or `evening` |
| `supported_languages` | Available languages |
| `default_language` | Primary language |

All keys from `global_data` are also merged into `prompt_vars`.

## Best Practices

1. Use DataMap tools for simple API lookups that do not require custom logic
2. Use regular SWAIG tools when you need conditional logic, database access, or complex processing
3. Keep webhook URLs simple and rely on variable expansion for dynamic values
4. Use expressions for routing decisions that depend on argument patterns
5. Combine DataMap and regular tools freely in the same agent
