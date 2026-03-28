# Skills System (PHP)

## Overview

The skills system provides a modular architecture for extending agent capabilities. Skills are self-contained packages of SWAIG tools, prompt text, and configuration that can be added to any agent with a single call.

## Adding Skills

```php
<?php
require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(name: 'assistant', route: '/assistant');
$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

$agent->promptAddSection('Role', 'You are a helpful assistant with multiple skills.');

// Add built-in skills
$agent->addSkill('datetime');
$agent->addSkill('math');
$agent->addSkill('web_search', [
    'api_key'          => $_ENV['GOOGLE_SEARCH_API_KEY'],
    'search_engine_id' => $_ENV['GOOGLE_SEARCH_ENGINE_ID'],
    'num_results'      => 3,
]);

// List loaded skills
$loaded = $agent->listSkills();
echo "Loaded skills: " . implode(', ', $loaded) . "\n";

$agent->run();
```

## Built-in Skills

| Skill | Description | Required Config |
|-------|-------------|-----------------|
| `datetime` | Current date, time, timezone info | None |
| `math` | Mathematical calculations | None |
| `web_search` | Google Custom Search | `api_key`, `search_engine_id` |
| `datasphere` | SignalWire Datasphere semantic search | SignalWire credentials |
| `datasphere_serverless` | Serverless Datasphere variant | SignalWire credentials |
| `native_vector_search` | Native vector search | None |
| `wikipedia_search` | Wikipedia article search | None |
| `weather_api` | Weather information | API key |
| `joke` | Tell jokes | None |
| `google_maps` | Google Maps directions/places | API key |
| `spider` | Web scraping | API key |
| `api_ninjas_trivia` | Trivia questions | API key |
| `swml_transfer` | SWML-based call transfer | None |
| `play_background_file` | Background audio playback | None |
| `info_gatherer` | Structured information collection | Questions config |
| `mcp_gateway` | MCP (Model Context Protocol) gateway | Gateway URL |
| `claude_skills` | Claude-powered skills | API key |
| `custom_skills` | User-defined custom skills | Skill definitions |

## Skill Configuration

Skills accept configuration via an associative array:

```php
// Simple skill, no config needed
$agent->addSkill('datetime');

// Skill with configuration
$agent->addSkill('web_search', [
    'api_key'          => 'your-google-api-key',
    'search_engine_id' => 'your-engine-id',
    'num_results'      => 5,
    'delay'            => 0,
]);

// Datasphere skill
$agent->addSkill('datasphere', [
    'project_id' => $_ENV['SIGNALWIRE_PROJECT_ID'],
    'token'      => $_ENV['SIGNALWIRE_API_TOKEN'],
    'space'      => $_ENV['SIGNALWIRE_SPACE'],
]);
```

## Error Handling

Skills validate their dependencies and throw exceptions if requirements are not met:

```php
try {
    $agent->addSkill('web_search', [
        'api_key'          => $_ENV['GOOGLE_SEARCH_API_KEY'],
        'search_engine_id' => $_ENV['GOOGLE_SEARCH_ENGINE_ID'],
    ]);
    echo "Web search skill loaded.\n";
} catch (\Exception $e) {
    echo "Web search not available: " . $e->getMessage() . "\n";
}
```

## Skill Discovery

The `SkillRegistry` automatically discovers skills from the `SignalWire\Skills\Builtin` namespace. Each skill class extends `SkillBase` and registers its tools, prompt sections, and dependencies.

### Skill Class Structure

```php
namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;

class MySkill extends SkillBase
{
    public function name(): string { return 'my_skill'; }
    public function description(): string { return 'Does something useful'; }
    public function requiredConfig(): array { return ['api_key']; }
    public function requiredEnv(): array { return []; }

    public function register(AgentBase $agent, array $config): void
    {
        $agent->defineTool(
            name:        'my_tool',
            description: 'My custom tool',
            parameters:  ['type' => 'object', 'properties' => [/* ... */]],
            handler:     function (array $args, array $raw) use ($config) {
                // Implementation using $config['api_key']
                return new FunctionResult('Result');
            },
        );
    }
}
```

## Combining Skills with Custom Tools

Skills coexist with manually defined tools:

```php
// Add skills
$agent->addSkill('datetime');
$agent->addSkill('math');

// Add custom tool
$agent->defineTool(
    name:        'lookup_account',
    description: 'Look up customer account',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'account_id' => ['type' => 'string', 'description' => 'Account ID'],
        ],
    ],
    handler: function (array $args, array $raw): FunctionResult {
        return new FunctionResult("Account {$args['account_id']} found: Premium tier.");
    },
);
```

## Best Practices

1. Add only the skills your agent needs to keep the tool list focused
2. Handle skill loading errors gracefully -- missing API keys should not crash your agent
3. Use `listSkills()` to verify which skills loaded successfully
4. Skills automatically add prompt context -- you do not need to duplicate it
