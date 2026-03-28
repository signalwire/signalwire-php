# LLM Parameters Reference (PHP)

## Overview

LLM parameters control AI behavior during voice calls: model selection, speech timing, confidence thresholds, and generation settings.

## Setting Parameters

### Via setParams()

```php
$agent->setParams([
    'ai_model'              => 'gpt-4.1-nano',
    'wait_for_user'         => false,
    'end_of_speech_timeout' => 1000,
    'ai_volume'             => 5,
    'languages_enabled'     => true,
    'local_tz'              => 'America/Los_Angeles',
]);
```

### Via setPromptLlmParams()

```php
$agent->setPromptLlmParams(
    temperature:      0.3,
    topP:             0.9,
    bargeConfidence:  0.7,
    presencePenalty:  0.1,
    frequencyPenalty: 0.2,
);
```

### Runtime Updates via FunctionResult

```php
$result->updateSettings([
    'temperature'   => 0.7,
    'max-tokens'    => 2048,
    'confidence'    => 0.8,
]);
```

## Parameter Reference

### Model Selection

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `ai_model` | string | - | LLM model to use (e.g. `gpt-4.1-nano`, `gpt-4.1-mini`) |

### Speech Timing

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `end_of_speech_timeout` | int | 500 | Silence timeout after speech (ms) |
| `attention_timeout` | int | 30000 | Max silence before AI prompts (ms) |
| `wait_for_user` | bool | true | Wait for user to speak first |

### Volume and Audio

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `ai_volume` | int | 0 | AI speech volume (-10 to 10) |
| `background_file_volume` | int | 0 | Background audio volume |

### Speech Recognition

| Parameter | Type | Range | Description |
|-----------|------|-------|-------------|
| `confidence` | float | 0.0-1.0 | Minimum speech recognition confidence |
| `barge_confidence` | float | 0.0-1.0 | Confidence threshold for barge-in (interrupting AI) |

### Generation Settings

| Parameter | Type | Range | Description |
|-----------|------|-------|-------------|
| `temperature` | float | 0.0-2.0 | Randomness in generation (clamped to 1.5) |
| `top_p` | float | 0.0-1.0 | Nucleus sampling threshold |
| `max_tokens` | int | 0-4096 | Maximum tokens in response |
| `frequency_penalty` | float | -2.0-2.0 | Reduce repetition of frequent tokens |
| `presence_penalty` | float | -2.0-2.0 | Reduce repetition of any seen token |

### Language

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `languages_enabled` | bool | false | Enable multi-language support |
| `local_tz` | string | - | Timezone for time-aware prompts |

### Debugging

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `debug_webhook_url` | string | - | URL for debug event delivery |
| `debug_webhook_level` | int | - | Debug event verbosity level |

## Common Configurations

### Fast Responsive Agent

```php
$agent->setParams([
    'ai_model'              => 'gpt-4.1-nano',
    'end_of_speech_timeout' => 300,
    'attention_timeout'     => 10000,
]);
$agent->setPromptLlmParams(
    temperature: 0.2,
    topP:        0.8,
);
```

### Conversational Agent

```php
$agent->setParams([
    'ai_model'              => 'gpt-4.1-mini',
    'end_of_speech_timeout' => 800,
    'wait_for_user'         => true,
    'attention_timeout'     => 30000,
]);
$agent->setPromptLlmParams(
    temperature:      0.7,
    presencePenalty:  0.3,
    frequencyPenalty: 0.3,
);
```

### Multilingual Agent

```php
$agent->setParams([
    'ai_model'           => 'gpt-4.1-nano',
    'languages_enabled'  => true,
    'local_tz'           => 'America/New_York',
]);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->addLanguage(name: 'Spanish', code: 'es',    voice: 'inworld.Sarah');
$agent->addLanguage(name: 'French',  code: 'fr-FR', voice: 'inworld.Hanna');
```

## updateSettings() Reference

Settings that can be changed at runtime via `FunctionResult::updateSettings()`:

| Setting | Type | Range |
|---------|------|-------|
| `frequency-penalty` | float | -2.0 to 2.0 |
| `presence-penalty` | float | -2.0 to 2.0 |
| `max-tokens` | int | 0 to 4096 |
| `top-p` | float | 0.0 to 1.0 |
| `confidence` | float | 0.0 to 1.0 |
| `barge-confidence` | float | 0.0 to 1.0 |
| `temperature` | float | 0.0 to 2.0 (clamped to 1.5) |

Note: Runtime settings use hyphenated names (e.g. `max-tokens`), while `setParams()` uses snake_case.
