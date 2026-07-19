<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/**
 * `calling.call.receive` — fires when an inbound call arrives on a subscribed
 * context.
 */
class CallReceiveEvent extends RelayEvent
{
    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $device
     */
    public function __construct(
        string $eventType,
        array $params,
        string $callId,
        float $timestamp,
        public readonly string $callState,
        public readonly string $direction,
        public readonly array $device,
        public readonly string $nodeId,
        public readonly string $projectId,
        public readonly string $context,
        public readonly string $segmentId,
        public readonly string $tag,
    ) {
        parent::__construct($eventType, $params, $callId, $timestamp);
    }

    /** The call state. */
    public function getCallState(): string
    {
        return $this->callState;
    }

    /** The direction. */
    public function getDirection(): string
    {
        return $this->direction;
    }

    /** @return array<string,mixed> */
    public function getDevice(): array
    {
        return $this->device;
    }

    /** The node ID. */
    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    /** The project ID. */
    public function getProjectId(): string
    {
        return $this->projectId;
    }

    /** The context. */
    public function getContext(): string
    {
        return $this->context;
    }

    /** The segment ID. */
    public function getSegmentId(): string
    {
        return $this->segmentId;
    }

    /** The tag. */
    public function getTag(): string
    {
        return $this->tag;
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
            self::pickString($p, 'call_state'),
            self::pickString($p, 'direction'),
            self::pickArray($p, 'device'),
            self::pickString($p, 'node_id'),
            self::pickString($p, 'project_id'),
            self::pickString($p, 'context', self::pickString($p, 'protocol')),
            self::pickString($p, 'segment_id'),
            self::pickString($p, 'tag'),
        );
    }
}
