<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/**
 * `calling.call.refer` — SIP REFER result (off-platform transfer response
 * codes).
 */
class ReferEvent extends RelayEvent
{
    /** @param array<string,mixed> $params */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $state,
        public readonly string $sipReferTo,
        public readonly string $sipReferResponseCode,
        public readonly string $sipNotifyResponseCode,
    ) {
        parent::__construct($eventType, $params, $callId, $timestamp);
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getSipReferTo(): string
    {
        return $this->sipReferTo;
    }

    public function getSipReferResponseCode(): string
    {
        return $this->sipReferResponseCode;
    }

    public function getSipNotifyResponseCode(): string
    {
        return $this->sipNotifyResponseCode;
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
            self::pickString($p, 'state'),
            self::pickString($p, 'sip_refer_to'),
            self::pickString($p, 'sip_refer_response_code'),
            self::pickString($p, 'sip_notify_response_code'),
        );
    }
}
