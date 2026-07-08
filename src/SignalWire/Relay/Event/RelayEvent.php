<?php

declare(strict_types=1);

namespace SignalWire\Relay\Event;

/**
 * Base class for all typed RELAY events.
 *
 * These are convenience wrappers over the raw `signalwire.event` params dict.
 * Every Call/Message event handler also accepts the raw {@see \SignalWire\Relay\Event}
 * (or a bare array), so the typed hierarchy is OPTIONAL — a richer, typed view
 * of the same wire payload. Raw events arrive as `signalwire.event` JSON-RPC
 * notifications; {@see parseEvent()} looks up the correct subclass in the
 * event-type map and invokes {@see RelayEvent::fromPayload()} to build a typed
 * wrapper. Handlers can always read the original dict from {@see getParams()}.
 *
 * Mirrors the Python reference `signalwire.relay.event.RelayEvent` and the
 * TypeScript `RelayEvent` (the canonical shape): same `from_payload`/`fromPayload`
 * factory, same field set, same `value ?? default` boundary-read semantics.
 *
 * The fields are `readonly` (PHP 8.1): handlers only ever read an event, so an
 * accidental write after construction is a hard `\Error` rather than silent
 * state corruption.
 */
class RelayEvent
{
    /**
     * @param string              $eventType Fully-qualified event type (e.g.
     *   `"calling.call.state"`).
     * @param array<string,mixed> $params    Raw params dict from the RELAY
     *   notification.
     * @param string              $callId    Call ID associated with the event,
     *   or `""` for non-call events.
     * @param float               $timestamp Server timestamp (epoch seconds) at
     *   which the event was emitted.
     */
    public function __construct(
        public readonly string $eventType,
        public readonly array $params,
        public readonly string $callId = '',
        public readonly float $timestamp = 0.0,
    ) {
    }

    /** Fully-qualified event type (e.g. `"calling.call.state"`). */
    public function getEventType(): string
    {
        return $this->eventType;
    }

    /** @return array<string,mixed> The raw event params dict. */
    public function getParams(): array
    {
        return $this->params;
    }

    /** Call ID associated with the event, or `""` for non-call events. */
    public function getCallId(): string
    {
        return $this->callId;
    }

    /** Server-side event timestamp (epoch seconds). */
    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    /**
     * Read a known field off an open params dict, applying `value ?? fallback`
     * (identical runtime semantics to the TypeScript `pick` boundary accessor
     * and Python's `params.get(key, default)`).
     *
     * @param array<string,mixed> $params
     * @template T
     * @param T $fallback
     * @return T
     */
    protected static function pick(array $params, string $key, mixed $fallback): mixed
    {
        /** @var T */
        return $params[$key] ?? $fallback;
    }

    /** @param array<string,mixed> $params */
    protected static function pickString(array $params, string $key, string $fallback = ''): string
    {
        $value = $params[$key] ?? $fallback;
        return is_string($value) ? $value : $fallback;
    }

    /** @param array<string,mixed> $params */
    protected static function pickFloat(array $params, string $key, float $fallback = 0.0): float
    {
        $value = $params[$key] ?? $fallback;
        return is_int($value) || is_float($value) ? (float) $value : $fallback;
    }

    /** @param array<string,mixed> $params */
    protected static function pickInt(array $params, string $key, int $fallback = 0): int
    {
        $value = $params[$key] ?? $fallback;
        return is_int($value) ? $value : $fallback;
    }

    /** @param array<string,mixed> $params */
    protected static function pickBool(array $params, string $key, bool $fallback = false): bool
    {
        $value = $params[$key] ?? $fallback;
        return is_bool($value) ? $value : $fallback;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    protected static function pickArray(array $params, string $key): array
    {
        $value = $params[$key] ?? [];
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string,mixed> $params
     * @return list<string>
     */
    protected static function pickStringList(array $params, string $key): array
    {
        $value = $params[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * Read the four base fields ({eventType, params, callId, timestamp}) off a
     * raw `signalwire.event` payload, mirroring the TS `baseFields` helper and
     * Python's `RelayEvent.from_payload`.
     *
     * @param array<string,mixed> $payload
     * @return array{eventType: string, params: array<string,mixed>, callId: string, timestamp: float}
     */
    protected static function baseFields(array $payload): array
    {
        $eventType = self::pickString($payload, 'event_type');
        $paramsRaw = $payload['params'] ?? [];
        $params = is_array($paramsRaw) ? $paramsRaw : [];
        return [
            'eventType' => $eventType,
            'params'    => $params,
            'callId'    => self::pickString($params, 'call_id'),
            'timestamp' => self::pickFloat($params, 'timestamp'),
        ];
    }

    /**
     * Factory that builds a typed event from a raw `signalwire.event` payload.
     * Subclasses override this to populate their specialised fields; the base
     * implementation returns a minimal {@see RelayEvent} used as the fallback
     * for unrecognised event types.
     *
     * @param array<string,mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $b = self::baseFields($payload);
        return new self($b['eventType'], $b['params'], $b['callId'], $b['timestamp']);
    }

    /**
     * Maps RELAY `event_type` strings to the typed event subclass that builds
     * its wrapper. Used by {@see parseEvent()} to dispatch raw payloads.
     *
     * Mirrors the Python reference `EVENT_CLASS_MAP` and the TypeScript
     * `EVENT_CLASS_MAP` (the canonical shape). — same 23 event-type → subclass
     * entries.
     *
     * @return array<string, class-string<RelayEvent>>
     */
    private static function eventClassMap(): array
    {
        return [
            'calling.call.state'       => CallStateEvent::class,
            'calling.call.receive'     => CallReceiveEvent::class,
            'calling.call.play'        => PlayEvent::class,
            'calling.call.record'      => RecordEvent::class,
            'calling.call.collect'     => CollectEvent::class,
            'calling.call.connect'     => ConnectEvent::class,
            'calling.call.detect'      => DetectEvent::class,
            'calling.call.fax'         => FaxEvent::class,
            'calling.call.tap'         => TapEvent::class,
            'calling.call.stream'      => StreamEvent::class,
            'calling.call.send_digits' => SendDigitsEvent::class,
            'calling.call.dial'        => DialEvent::class,
            'calling.call.refer'       => ReferEvent::class,
            'calling.call.denoise'     => DenoiseEvent::class,
            'calling.call.pay'         => PayEvent::class,
            'calling.call.queue'       => QueueEvent::class,
            'calling.call.echo'        => EchoEvent::class,
            'calling.call.transcribe'  => TranscribeEvent::class,
            'calling.call.hold'        => HoldEvent::class,
            'calling.conference'       => ConferenceEvent::class,
            'calling.error'            => CallingErrorEvent::class,
            'messaging.receive'        => MessageReceiveEvent::class,
            'messaging.state'          => MessageStateEvent::class,
        ];
    }

    /**
     * Parse a raw `signalwire.event` payload into the right typed event object.
     *
     * Looks up the `event_type` in the event-class map and delegates to that
     * subclass's {@see fromPayload()}; unrecognised types fall back to a base
     * {@see RelayEvent}. Mirrors the Python reference module-level
     * `signalwire.relay.event.parse_event` and the TypeScript `parseEvent`
     * free function (the canonical shape). PHP has no module-level free functions
     * (PSR-4 file-per-class), so it is hosted as a static method on the base
     * event class — projected back to the Python module-level free function by
     * the surface/signature adapters (see PORT_ADDITIONS.md / PORT_OMISSIONS.md).
     *
     * @param array<string,mixed> $payload
     */
    public static function parseEvent(array $payload): RelayEvent
    {
        $eventType = self::pickString($payload, 'event_type');
        $cls = self::eventClassMap()[$eventType] ?? RelayEvent::class;
        return $cls::fromPayload($payload);
    }
}
