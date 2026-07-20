<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/** `calling.call.denoise` — noise-reduction on/off confirmation. */
class DenoiseEvent extends RelayEvent
{
    /** @param array<string,mixed> $params */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly bool $denoised,
    ) {
        parent::__construct($eventType, $params, $callId, $timestamp);
    }

    /** The denoised. */
    public function getDenoised(): bool
    {
        return $this->denoised;
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
            self::pickBool($p, 'denoised'),
        );
    }
}
