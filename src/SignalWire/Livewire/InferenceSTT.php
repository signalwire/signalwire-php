<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

/**
 * Stub for livekit `inference.STT`.
 *
 * Captures the model identifier; runs no inference locally — SignalWire's
 * control plane handles speech recognition server-side. Logs a one-time
 * advisory on first construction.
 */
class InferenceSTT
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
     * @param string               $model Model identifier (captured).
     * @param array<string, mixed>  $kwargs Additional options (ignored).
     */
    public function __construct(string $model = '', array $kwargs = [])
    {
        $this->model  = $model;
        $this->kwargs = $kwargs;
        self::noopOnce(
            'inference_stt',
            "InferenceSTT('{$model}'): SignalWire's control plane handles "
            . 'speech recognition -- inference stubs are no-ops'
        );
    }
}
