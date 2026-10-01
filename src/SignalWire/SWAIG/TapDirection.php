<?php

declare(strict_types=1);

namespace SignalWire\SWAIG;

/**
 * Audio-stream direction for call tapping, as a typed, compile-time-checked
 * closed set.
 *
 * {@see FunctionResult::tap()} accepts this enum OR a string for its
 * `$direction` argument. The enum gives editor autocompletion and makes a typo
 * fail at the call site; strings keep mirrors the reference (`tap`
 * takes a bare `str`).
 *
 *     $result->tap($uri, direction: TapDirection::Speak);   // typed
 *     $result->tap($uri, direction: 'speak');               // string (for compatibility)
 *
 * The three members are the tap verb's directions (`["speak", "listen",
 * "both"]`), the same set {@see RecordDirection} uses for `record_call`. The
 * backing values are the exact wire strings.
 *
 *   - `Speak`  — audio the far end hears from the agent.
 *   - `Listen` — audio the agent hears from the far end.
 *   - `Both`   — bidirectional (the default).
 */
enum TapDirection: string
{
    case Speak  = 'speak';
    case Listen = 'listen';
    case Both   = 'both';
}
