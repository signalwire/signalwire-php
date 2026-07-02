<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

/**
 * Signals a handoff to another agent in multi-agent scenarios.
 *
 * Mirrors livekit `AgentHandoff`: carries the target {@see Agent} and an
 * optional value surfaced to the current agent when the handoff completes.
 * Return it from a tool handler to transfer control.
 */
class AgentHandoff
{
    /** Target agent that should take over the conversation. */
    public Agent $agent;

    /** Optional return value surfaced when the handoff completes. */
    public mixed $returns;

    public function __construct(Agent $agent, mixed $returns = null)
    {
        $this->agent   = $agent;
        $this->returns = $returns;
    }
}
