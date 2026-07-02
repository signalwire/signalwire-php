<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

/**
 * Stub for livekit `inference.TTS`.
 *
 * Captures the model identifier; runs no inference locally — SignalWire's
 * control plane handles text-to-speech server-side. Logs a one-time advisory
 * on first construction.
 */
class InferenceTTS
{
    use NoopLog;

    /** Model identifier captured from the constructor. */
    public string $model;

    /**
     * Additional constructor options, captured for source-compatibility.
     *
     * @var array<string, mixed>
     */
    public array $kwargs;

    /**
     * @param string               $model  Model identifier (captured).
     * @param array<string, mixed>  $kwargs Additional options (ignored).
     */
    public function __construct(string $model = '', array $kwargs = [])
    {
        $this->model  = $model;
        $this->kwargs = $kwargs;
        self::noopOnce(
            'inference_tts',
            "InferenceTTS('{$model}'): SignalWire's control plane handles "
            . 'text-to-speech -- inference stubs are no-ops'
        );
    }
}
