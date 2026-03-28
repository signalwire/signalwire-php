# Skills Parameter Schema (PHP)

## Overview

Each skill defines its required and optional configuration parameters. This document lists the parameter schemas for all built-in skills.

## datetime

No configuration required.

**Tools provided**: `get_current_time`, `get_date_info`, `get_timezone_info`

```php
$agent->addSkill('datetime');
```

## math

No configuration required.

**Tools provided**: `calculate`

```php
$agent->addSkill('math');
```

## web_search

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `api_key` | string | Yes | - | Google Custom Search API key |
| `search_engine_id` | string | Yes | - | Google Custom Search engine ID |
| `num_results` | int | No | 3 | Number of results to return |
| `delay` | int | No | 0 | Delay in seconds between requests |

**Tools provided**: `search_web`

```php
$agent->addSkill('web_search', [
    'api_key'          => 'your-key',
    'search_engine_id' => 'your-engine-id',
    'num_results'      => 5,
]);
```

## datasphere

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `project_id` | string | Yes | - | SignalWire project ID |
| `token` | string | Yes | - | SignalWire API token |
| `space` | string | Yes | - | SignalWire space hostname |
| `document_id` | string | No | - | Specific document to search |
| `count` | int | No | 5 | Number of results |

**Tools provided**: `search_datasphere`

```php
$agent->addSkill('datasphere', [
    'project_id' => $_ENV['SIGNALWIRE_PROJECT_ID'],
    'token'      => $_ENV['SIGNALWIRE_API_TOKEN'],
    'space'      => $_ENV['SIGNALWIRE_SPACE'],
    'count'      => 3,
]);
```

## wikipedia_search

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `language` | string | No | `en` | Wikipedia language code |
| `num_results` | int | No | 3 | Number of articles to return |

**Tools provided**: `search_wikipedia`

```php
$agent->addSkill('wikipedia_search', ['language' => 'en', 'num_results' => 3]);
```

## weather_api

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `api_key` | string | Yes | - | Weather API key |

**Tools provided**: `get_weather`

```php
$agent->addSkill('weather_api', ['api_key' => $_ENV['WEATHER_API_KEY']]);
```

## joke

No configuration required.

**Tools provided**: `tell_joke`

```php
$agent->addSkill('joke');
```

## google_maps

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `api_key` | string | Yes | - | Google Maps API key |

**Tools provided**: `get_directions`, `search_places`

```php
$agent->addSkill('google_maps', ['api_key' => $_ENV['GOOGLE_MAPS_API_KEY']]);
```

## spider

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `api_key` | string | Yes | - | Spider API key |

**Tools provided**: `scrape_web_page`

```php
$agent->addSkill('spider', ['api_key' => $_ENV['SPIDER_API_KEY']]);
```

## swml_transfer

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `destinations` | array | No | `[]` | Named transfer destinations |

**Tools provided**: `transfer_call`

```php
$agent->addSkill('swml_transfer', [
    'destinations' => [
        'sales'   => '+15551001001',
        'support' => '+15551002002',
    ],
]);
```

## play_background_file

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `files` | array | No | `[]` | Named audio files |

**Tools provided**: `play_audio`, `stop_audio`

```php
$agent->addSkill('play_background_file', [
    'files' => [
        'hold_music' => 'https://cdn.example.com/hold.mp3',
        'welcome'    => 'https://cdn.example.com/welcome.mp3',
    ],
]);
```

## info_gatherer

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `questions` | array | Yes | - | List of questions to ask |

**Tools provided**: `save_answer`, `get_answers`

```php
$agent->addSkill('info_gatherer', [
    'questions' => [
        ['field' => 'name',  'question_text' => 'What is your name?'],
        ['field' => 'email', 'question_text' => 'What is your email?'],
    ],
]);
```

## mcp_gateway

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `gateway_url` | string | Yes | - | MCP gateway endpoint URL |
| `timeout` | int | No | 30 | Request timeout in seconds |

**Tools provided**: Dynamic, based on MCP server capabilities

```php
$agent->addSkill('mcp_gateway', [
    'gateway_url' => 'https://mcp.example.com/gateway',
]);
```

## custom_skills

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `skills` | array | Yes | - | Array of custom skill definitions |

```php
$agent->addSkill('custom_skills', [
    'skills' => [
        [
            'name'        => 'my_tool',
            'description' => 'Does something custom',
            'parameters'  => ['type' => 'object', 'properties' => [/* ... */]],
            'url'         => 'https://my-api.example.com/tool',
        ],
    ],
]);
```
