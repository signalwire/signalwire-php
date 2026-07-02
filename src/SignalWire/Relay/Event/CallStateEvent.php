<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/**
 * `calling.call.state` — fires on every lifecycle transition (created,
 * ringing, answered, ending, ended).
 */
class CallStateEvent extends RelayEvent
{
    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $device
     */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $callState,
        public readonly string $endReason,
        public readonly string $direction,
        public readonly array $device,
    ) {
        parent::__construct($eventType, $params, $callId, $timestamp);
    }

    /** The new call state (`created`|`ringing`|`answered`|`ending`|`ended`). */
    public function getCallState(): string
    {
        return $this->callState;
    }

    public function getEndReason(): string
    {
        return $this->endReason;
    }

    public function getDirection(): string
    {
        return $this->direction;
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
            self::pickString($p, 'call_state'),
            self::pickString($p, 'end_reason'),
            self::pickString($p, 'direction'),
            self::pickArray($p, 'device'),
        );
    }
}
