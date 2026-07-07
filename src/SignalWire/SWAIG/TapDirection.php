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
 * The three members are the only directions the Python reference's `tap` accepts
 * (`["speak", "hear", "both"]`). Note this set uses `hear`, which differs from
 * {@see RecordDirection} — the `record_call` set uses `listen`. They are
 * deliberately separate enums because the reference validates two distinct lists.
 * The backing values are the exact wire strings.
 *
 *   - `Speak` — audio the far end hears from the agent.
 *   - `Hear`  — audio the agent hears from the far end.
 *   - `Both`  — bidirectional (the default).
 */
enum TapDirection: string
{
    case Speak = 'speak';
    case Hear  = 'hear';
    case Both  = 'both';
}
