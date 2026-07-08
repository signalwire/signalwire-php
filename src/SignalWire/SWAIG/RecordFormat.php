<?php

declare(strict_types=1);

namespace SignalWire\SWAIG;

/**
 * Recording container format for call recording, as a typed,
 * compile-time-checked closed set.
 *
 * {@see FunctionResult::recordCall()} accepts this enum OR a string for its
 * `$format` argument. The enum gives editor autocompletion and makes a typo
 * fail at the call site; strings keep mirrors the reference
 * (`record_call` takes a bare `str`).
 *
 *     $result->recordCall(format: RecordFormat::Mp3);   // typed
 *     $result->recordCall(format: 'mp3');               // string (for compatibility)
 *
 * The three members are the only formats the Python reference's `record_call`
 * accepts (`["wav", "mp3", "mp4"]` — the SWML `record_call` verb schema; `mp4`
 * is valid here, distinct from the RELAY `record` action's 2-value
 * `audio.format` set). The backing values are the exact wire strings.
 *
 *   - `Wav` — WAV container (the default).
 *   - `Mp3` — MP3 container.
 *   - `Mp4` — MP4 container.
 */
enum RecordFormat: string
{
    case Wav = 'wav';
    case Mp3 = 'mp3';
    case Mp4 = 'mp4';
}
