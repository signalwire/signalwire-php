<?php

declare(strict_types=1);

namespace SignalWire\Livewire\Plugins;

use SignalWire\Livewire\NoopLog;

/**
 * Stub for the livekit Cartesia TTS plugin.
 *
 * No-op on SignalWire — the control plane handles text-to-speech at scale.
 * Logs a one-time advisory on first construction.
 */
class CartesiaTTS
{
    use NoopLog;

    /**
     * Constructor options, captured for LiveKit source-compatibility.
     *
     * @var array<string, mixed>
     */
    public array $kwargs;

    /** @param array<string, mixed> $kwargs Cartesia options (captured, ignored). */
    public function __construct(array $kwargs = [])
    {
        $this->kwargs = $kwargs;
        self::noopOnce(
            'cartesia_tts',
            "CartesiaTTS(): SignalWire's control plane handles "
            . 'text-to-speech at scale -- Cartesia plugin is a no-op'
        );
    }
}
