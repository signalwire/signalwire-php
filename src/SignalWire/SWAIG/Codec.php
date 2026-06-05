<?php

declare(strict_types=1);

namespace SignalWire\SWAIG;

/**
 * Media codec for call tapping, as a typed, compile-time-checked closed set.
 *
 * {@see FunctionResult::tap()} accepts this enum OR a string for its `$codec`
 * argument. The enum gives editor autocompletion and makes a typo fail at the
 * call site; strings keep parity with the Python reference (`tap` takes a bare
 * `str`).
 *
 *     $result->tap($uri, codec: Codec::Pcma);   // typed
 *     $result->tap($uri, codec: 'PCMA');        // string (parity)
 *
 * The two members are the only codecs the Python reference's `tap` accepts
 * (`["PCMU", "PCMA"]`). The wire strings are upper-case and matching is
 * case-sensitive, mirroring the reference's literal list.
 *
 * NOTE: this is the SWAIG `tap` codec set ONLY. It is deliberately NOT shared
 * with the RELAY `stream`/`connect` codec superset
 * (`{PCMU,PCMA,OPUS,G729,G722,VP8,H264}`, comma-joinable) — those are a
 * distinct, larger vocabulary and must not be unified with this 2-value set.
 *
 *   - `Pcmu` — `PCMU` (G.711 µ-law, the default).
 *   - `Pcma` — `PCMA` (G.711 A-law).
 */
enum Codec: string
{
    case Pcmu = 'PCMU';
    case Pcma = 'PCMA';
}
