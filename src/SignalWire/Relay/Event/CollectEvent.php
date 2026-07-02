<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/**
 * `calling.call.collect` — caller input (DTMF or speech) collected by a collect
 * action.
 */
class CollectEvent extends RelayEvent
{
    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $result
     */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $controlId,
        public readonly string $state,
        public readonly array $result,
        public readonly ?bool $final,
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

    /** @return array<string,mixed> */
    public function getResult(): array
    {
        return $this->result;
    }

    /** Whether this is the final collect result (`null` when the field is absent). */
    public function getFinal(): ?bool
    {
        return $this->final;
    }

    /** @param array<string,mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $b = self::baseFields($payload);
        $p = $b['params'];
        $finalRaw = $p['final'] ?? null;
        return new self(
            $b['eventType'],
            $p,
            $b['callId'],
            $b['timestamp'],
            self::pickString($p, 'control_id'),
            self::pickString($p, 'state'),
            self::pickArray($p, 'result'),
            is_bool($finalRaw) ? $finalRaw : null,
        );
    }
}
