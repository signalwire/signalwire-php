<?php

declare(strict_types=1);

namespace SignalWire\Core;

/**
 * One finished conversation leg, in a shape that does not vary by engine.
 *
 * Built by {@see PostPrompt::normalizePostPrompt()} from a post-prompt body sent
 * by either the voice engine or the text-chat engine.
 */
final class NormalizedPostPrompt
{
    /**
     * @param string $medium `conversation_type` as reported, e.g. "voice" or
     *        "chat". Empty string when the engine did not say.
     * @param string|null $conversation_id Present on chat, absent on voice. Null
     *        when the engine did not supply one; callers that need a stable key
     *        should fall back to their own (global_data, call_id).
     * @param array<string, mixed> $summary The parsed `post_prompt_data`, whatever
     *        keys the application's post-prompt asked for. Empty when there was
     *        none or it could not be parsed; a model that answered in prose
     *        yields `['summary' => '<the prose>']`.
     * @param list<array{role: string, content: string}> $dialogue `user` /
     *        `assistant` turns only, with tool calls and the chat engine's
     *        summary echo removed.
     * @param string|null $call_id The platform call id, when present.
     * @param array<string, mixed> $raw The complete request body, untouched.
     */
    public function __construct(
        public readonly string $medium = '',
        public readonly ?string $conversation_id = null,
        public readonly array $summary = [],
        public readonly array $dialogue = [],
        public readonly ?string $call_id = null,
        public readonly array $raw = [],
    ) {
    }
}
