<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/**
 * `calling.call.transcribe` — transcription state change and final URL/duration
 * when finished.
 */
class TranscribeEvent extends RelayEvent
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
        public readonly string $recordingId,
        public readonly float $duration,
        public readonly int $size,
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

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getRecordingId(): string
    {
        return $this->recordingId;
    }

    public function getDuration(): float
    {
        return $this->duration;
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
            self::pickString($p, 'state'),
            self::pickString($p, 'url'),
            self::pickString($p, 'recording_id'),
            self::pickFloat($p, 'duration'),
            self::pickInt($p, 'size'),
        );
    }
}
