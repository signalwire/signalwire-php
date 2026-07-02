<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

/**
 * Mirrors a LiveKit `JobProcess` — placeholder for prewarm / setup hooks.
 *
 * On SignalWire the control plane pre-warms infrastructure at scale, so this
 * class carries no real state beyond the LiveKit-compatible `userdata` bag.
 */
class JobProcess
{
    /**
     * Mutable bag shared across prewarm and entry-point callbacks.
     *
     * @var array<string, mixed>
     */
    public array $userdata;

    public function __construct()
    {
        $this->userdata = [];
    }
}
