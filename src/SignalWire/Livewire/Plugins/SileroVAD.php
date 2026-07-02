<?php

declare(strict_types=1);

namespace SignalWire\Livewire\Plugins;

use SignalWire\Livewire\NoopLog;

/**
 * Stub for the livekit Silero VAD plugin.
 *
 * No-op on SignalWire — the control plane handles voice-activity detection at
 * scale automatically. The `load()` factory mirrors livekit's `SileroVAD.load()`
 * and returns a fresh stub instance.
 */
class SileroVAD
{
    use NoopLog;

    /**
     * Constructor options, captured for LiveKit source-compatibility.
     *
     * @var array<string, mixed>
     */
    public array $kwargs;

    /** @param array<string, mixed> $kwargs Silero VAD options (captured, ignored). */
    public function __construct(array $kwargs = [])
    {
        $this->kwargs = $kwargs;
        self::noopOnce(
            'silero_vad',
            "SileroVAD(): SignalWire's control plane handles voice "
            . 'activity detection at scale automatically -- Silero VAD is a no-op'
        );
    }

    /** Mirrors the livekit `SileroVAD.load()` factory — returns a fresh stub. */
    public static function load(): self
    {
        return new self();
    }
}
