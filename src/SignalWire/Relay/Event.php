<?php

declare(strict_types=1);

namespace SignalWire\Relay;

/**
 * An immutable RELAY event value object.
 *
 * An Event carries the event type, its raw params, and a receive timestamp.
 * Once constructed it never changes — the three backing fields are
 * `readonly` (PHP 8.1), so an accidental write after construction is a hard
 * `\Error` rather than silent state corruption. This matches how the event is
 * actually used: handlers (`Call::dispatchEvent`, `Action::handleEvent`,
 * `Message::handleEvent`) only ever *read* it.
 *
 * `eventType` and `params` are promoted constructor parameters; `timestamp`
 * is declared separately because its default is computed (`microtime(true)`
 * when the caller passes 0), which a promoted default expression cannot
 * express.
 *
 * The fields stay `private` (exposed only through the `get*` accessors) so the
 * public surface — and therefore the cross-port audit shape — is unchanged by
 * the immutability upgrade.
 */
class Event
{
    private readonly float $timestamp;

    public function __construct(
        private readonly string $eventType,
        private readonly array $params,
        float $timestamp = 0,
    ) {
        $this->timestamp = $timestamp ?: microtime(true);
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function getCallId(): ?string
    {
        return $this->params['call_id'] ?? null;
    }

    public function getNodeId(): ?string
    {
        return $this->params['node_id'] ?? null;
    }

    public function getControlId(): ?string
    {
        return $this->params['control_id'] ?? null;
    }

    public function getTag(): ?string
    {
        return $this->params['tag'] ?? null;
    }

    public function getState(): ?string
    {
        return $this->params['state'] ?? null;
    }

    /**
     * The dial outcome carried by a `calling.call.dial` event as a typed
     * {@see DialState}, read from the `dial_state` (or legacy `state`) param,
     * or null when absent / outside the known closed set (a forward-compatible
     * server value).
     *
     * Offered ALONGSIDE the raw `getParams()['dial_state']` string for parity:
     * the wire string stays available; `dialState()` is the typed view for
     * autocompletion + an exhaustive `match` + `->isTerminal()`. PORT_ADDITION.
     */
    public function dialState(): ?DialState
    {
        $wire = $this->params['dial_state'] ?? $this->params['state'] ?? null;
        return is_string($wire) ? DialState::tryFromWire($wire) : null;
    }

    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'timestamp' => $this->timestamp,
            'params' => $this->params,
        ];
    }

    public static function parse(string $eventType, array $params): self
    {
        return new self($eventType, $params);
    }
}
