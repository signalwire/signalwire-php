<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/**
 * `calling.call.tap` — media tap state change (audio mirror to an external
 * endpoint).
 */
class TapEvent extends RelayEvent
{
    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $tap
     * @param array<string,mixed> $device
     */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $controlId,
        public readonly string $state,
        public readonly array $tap,
        public readonly array $device,
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

    /** @return array<string,mixed> */
    public function getTap(): array
    {
        return $this->tap;
    }

    /** @return array<string,mixed> */
    public function getDevice(): array
    {
        return $this->device;
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
            self::pickArray($p, 'tap'),
            self::pickArray($p, 'device'),
        );
    }
}
