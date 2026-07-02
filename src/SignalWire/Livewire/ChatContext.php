<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

/**
 * Minimal `ChatContext` mirroring livekit's `ChatContext`.
 *
 * Holds an ordered list of `{role, content}` messages. `append()` returns
 * `$this` for fluent chaining (matching the Python/TS shim).
 */
class ChatContext
{
    /**
     * Ordered chat messages, each `['role' => ..., 'content' => ...]`.
     *
     * @var list<array{role: string, content: string}>
     */
    public array $messages;

    public function __construct()
    {
        $this->messages = [];
    }

    /**
     * Append a chat message.
     *
     * @param string $role Speaker role ("user", "assistant", or "system").
     * @param string $text Message text.
     */
    public function append(string $role = 'user', string $text = ''): self
    {
        $this->messages[] = ['role' => $role, 'content' => $text];
        return $this;
    }
}
