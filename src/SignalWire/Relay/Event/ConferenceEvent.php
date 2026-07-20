<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/** `calling.conference` — conference lifecycle change (created, active, ended). */
class ConferenceEvent extends RelayEvent
{
    /** @param array<string,mixed> $params */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $conferenceId,
        public readonly string $name,
        public readonly string $status,
    ) {
        parent::__construct($eventType, $params, $callId, $timestamp);
    }

    /** The conference ID. */
    public function getConferenceId(): string
    {
        return $this->conferenceId;
    }

    /** The name. */
    public function getName(): string
    {
        return $this->name;
    }

    /** The status. */
    public function getStatus(): string
    {
        return $this->status;
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
            self::pickString($p, 'conference_id'),
            self::pickString($p, 'name'),
            self::pickString($p, 'status'),
        );
    }
}
