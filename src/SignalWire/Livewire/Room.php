<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

/**
 * Stub `Room` — SignalWire does not use the LiveKit room abstraction.
 *
 * Present purely for LiveKit source-compatibility so room-shaped code
 * constructs; its only meaningful attribute is the constant name.
 */
class Room
{
    /** Always `"livewire-room"` — SignalWire has no per-call room identity. */
    public string $name = 'livewire-room';
}
