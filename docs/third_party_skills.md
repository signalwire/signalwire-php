# Third-Party Skills Integration (PHP)

## Overview

The SignalWire PHP SDK skills system supports integration with external services and custom skill providers. This guide covers connecting third-party APIs and custom skill definitions.

## Google Custom Search

```php
$agent->addSkill('web_search', [
    'api_key'          => $_ENV['GOOGLE_SEARCH_API_KEY'],
    'search_engine_id' => $_ENV['GOOGLE_SEARCH_ENGINE_ID'],
    'num_results'      => 5,
]);
```

### Setup

1. Create a Google Custom Search Engine at https://cse.google.com
2. Get an API key from https://console.cloud.google.com
3. Set `GOOGLE_SEARCH_API_KEY` and `GOOGLE_SEARCH_ENGINE_ID` environment variables

## Google Maps

```php
$agent->addSkill('google_maps', [
    'api_key' => $_ENV['GOOGLE_MAPS_API_KEY'],
]);
```

Provides `get_directions` and `search_places` tools for location-based queries.

## Weather API

```php
$agent->addSkill('weather_api', [
    'api_key' => $_ENV['WEATHER_API_KEY'],
]);
```

Provides `get_weather` for current conditions and forecasts.

## Spider (Web Scraping)

```php
$agent->addSkill('spider', [
    'api_key' => $_ENV['SPIDER_API_KEY'],
]);
```

Provides `scrape_web_page` for extracting content from web pages.

## Claude Skills

Integrate Claude-powered capabilities:

```php
$agent->addSkill('claude_skills', [
    'api_key' => $_ENV['ANTHROPIC_API_KEY'],
]);
```

## Custom External Skills

### Via DataMap

For simple API integrations, use DataMap tools:

```php
use SignalWire\DataMap\DataMap;
use SignalWire\SWAIG\FunctionResult;

$stockPrice = (new DataMap('get_stock_price'))
    ->description('Get current stock price')
    ->parameter('symbol', 'string', 'Stock ticker symbol', required: true)
    ->webhook('GET', 'https://api.stocks.example.com/v1/quote/${args.symbol}', [
        'Authorization' => 'Bearer ${global_data.stock_api_key}',
    ])
    ->output(new FunctionResult(
        '${args.symbol} is trading at $${response.price} (${response.change}%)'
    ));

$agent->registerSwaigFunction($stockPrice->toSwaigFunction());
```

### Via Custom Skill Class

Create a reusable skill by extending `SkillBase`:

```php
namespace App\Skills;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class CrmLookupSkill extends SkillBase
{
    public function getName(): string { return 'crm_lookup'; }
    public function getDescription(): string { return 'Look up customer in CRM'; }

    /** Config passed via addSkill('crm_lookup', [...]) lands in $this->params. */
    public function setup(): bool
    {
        return !empty($this->params['crm_api_url']) && !empty($this->params['crm_api_key']);
    }

    public function registerTools(): void
    {
        $this->defineTool(
            'lookup_customer',
            'Look up a customer by name or email',
            [
                'query' => ['type' => 'string', 'description' => 'Customer name or email'],
            ],
            function (array $args, array $raw): FunctionResult {
                // Call CRM API using the configured credentials.
                $response = file_get_contents(
                    $this->params['crm_api_url'] . '/customers?q=' . urlencode($args['query']),
                    false,
                    stream_context_create(['http' => [
                        'header' => "Authorization: Bearer {$this->params['crm_api_key']}\r\n",
                    ]])
                );
                $data = json_decode($response, true);
                $name = $data['results'][0]['name'] ?? 'Not found';
                return new FunctionResult("Customer found: {$name}");
            },
        );
    }
}
```

### Via custom_skills Skill

For quick external tool definitions without creating a class:

```php
$agent->addSkill('custom_skills', [
    'skills' => [
        [
            'name'        => 'check_inventory',
            'description' => 'Check product inventory',
            'parameters'  => [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'string', 'description' => 'Product ID'],
                ],
            ],
            'url' => 'https://inventory.example.com/api/check',
        ],
    ],
]);
```

## Environment Variable Best Practices

Store API keys in environment variables, never in source code:

```bash
export GOOGLE_SEARCH_API_KEY=your-key
export GOOGLE_SEARCH_ENGINE_ID=your-engine-id
export WEATHER_API_KEY=your-weather-key
export ANTHROPIC_API_KEY=your-claude-key
```

Handle missing credentials gracefully:

```php
try {
    $agent->addSkill('web_search', [
        'api_key'          => $_ENV['GOOGLE_SEARCH_API_KEY'] ?? throw new \RuntimeException('Missing key'),
        'search_engine_id' => $_ENV['GOOGLE_SEARCH_ENGINE_ID'] ?? throw new \RuntimeException('Missing ID'),
    ]);
} catch (\Exception $e) {
    echo "Web search disabled: " . $e->getMessage() . "\n";
}
```
