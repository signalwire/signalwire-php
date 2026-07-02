<?php

declare(strict_types=1);

namespace SignalWire\Livewire\Plugins;

use SignalWire\Livewire\NoopLog;

/**
 * Stub for the livekit OpenAI LLM plugin.
 *
 * The `model` option is captured and mapped to the SignalWire AI `model` param
 * by {@see \SignalWire\Livewire\AgentSession::start()}. Other options are
 * ignored. Logs a one-time advisory on first construction.
 */
class OpenAILLM
{
    use NoopLog;

    /** Model identifier captured from the constructor options. */
    public string $model;

    /** @param array<string, mixed> $kwargs OpenAI options; `model` is captured. */
    public function __construct(array $kwargs = [])
    {
        $model = $kwargs['model'] ?? '';
        $this->model = is_string($model) ? $model : '';
        self::noopOnce(
            'openai_llm',
            'OpenAILLM(): model selection is mapped to SignalWire AI '
            . 'params -- OpenAI plugin wrapper is a no-op'
        );
    }
}
