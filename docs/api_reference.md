# API Reference (PHP)

## AgentBase

`SignalWire\Agent\AgentBase` -- The main class for building AI voice agents.

### Constructor

```php
new AgentBase(
    name:       string,         // Agent name
    route:      string = '/',   // HTTP route path
    host:       string = '0.0.0.0',
    port:       int    = 3000,
    autoAnswer: bool   = false, // Auto-answer inbound calls
    recordCall: bool   = false, // Record all calls
)
```

### Prompt Methods

| Method | Description |
|--------|-------------|
| `promptAddSection(string $title, string $body, array $bullets = [])` | Add a named section to the POM |
| `setPromptLlmParams(...)` | Set LLM parameters (temperature, topP, etc.) |
| `setPostPrompt(string $prompt)` | Set the post-conversation summary prompt |
| `setParams(array $params)` | Set AI behavior parameters |
| `setGlobalData(array $data)` | Set global data available to the AI |
| `addHints(string ...$hints)` | Add speech recognition hints |
| `addPronunciation(string $word, string $pronunciation, bool $ignoreCase = true)` | Add pronunciation mapping |

### Language Methods

| Method | Description |
|--------|-------------|
| `addLanguage(string $name, string $code, string $voice)` | Add a language option |
| `setParams(['languages_enabled' => true])` | Enable multi-language support |

### Tool Methods

| Method | Description |
|--------|-------------|
| `defineTool(string $name, string $description, array $parameters, callable $handler)` | Define a SWAIG function with handler |
| `registerSwaigFunction(array $def)` | Register a raw SWAIG function definition |
| `setNativeFunctions(array $names)` | Enable built-in SWML functions |

### Skill Methods

| Method | Description |
|--------|-------------|
| `addSkill(string $name, array $config = [])` | Add a skill by name |
| `listSkills(): array` | List loaded skill names |

### Context Methods

| Method | Description |
|--------|-------------|
| `defineContexts(): ContextBuilder` | Get the context builder for multi-step flows |

### Call Flow Methods

| Method | Description |
|--------|-------------|
| `addPreAnswerVerb(string $verb, array $params)` | Add a verb before AI answers |
| `addPostAiVerb(string $verb, array $params)` | Add a verb after AI disconnects |

### Callback Methods

| Method | Description |
|--------|-------------|
| `onSummary(callable $callback)` | Register summary handler |
| `onDebugEvent(callable $callback)` | Register debug event handler |
| `setDynamicConfigCallback(callable $callback)` | Set per-request configuration callback |
| `enableDebugEvents(bool $enabled)` | Toggle debug events |

### Server Methods

| Method | Description |
|--------|-------------|
| `run()` | Start the agent HTTP server |
| `basicAuthUser(): string` | Get the basic auth username |
| `basicAuthPassword(): string` | Get the basic auth password |

---

## FunctionResult

`SignalWire\SWAIG\FunctionResult` -- Returned by SWAIG tool handlers with response text and actions.

### Constructor

```php
new FunctionResult(string $response = null, bool $postProcess = false)
```

### Core Methods

| Method | Description |
|--------|-------------|
| `setResponse(string $response)` | Set/update response text |
| `setPostProcess(bool $postProcess)` | AI gets one more turn before actions execute |
| `toArray(): array` | Convert to array for JSON serialization |

### Call Control Actions

| Method | Description |
|--------|-------------|
| `connect(string $destination, bool $final = true, string $fromAddr = null)` | Transfer call |
| `sendSms(string $toNumber, string $fromNumber, string $body, array $media = [])` | Send SMS |
| `hangup()` | Terminate call |
| `hold(int $timeout = 300)` | Put call on hold |
| `stop()` | Stop agent execution |
| `sipRefer(string $toUri)` | SIP REFER transfer |
| `joinRoom(string $name)` | Join a RELAY room |
| `joinConference(string $name, ...)` | Join audio conference |
| `recordCall(string $controlId = null, bool $stereo = false, ...)` | Start recording |
| `stopRecordCall(string $controlId = null)` | Stop recording |
| `tap(string $uri, ...)` | Start media tap |
| `stopTap(string $controlId = null)` | Stop media tap |
| `pay(string $paymentConnectorUrl, ...)` | Process payment |
| `executeSwml(mixed $swmlContent, bool $transfer = false)` | Execute SWML |

### Speech and Audio

| Method | Description |
|--------|-------------|
| `say(string $text)` | Speak text immediately |
| `playBackgroundFile(string $filename, bool $wait = false)` | Play background audio |
| `stopBackgroundFile()` | Stop background audio |
| `setEndOfSpeechTimeout(int $ms)` | Set silence timeout |
| `setSpeechEventTimeout(int $ms)` | Set speech event timeout |

### Data Management

| Method | Description |
|--------|-------------|
| `updateGlobalData(array $data)` | Update global variables |
| `removeGlobalData(mixed $keys)` | Remove global variables |
| `setMetadata(array $data)` | Set function metadata |
| `removeMetadata(mixed $keys)` | Remove function metadata |

### Behavior Control

| Method | Description |
|--------|-------------|
| `toggleFunctions(array $toggles)` | Enable/disable SWAIG functions |
| `switchContext(string $systemPrompt = null, string $userPrompt = null, ...)` | Change agent context |
| `updateSettings(array $settings)` | Update AI runtime settings |
| `simulateUserInput(string $text)` | Inject simulated user input |
| `waitForUser(mixed $enabled = null, int $timeout = null)` | Control wait behavior |
| `replaceInHistory(mixed $text = true)` | Remove/replace tool call from history |

### Low-Level

| Method | Description |
|--------|-------------|
| `addAction(string $name, mixed $data)` | Add a raw action |
| `addActions(array $actions)` | Add multiple raw actions |

All action methods return `$this` for method chaining.

---

## DataMap

`SignalWire\DataMap\DataMap` -- Declarative tools that execute on SignalWire's servers.

### Builder Methods

| Method | Description |
|--------|-------------|
| `description(string $desc)` | Set function description |
| `parameter(string $name, string $type, string $desc, ...)` | Add a parameter |
| `webhook(string $method, string $url, array $headers = [])` | Set webhook endpoint |
| `expression(string $input, string $pattern, FunctionResult $match, ...)` | Add pattern match |
| `output(FunctionResult $result)` | Set output template |
| `toSwaigFunction(): array` | Convert to SWAIG function definition |

---

## ContextBuilder

`SignalWire\Contexts\ContextBuilder` -- Defines multi-step conversation flows.

### Methods

| Method | Description |
|--------|-------------|
| `addContext(string $name, array $options)` | Add a named context with steps |

### Context Options

| Key | Type | Description |
|-----|------|-------------|
| `system_prompt` | string | Override system prompt for this context |
| `consolidate` | bool | Summarize history when entering context |
| `full_reset` | bool | Clear history when entering context |
| `steps` | array | Array of step definitions |

### Step Definition

| Key | Type | Description |
|-----|------|-------------|
| `name` | string | Step identifier |
| `prompt` | string | Step-specific prompt |
| `criteria` | string | Completion criteria |
| `valid_steps` | array | Allowed next steps |
| `valid_contexts` | array | Allowed context transitions |

---

## AgentServer

`SignalWire\Server\AgentServer` -- Runs multiple agents on one HTTP server.

```php
$server = new AgentServer(host: '0.0.0.0', port: 3000);
$server->register($agent);
$server->run();
```
