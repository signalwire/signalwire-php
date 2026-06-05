<?php

declare(strict_types=1);

namespace SignalWire\Relay;

/**
 * A RELAY `{type, params}` device descriptor, as an immutable typed value
 * object.
 *
 * The device object is the single most-recurring blob in the RELAY calling
 * layer: it identifies WHERE a call leg, tap delivery, or referral goes
 * (a phone number, a SIP endpoint, a WebRTC client, an RTP sink, …) and rides
 * inside `calling.connect` / `calling.dial` / `calling.tap` / `calling.refer`
 * params. Its shape is grounded in the switchblade-extracted wire schemas
 * (`relay-protocol/calling.{connect,dial,tap,refer}.params.json`): an object
 * with a required `type` string and a `params` payload.
 *
 * The Python reference (and this port until now) passes that object as a raw
 * associative array. This value object types the SHAPE — readonly,
 * constructor-promoted (consistent with the Wave-A {@see Event} immutability
 * work) — while keeping `type` a bare `string`: the discriminant is NOT
 * schema-enumerated (the set of device types is open — phone / sip / webrtc /
 * rtp / agent / …), so per the idiom philosophy it stays a string, not an enum.
 *
 * It is purely ADDITIVE: every method that takes a device still accepts the
 * raw array; {@see Device::toArray()} produces the byte-identical
 * `['type' => …, 'params' => …]` wire array, so a `Device` is a drop-in for the
 * hand-written literal. PORT_ADDITION — the Python reference has no equivalent.
 *
 *     // hand-written (still works):
 *     ['type' => 'phone', 'params' => ['to_number' => $to, 'from_number' => $frm]]
 *
 *     // typed, identical on the wire:
 *     (new Device('phone', ['to_number' => $to, 'from_number' => $frm]))->toArray()
 *     Device::phone($to, $frm)->toArray();   // convenience for the common case
 */
final class Device
{
    /**
     * @param string              $type   The device discriminant (`phone`,
     *   `sip`, `webrtc`, `rtp`, …). Kept a bare string — the set is open and
     *   not schema-enumerated.
     * @param array<string,mixed> $params The device parameters (e.g.
     *   `to_number` / `from_number` for a phone, `to` for a SIP referral).
     */
    public function __construct(
        public readonly string $type,
        public readonly array $params = [],
    ) {
    }

    /**
     * Render the device to its wire array — byte-identical to the
     * hand-written `['type' => …, 'params' => …]` literal (same keys, same
     * order). This is what {@see Call::connect()} / {@see Client::dial()} /
     * {@see Call::tap()} / {@see Call::refer()} put on the wire.
     *
     * @return array{type: string, params: array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'type'   => $this->type,
            'params' => $this->params,
        ];
    }

    /**
     * Convenience constructor for the most common device: a PSTN phone leg.
     *
     * Mirrors the `['type' => 'phone', 'params' => ['to_number' => …,
     * 'from_number' => …]]` literal callers hand-write today.
     *
     * @param array<string,mixed> $extra Additional `params` keys (e.g.
     *   `timeout`) merged after the numbers.
     */
    public static function phone(string $toNumber, string $fromNumber, array $extra = []): self
    {
        return new self('phone', [
            'to_number'   => $toNumber,
            'from_number' => $fromNumber,
        ] + $extra);
    }

    /**
     * Convenience constructor for a SIP endpoint device.
     *
     * @param array<string,mixed> $extra Additional `params` keys merged after
     *   the address (e.g. `from`, `headers`, `codecs`).
     */
    public static function sip(string $to, array $extra = []): self
    {
        return new self('sip', ['to' => $to] + $extra);
    }
}
