<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/** `calling.error` — platform-emitted error against the calling namespace. */
class CallingErrorEvent extends RelayEvent
{
    /** @param array<string,mixed> $params */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $code,
        public readonly string $message,
    ) {
        parent::__construct($eventType, $params, $callId, $timestamp);
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
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
            self::pickString($p, 'code'),
            self::pickString($p, 'message'),
        );
    }
}
