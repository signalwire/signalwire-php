<?php

declare(strict_types=1);

namespace SignalWire\Relay;

/**
 * RELAY message delivery state, as a typed, compile-time-checked closed set.
 *
 * A {@see Message} progresses through these states as the server reports
 * delivery via `messaging.state` events:
 *
 *     queued → initiated → sent → (delivered | undelivered | failed)
 *
 * plus `received` for an inbound message. The seven members are exactly the
 * {@see Constants}::MESSAGE_STATE_* tokens (grounded in the Python reference's
 * `relay/constants.py` MESSAGE_STATE_* + MESSAGE_TERMINAL_STATES); each
 * backing string IS the wire value the server emits in the `message_state` /
 * `state` field. {@see Message} keeps the bare-string `getState()` accessor
 * for mirrors the reference (which models state as a plain `str`);
 * this enum is offered ALONGSIDE it via the typed
 * {@see Message::messageState()} accessor.
 *
 *     $msg->getState();          // 'delivered'                (string)
 *     $msg->messageState();      // MessageState::Delivered     (typed)
 *     $msg->messageState()?->isTerminal();  // true
 *
 * Server states can GROW, so {@see MessageState::tryFromWire()} maps an
 * unknown value to `null` rather than throwing — a forward-compatible coerce.
 * This is a PHP PORT_ADDITION: the Python reference uses a bare `str`.
 *
 * This is the MESSAGE-delivery vocabulary ONLY. It is deliberately NOT unified
 * with {@see CallState} (call-lifecycle) or {@see DialState} (dial-outcome) —
 * three distinct vocabularies that must never be conflated. (Note `failed`
 * appears in both MessageState and DialState but means different things —
 * message-delivery failure vs dial-outcome failure.)
 */
enum MessageState: string
{
    /** The message is queued for sending (the initial state). */
    case Queued = 'queued';

    /** Sending has been initiated. */
    case Initiated = 'initiated';

    /** The message has left the platform. */
    case Sent = 'sent';

    /** The message was delivered to the carrier/handset (terminal, success). */
    case Delivered = 'delivered';

    /** Delivery was attempted but not confirmed (terminal, failure). */
    case Undelivered = 'undelivered';

    /** The message failed to send (terminal, failure). */
    case Failed = 'failed';

    /** An inbound message was received. */
    case Received = 'received';

    /**
     * True once the message has reached a terminal delivery state.
     *
     * Terminal = {@see MessageState::Delivered}, {@see MessageState::Undelivered},
     * or {@see MessageState::Failed}, matching
     * {@see Constants}::MESSAGE_TERMINAL_STATES — the three states on which
     * {@see Message::dispatchEvent()} auto-resolves the message. Note `received`
     * is NOT terminal here (it mirrors the Python reference, whose
     * MESSAGE_TERMINAL_STATES excludes it).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::Undelivered, self::Failed => true,
            default => false,
        };
    }

    /**
     * Coerce a wire string (or null) to this enum, mapping any value outside
     * the known closed set — or null — to null instead of throwing.
     *
     * Use this when reading a SERVER-emitted `message_state`: the wire
     * vocabulary is owned by the server and may grow, so an unrecognised value
     * is not an error — the caller falls back to the raw string. (Contrast
     * PHP's built-in {@see MessageState::from()}, which throws \ValueError on a
     * miss.)
     *
     * @param ?string $wire The raw `message_state` / `state` value from the wire.
     */
    public static function tryFromWire(?string $wire): ?self
    {
        return $wire === null ? null : self::tryFrom($wire);
    }
}
