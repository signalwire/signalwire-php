<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

/**
 * Mirrors a LiveKit `RunContext` — passed to tool handlers so they can read the
 * current session, speech-turn handle, function-call descriptor, and user data.
 */
class RunContext
{
    /** The owning {@see AgentSession}, when one is bound. */
    public ?AgentSession $session;

    /** Opaque speech-turn handle (LiveKit shape; passed through untouched). */
    public mixed $speechHandle;

    /** Opaque function-call descriptor (LiveKit shape; passed through untouched). */
    public mixed $functionCall;

    public function __construct(
        ?AgentSession $session = null,
        mixed $speechHandle = null,
        mixed $functionCall = null,
    ) {
        $this->session      = $session;
        $this->speechHandle = $speechHandle;
        $this->functionCall = $functionCall;
    }

    /**
     * Per-session user data, or an empty array when no session is bound.
     *
     * Mirrors livekit `RunContext.userdata` (TS `RunContext.userData`).
     */
    public function getUserdata(): mixed
    {
        if ($this->session !== null) {
            return $this->session->getUserdata();
        }
        return [];
    }
}
