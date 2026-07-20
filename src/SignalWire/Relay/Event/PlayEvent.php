<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/**
 * `calling.call.play` — play-media action state change (`playing`, `paused`,
 * `finished`, `error`).
 */
class PlayEvent extends RelayEvent
{
    /** @param array<string,mixed> $params */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $controlId,
        public readonly string $state,
    ) {
        parent::__construct($eventType, $params, $callId, $timestamp);
    }

    /** The control ID. */
    public function getControlId(): string
    {
        return $this->controlId;
    }

    /** The state. */
    public function getState(): string
    {
        return $this->state;
    }

    /** @param array<string,mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $b = self::baseFields($payload);
        $p = $b['params'];
        return new self(
            $b['eventType'],
            $p,
            $b['callId'],
            $b['timestamp'],
            self::pickString($p, 'control_id'),
            self::pickString($p, 'state'),
        );
    }
}
