<?php

declare(strict_types=1);

namespace SignalWire\Relay;

class Event
{
    private string $eventType;
    private float $timestamp;
    private array $params;

    public function __construct(string $eventType, array $params, float $timestamp = 0)
    {
        $this->eventType = $eventType;
        $this->params = $params;
        $this->timestamp = $timestamp ?: microtime(true);
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function getCallId(): ?string
    {
        return $this->params['call_id'] ?? null;
    }

    public function getNodeId(): ?string
    {
        return $this->params['node_id'] ?? null;
    }

    public function getControlId(): ?string
    {
        return $this->params['control_id'] ?? null;
    }

    public function getTag(): ?string
    {
        return $this->params['tag'] ?? null;
    }

    public function getState(): ?string
    {
        return $this->params['state'] ?? null;
    }

    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'timestamp' => $this->timestamp,
            'params' => $this->params,
        ];
    }

    public static function parse(string $eventType, array $params): self
    {
        return new self($eventType, $params);
    }
}
