<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/**
 * `calling.call.stream` — outbound media stream state change (e.g.
 * RTMP/WebSocket streaming).
 */
class StreamEvent extends RelayEvent
{
    /** @param array<string,mixed> $params */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $controlId,
        public readonly string $state,
        public readonly string $url,
        public readonly string $name,
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

    /** The URL. */
    public function getUrl(): string
    {
        return $this->url;
    }

    /** The name. */
    public function getName(): string
    {
        return $this->name;
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
            self::pickString($p, 'url'),
            self::pickString($p, 'name'),
        );
    }
}
