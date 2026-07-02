<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/** `calling.call.detect` — answering-machine / fax / DTMF detection result. */
class DetectEvent extends RelayEvent
{
    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $detect
     */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $controlId,
        public readonly array $detect,
    ) {
        parent::__construct($eventType, $params, $callId, $timestamp);
    }

    public function getControlId(): string
    {
        return $this->controlId;
    }

    /** @return array<string,mixed> */
    public function getDetect(): array
    {
        return $this->detect;
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
            self::pickArray($p, 'detect'),
        );
    }
}
