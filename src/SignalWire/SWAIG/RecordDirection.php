<?php

declare(strict_types=1);

namespace SignalWire\SWAIG;

/**
 * Audio-stream direction for call recording, as a typed, compile-time-checked
 * closed set.
 *
 * {@see FunctionResult::recordCall()} accepts this enum OR a string for its
 * `$direction` argument. The enum gives editor autocompletion and makes a typo
 * fail at the call site; strings keep mirrors the reference
 * (`record_call` takes a bare `str`).
 *
 *     $result->recordCall(direction: RecordDirection::Listen);   // typed
 *     $result->recordCall(direction: 'listen');                  // string (for compatibility)
 *
 * The three members are the only directions the Python reference's `record_call`
 * accepts (`["speak", "listen", "both"]`). Note this set uses `listen`, which
 * differs from {@see TapDirection} — the `tap` set uses `hear`. They are
 * deliberately separate enums because the reference validates two distinct lists.
 * The backing values are the exact wire strings.
 *
 *   - `Speak`  — audio the far end hears from the agent.
 *   - `Listen` — audio the agent hears from the far end.
 *   - `Both`   — bidirectional (the default).
 */
enum RecordDirection: string
{
    case Speak  = 'speak';
    case Listen = 'listen';
    case Both   = 'both';
}
