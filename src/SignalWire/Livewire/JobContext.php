<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

/**
 * Mirrors a LiveKit `JobContext` — provides room and connection info to the
 * entry-point callback registered on an {@see AgentServer}.
 *
 * `connect()` and `waitForParticipant()` are no-ops: SignalWire's control plane
 * manages the connection lifecycle and participant handling automatically.
 */
class JobContext
{
    use NoopLog;

    /** Placeholder {@see Room} (SignalWire has no room abstraction). */
    public Room $room;

    /** Shared {@see JobProcess} for prewarm-to-entry data passing. */
    public JobProcess $proc;

    /** @internal Agent bound by the entry-point (started by run_app). */
    public mixed $agent = null;

    public function __construct()
    {
        $this->room = new Room();
        $this->proc = new JobProcess();
    }

    /** Connect to the platform. No-op — SignalWire manages connection lifecycle. */
    public function connect(): void
    {
        self::noopOnce(
            'connect',
            "JobContext.connect(): SignalWire's control plane handles "
            . 'connection lifecycle at scale automatically'
        );
    }

    /** Wait for a participant to join. No-op — SignalWire handles this automatically. */
    public function waitForParticipant(mixed $identity = null): void
    {
        self::noopOnce(
            'wait_for_participant',
            "JobContext.wait_for_participant(): SignalWire's control plane "
            . 'handles participant management automatically'
        );
    }
}
