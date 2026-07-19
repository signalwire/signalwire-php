<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/** `messaging.receive` — inbound SMS/MMS received on a subscribed context. */
class MessageReceiveEvent extends RelayEvent
{
    /**
     * @param array<string,mixed> $params
     * @param list<string>        $media
     * @param list<string>        $tags
     */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $messageId,
        public readonly string $context,
        public readonly string $direction,
        public readonly string $fromNumber,
        public readonly string $toNumber,
        public readonly string $body,
        public readonly array $media,
        public readonly int $segments,
        public readonly string $messageState,
        public readonly array $tags,
    ) {
        parent::__construct($eventType, $params, $callId, $timestamp);
    }

    /** The message ID. */
    public function getMessageId(): string
    {
        return $this->messageId;
    }

    /** The context. */
    public function getContext(): string
    {
        return $this->context;
    }

    /** The direction. */
    public function getDirection(): string
    {
        return $this->direction;
    }

    /** The from number. */
    public function getFromNumber(): string
    {
        return $this->fromNumber;
    }

    /** The to number. */
    public function getToNumber(): string
    {
        return $this->toNumber;
    }

    /** The body. */
    public function getBody(): string
    {
        return $this->body;
    }

    /** @return list<string> */
    public function getMedia(): array
    {
        return $this->media;
    }

    /** The segments. */
    public function getSegments(): int
    {
        return $this->segments;
    }

    /** The message state. */
    public function getMessageState(): string
    {
        return $this->messageState;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
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
            self::pickString($p, 'message_id'),
            self::pickString($p, 'context'),
            self::pickString($p, 'direction'),
            self::pickString($p, 'from_number'),
            self::pickString($p, 'to_number'),
            self::pickString($p, 'body'),
            self::pickStringList($p, 'media'),
            self::pickInt($p, 'segments'),
            self::pickString($p, 'message_state'),
            self::pickStringList($p, 'tags'),
        );
    }
}
