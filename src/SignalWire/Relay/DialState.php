<?php

declare(strict_types=1);

namespace SignalWire\Relay;

/**
 * RELAY dial outcome state, as a typed, compile-time-checked closed set.
 *
 * A `calling.call.dial` event carries a `dial_state` (or legacy `state`) that
 * reports how the outbound dial is progressing:
 *
 *     dialing → (answered | failed | no_answer | busy)
 *
 * The Python reference's `RelayClient` documents the core three
 * (`dialing | answered | failed`, see `relay/client.py`'s dial-event handler);
 * this port's {@see Client::handleDialEvent()} additionally treats the wire's
 * `no_answer` / `busy` as terminal failures, so they are modelled here too.
 * Each backing string IS the wire value. {@see Constants}::DIAL_STATE_* keeps
 * the bare-string constants for parity (the Python reference reads
 * `dial_state` as a plain `str`); this enum is offered ALONGSIDE them.
 *
 *     DialState::Answered->value;            // 'answered'  (wire string)
 *     DialState::tryFromWire('failed');      // DialState::Failed
 *     DialState::Failed->isTerminal();       // true
 *     DialState::Dialing->isTerminal();      // false (still in progress)
 *
 * Server states can GROW, so {@see DialState::tryFromWire()} maps an unknown
 * value to `null` rather than throwing — a forward-compatible coerce. This is
 * a PHP PORT_ADDITION: the Python reference uses a bare `str`.
 *
 * This is the DIAL-outcome vocabulary ONLY. It is deliberately NOT unified
 * with {@see CallState} (call-lifecycle) or {@see MessageState}
 * (message-delivery) — three distinct vocabularies that must never be
 * conflated. (Note `answered` appears in both CallState and DialState but
 * means different things — a call lifecycle phase vs a dial outcome — which is
 * exactly why the vocabularies are kept apart.)
 */
enum DialState: string
{
    /** The dial is in progress, no leg has been picked yet. */
    case Dialing = 'dialing';

    /** A leg answered — the dial succeeded with a winner. */
    case Answered = 'answered';

    /** The dial failed outright. */
    case Failed = 'failed';

    /** No leg answered before the dial timed out. */
    case NoAnswer = 'no_answer';

    /** Every leg returned busy. */
    case Busy = 'busy';

    /**
     * True once the dial has reached a terminal outcome.
     *
     * Terminal = any state other than {@see DialState::Dialing} — i.e. a
     * winner answered ({@see DialState::Answered}) or the dial gave up
     * (failed / no-answer / busy). This mirrors
     * {@see Client::handleDialEvent()}, which resolves (or rejects) the
     * pending dial on exactly these states and keeps waiting while `dialing`.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Dialing;
    }

    /**
     * Coerce a wire string (or null) to this enum, mapping any value outside
     * the known closed set — or null — to null instead of throwing.
     *
     * Use this when reading a SERVER-emitted `dial_state`: the wire vocabulary
     * is owned by the server and may grow, so an unrecognised value is not an
     * error — the caller falls back to the raw string. (Contrast PHP's
     * built-in {@see DialState::from()}, which throws \ValueError on a miss.)
     *
     * @param ?string $wire The raw `dial_state` / `state` value from the wire.
     */
    public static function tryFromWire(?string $wire): ?self
    {
        return $wire === null ? null : self::tryFrom($wire);
    }
}
