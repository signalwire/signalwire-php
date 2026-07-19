<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/**
 * `calling.call.connect` — state transition when one call connects to another
 * (dialplan/bridge).
 */
class ConnectEvent extends RelayEvent
{
    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $peer
     */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $connectState,
        public readonly array $peer,
    ) {
        parent::__construct($eventType, $params, $callId, $timestamp);
    }

    /** The connect state. */
    public function getConnectState(): string
    {
        return $this->connectState;
    }

    /** @return array<string,mixed> */
    public function getPeer(): array
    {
        return $this->peer;
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
            self::pickString($p, 'connect_state'),
            self::pickArray($p, 'peer'),
        );
    }
}
