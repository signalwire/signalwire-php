<?php

declare(strict_types=1);

namespace SignalWire\Relay;

/**
 * RELAY call lifecycle state, as a typed, compile-time-checked closed set.
 *
 * A call moves through these states over its lifetime:
 *
 *     created → ringing → answered → ending → ended
 *
 * The five members are exactly the {@see Constants}::CALL_STATE_* tokens
 * (grounded in the Python reference's `relay/constants.py` CALL_STATES tuple);
 * each backing string IS the wire value the server emits in a
 * `calling.call.state` event's `call_state` / `state` field. {@see Call}
 * keeps the bare-string `$state` property for mirrors the reference
 * (which models state as a plain `str`); this enum is offered ALONGSIDE it via
 * the typed {@see Call::callState()} accessor — autocompletion and an
 * exhaustive `match` at the call site, with the string preserved.
 *
 *     $call->state;          // 'answered'              (string)
 *     $call->callState();    // CallState::Answered     (typed)
 *     $call->callState()?->isTerminal();  // false
 *
 * Server states can GROW (the wire vocabulary is owned by the server, not the
 * SDK), so {@see CallState::tryFromWire()} maps an unknown value to `null`
 * rather than throwing — a forward-compatible coerce. This is a PHP
 * PORT_ADDITION: the Python reference uses a bare `str`.
 *
 * This is the CALL-state vocabulary ONLY. It is deliberately NOT unified with
 * {@see DialState} (dial-outcome) or {@see MessageState} (message-delivery) —
 * three distinct vocabularies that must never be conflated.
 */
enum CallState: string
{
    /** The call object exists but nothing has happened yet (the default). */
    case Created = 'created';

    /** The call is ringing the destination. */
    case Ringing = 'ringing';

    /** The call has been answered and is up. */
    case Answered = 'answered';

    /** The call is tearing down. */
    case Ending = 'ending';

    /** The call has ended — the one terminal state. */
    case Ended = 'ended';

    /**
     * True once the call has reached its terminal state.
     *
     * Terminal = {@see CallState::Ended} only, matching
     * {@see Constants}::CALL_TERMINAL_STATES (`['ended' => true]`). A terminal
     * state is what resolves every in-flight action on the call.
     */
    public function isTerminal(): bool
    {
        return $this === self::Ended;
    }

    /**
     * Coerce a wire string (or null) to this enum, mapping any value outside
     * the known closed set — or null — to null instead of throwing.
     *
     * Use this when reading a SERVER-emitted state: the wire vocabulary is
     * owned by the server and may grow, so an unrecognised value is not an
     * error — the caller falls back to the raw string. (Contrast PHP's
     * built-in {@see CallState::from()}, which throws \ValueError on a miss.)
     *
     * @param ?string $wire The raw `call_state` / `state` value from the wire.
     */
    public static function tryFromWire(?string $wire): ?self
    {
        return $wire === null ? null : self::tryFrom($wire);
    }
}
