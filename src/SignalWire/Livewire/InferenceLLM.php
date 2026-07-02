<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

/**
 * Stub for livekit `inference.LLM`.
 *
 * Captures the model identifier; runs no inference locally — SignalWire's
 * control plane handles LLM inference server-side. (Mirrors the Python shim,
 * which emits no advisory for the LLM inference stub.)
 */
class InferenceLLM
{
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
    }
}
