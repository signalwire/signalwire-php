<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

/**
 * Signals a tool execution error back to the LLM.
 *
 * LiveKit-compatible exception (mirrors livekit `ToolError`).
 */
class ToolError extends \Exception
{
}
