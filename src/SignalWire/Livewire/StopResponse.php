<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

/**
 * Signals that a tool should not trigger another LLM reply.
 *
 * LiveKit-compatible exception (mirrors livekit `StopResponse`). Raised from a
 * tool handler to end the turn without a further model round-trip.
 */
class StopResponse extends \Exception
{
}
