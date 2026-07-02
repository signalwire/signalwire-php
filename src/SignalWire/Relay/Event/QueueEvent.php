<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/**
 * `calling.call.queue` — call-queue position update (queued, waiting, member
 * answered, timed out).
 */
class QueueEvent extends RelayEvent
{
    /** @param array<string,mixed> $params */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $controlId,
        public readonly string $status,
        public readonly string $queueId,
        public readonly string $queueName,
        public readonly int $position,
        public readonly int $size,
    ) {
        parent::__construct($eventType, $params, $callId, $timestamp);
    }

    public function getControlId(): string
    {
        return $this->controlId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getQueueId(): string
    {
        return $this->queueId;
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getSize(): int
    {
        return $this->size;
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
            self::pickString($p, 'status'),
            self::pickString($p, 'id'),
            self::pickString($p, 'name'),
            self::pickInt($p, 'position'),
            self::pickInt($p, 'size'),
        );
    }
}
