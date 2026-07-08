<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/**
 * `calling.call.dial` — outbound dial progress (answered, failed, no-answer,
 * etc.).
 */
class DialEvent extends RelayEvent
{
    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $call
     */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $tag,
        public readonly string $dialState,
        public readonly array $call,
    ) {
        parent::__construct($eventType, $params, $callId, $timestamp);
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    /** Outbound-dial state (`dialing`|`answered`|`failed`). */
    public function getDialState(): string
    {
        return $this->dialState;
    }

    /** @return array<string,mixed> */
    public function getCall(): array
    {
        return $this->call;
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
            self::pickString($p, 'tag'),
            self::pickString($p, 'dial_state'),
            self::pickArray($p, 'call'),
        );
    }
}
