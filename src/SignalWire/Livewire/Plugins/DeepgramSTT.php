<?php

declare(strict_types=1);

namespace SignalWire\Livewire\Plugins;

use SignalWire\Livewire\NoopLog;

/**
 * Stub for the livekit Deepgram STT plugin.
 *
 * No-op on SignalWire — the control plane handles speech recognition at scale.
 * Exists so LiveKit code that constructs this plugin runs unchanged; logs a
 * one-time advisory on first construction.
 */
class DeepgramSTT
{
    use NoopLog;

    /**
     * Constructor options, captured for LiveKit source-compatibility.
     *
     * @var array<string, mixed>
     */
    public array $kwargs;

    /** @param array<string, mixed> $kwargs Deepgram options (captured, ignored). */
    public function __construct(array $kwargs = [])
    {
        $this->kwargs = $kwargs;
        self::noopOnce(
            'deepgram_stt',
            "DeepgramSTT(): SignalWire's control plane handles speech "
            . 'recognition at scale -- Deepgram plugin is a no-op'
        );
    }
}
