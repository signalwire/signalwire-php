<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/** `calling.call.fax` — fax send/receive progress update. */
class FaxEvent extends RelayEvent
{
    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $fax
     */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $controlId,
        public readonly array $fax,
    ) {
        parent::__construct($eventType, $params, $callId, $timestamp);
    }

    public function getControlId(): string
    {
        return $this->controlId;
    }

    /** @return array<string,mixed> */
    public function getFax(): array
    {
        return $this->fax;
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
            self::pickArray($p, 'fax'),
        );
    }
}
