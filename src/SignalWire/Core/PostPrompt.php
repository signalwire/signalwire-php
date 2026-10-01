<?php

declare(strict_types=1);

namespace SignalWire\Core;

/**
 * Post-prompt normalization.
 *
 * One conversation can run over voice and over text chat, and both ends produce
 * "the post-prompt" -- but not in the same shape. These helpers absorb that
 * divergence so an application sees one artifact whichever engine finished the
 * conversation:
 *
 * | field              | voice                          | chat                                  |
 * |--------------------|--------------------------------|---------------------------------------|
 * | `app_name`         | `"swml app"`                   | `"ai_chat"`                           |
 * | `conversation_id`  | absent                         | present at top level                  |
 * | full log           | `raw_call_log`                 | `raw_messages`                        |
 * | summary arrives as | `summarize_conversation` call  | a bare `role: assistant` turn         |
 * | `post_prompt_data` | parsed object                  | `{"raw": "```json ...```"}`           |
 *
 * `conversation_type` is a reliable top-level discriminator on both. The voice
 * engine also sends a third `post_prompt_data` shape, `{"parsed": [ {...} ],
 * "raw": "..."}`, which is unwrapped here.
 *
 * Parsing is schema-agnostic: the summary is whatever the application's
 * post-prompt asked the model to produce, returned as found. Nothing here
 * throws -- the conversation that produced the input is already over.
 */
final class PostPrompt
{
    /**
     * Roles that are actual dialogue. Everything else in a call log is
     * machinery: `system` is the prompt, `system-log` is lifecycle tracing,
     * `tool` is function output, `assistant-manual` is filler speech.
     */
    public const DIALOGUE_ROLES = ['user', 'assistant'];

    /**
     * Unwrap ```` ```json ... ``` ```` fencing. The chat engine hands the
     * model's answer back verbatim, fence and all.
     */
    public static function stripJsonFence(string $text): string
    {
        $stripped = trim($text);
        if (str_starts_with($stripped, '```')) {
            $stripped = (string) preg_replace('/^```[a-zA-Z]*\s*/', '', $stripped);
            $stripped = (string) preg_replace('/\s*```$/', '', $stripped);
        }
        return trim($stripped);
    }

    /**
     * Return `post_prompt_data` as a plain array, whichever shape it arrived in.
     *
     * Never throws: a malformed summary degrades to `[]` (or, for prose
     * instead of JSON, `['summary' => '<the prose>']`).
     *
     * @param mixed $data The `post_prompt_data` value from a post-prompt body.
     * @return array<string, mixed>
     */
    public static function parsePostPromptData(mixed $data): array
    {
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            return [];
        }

        $unwrapped = self::unwrapParsed($data);
        if ($unwrapped !== null && $unwrapped !== []) {
            return $unwrapped;
        }

        // Flat shape: real keys already present (anything but raw/parsed).
        $flat = [];
        foreach ($data as $k => $v) {
            if ($k !== 'raw' && $k !== 'parsed') {
                $flat[(string) $k] = $v;
            }
        }
        if ($flat !== []) {
            return $flat;
        }

        $raw = $data['raw'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $unfenced = self::stripJsonFence($raw);
        try {
            $loaded = json_decode($unfenced, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Prose instead of JSON. Still a summary.
            return ['summary' => $unfenced];
        }
        if (is_array($loaded) && (!array_is_list($loaded) || $loaded === [])) {
            return self::stringKeys($loaded);
        }
        return ['summary' => self::pyStr($loaded)];
    }

    /**
     * Extract the real dialogue from a call log.
     *
     * Drops everything that is machinery rather than speech: non-dialogue
     * roles, entries carrying `tool_calls`, and empty content. `$dropEcho`
     * removes the chat engine's post-prompt echo -- a bare `role: assistant`
     * turn byte-identical to `post_prompt_data.raw`.
     *
     * @param mixed $callLog The log, as `call_log` / `raw_call_log` / `raw_messages`.
     * @param list<string> $roles Roles to keep.
     * @param string|null $dropEcho Exact content to treat as the summary echo and drop.
     * @return list<array{role: string, content: string}>
     */
    public static function dialogueTurns(
        mixed $callLog,
        array $roles = self::DIALOGUE_ROLES,
        ?string $dropEcho = null
    ): array {
        if (!is_array($callLog) || !array_is_list($callLog)) {
            return [];
        }
        $out = [];
        $echo = trim($dropEcho ?? '');
        foreach ($callLog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $role = $entry['role'] ?? null;
            if (!is_string($role) || !in_array($role, $roles, true)) {
                continue;
            }
            if (!empty($entry['tool_calls'])) {
                continue;
            }
            $content = $entry['content'] ?? null;
            if (!is_string($content) || trim($content) === '') {
                continue;
            }
            if ($echo !== '' && trim($content) === $echo) {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }
        return $out;
    }

    /**
     * Normalize a post-prompt body from either engine. Never throws; a body
     * this cannot make sense of yields a {@see NormalizedPostPrompt} with
     * empty fields.
     *
     *     $leg = PostPrompt::normalizePostPrompt($rawBody);
     *     if ($leg->dialogue !== []) {
     *         store($leg->conversation_id, $leg->medium, $leg->summary, $leg->dialogue);
     *     }
     *
     * @param mixed $body The complete post-prompt request body.
     */
    public static function normalizePostPrompt(mixed $body): NormalizedPostPrompt
    {
        if (!is_array($body) || array_is_list($body) && $body !== []) {
            return new NormalizedPostPrompt();
        }
        $body = self::stringKeys($body);

        $summary = self::parsePostPromptData($body['post_prompt_data'] ?? null);

        // The echo is compared against the RAW string the engine returned, not
        // the parsed summary -- the assistant turn carries the fence too.
        $rawSummary = '';
        $ppd = $body['post_prompt_data'] ?? null;
        if (is_array($ppd) && is_string($ppd['raw'] ?? null)) {
            $rawSummary = $ppd['raw'];
        }

        $log = [];
        foreach (['call_log', 'raw_call_log', 'raw_messages'] as $key) {
            if (!empty($body[$key])) {
                $log = $body[$key];
                break;
            }
        }

        return new NormalizedPostPrompt(
            medium: self::optionalString($body['conversation_type'] ?? null) ?? '',
            conversation_id: self::optionalString($body['conversation_id'] ?? null),
            summary: $summary,
            dialogue: self::dialogueTurns($log, dropEcho: $rawSummary !== '' ? $rawSummary : null),
            call_id: self::optionalString($body['call_id'] ?? null),
            raw: $body,
        );
    }

    /**
     * Pull the object out of a `{"parsed": [...]}` wrapper, if present.
     *
     * @param array<mixed, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function unwrapParsed(array $data): ?array
    {
        $parsed = $data['parsed'] ?? null;
        if (!is_array($parsed)) {
            return null;
        }
        if (!array_is_list($parsed)) {
            return self::stringKeys($parsed);
        }
        foreach ($parsed as $item) {
            if (is_array($item) && $item !== [] && !array_is_list($item)) {
                return self::stringKeys($item);
            }
        }
        return null;
    }

    /**
     * @param array<mixed, mixed> $a
     * @return array<string, mixed>
     */
    private static function stringKeys(array $a): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    /** A falsy value (null, '', 0) is "not supplied", as the reference reads it. */
    private static function optionalString(mixed $v): ?string
    {
        if ($v === null || $v === '' || $v === false || $v === 0) {
            return null;
        }
        return is_scalar($v) ? (string) $v : null;
    }

    /** String form of a decoded JSON scalar, spelled as the reference prints it. */
    private static function pyStr(mixed $v): string
    {
        if ($v === null) {
            return 'None';
        }
        if (is_bool($v)) {
            return $v ? 'True' : 'False';
        }
        if (is_scalar($v)) {
            return (string) $v;
        }
        return (string) json_encode($v);
    }
}
