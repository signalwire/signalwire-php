<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/**
 * `calling.call.record` — recording state change with final URL, duration, and
 * size when finished.
 */
class RecordEvent extends RelayEvent
{
    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $record
     */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $controlId,
        public readonly string $state,
        public readonly string $url,
        public readonly float $duration,
        public readonly int $size,
        public readonly array $record,
    ) {
        parent::__construct($eventType, $params, $callId, $timestamp);
    }

    public function getControlId(): string
    {
        return $this->controlId;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    /** @return array<string,mixed> */
    public function getRecord(): array
    {
        return $this->record;
    }

    /** @param array<string,mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $b = self::baseFields($payload);
        $p = $b['params'];
        $rec = self::pickArray($p, 'record');
        return new self(
            $b['eventType'],
            $p,
            $b['callId'],
            $b['timestamp'],
            self::pickString($p, 'control_id'),
            self::pickString($p, 'state'),
            self::pickString($rec, 'url', self::pickString($p, 'url')),
            self::pickFloat($rec, 'duration', self::pickFloat($p, 'duration')),
            self::pickInt($rec, 'size', self::pickInt($p, 'size')),
            $rec,
        );
    }
}
