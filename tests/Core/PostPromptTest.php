<?php

declare(strict_types=1);

namespace SignalWire\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignalWire\Core\PostPrompt;

/**
 * Post-prompt normalization across the voice and chat engines.
 *
 * Ported from signalwire-python tests/unit/core/test_post_prompt_normalize.py.
 * `post_prompt_data` arrives three ways, one of which -- the object wrapped in
 * a list under `parsed` -- survives every structural check while missing every
 * field lookup; and the chat engine appends its own summary to `call_log` as a
 * bare `role: assistant` turn identifiable only by a byte comparison against
 * `post_prompt_data.raw`.
 */
class PostPromptTest extends TestCase
{
    private const FENCED = "```json\n{\"summary\": \"s\", \"already_answered\": [\"pricing\"]}\n```";

    /** @return list<array<string, mixed>|string> */
    private static function log(): array
    {
        return [
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => 'hello'],
            ['role' => 'system', 'content' => 'the prompt'],
            ['role' => 'system-log', 'content' => 'step trace'],
            ['role' => 'tool', 'content' => 'tool output'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 1]]],
            ['role' => 'assistant-manual', 'content' => 'let me look that up'],
            ['role' => 'assistant', 'content' => '   '],
            'not even a dict',
        ];
    }

    // ── parse shapes ─────────────────────────────────────────────────────

    public function testFlatKeysFromTheVoiceEngine(): void
    {
        $this->assertSame(
            ['summary' => 's', 'user_goal' => 'g'],
            PostPrompt::parsePostPromptData(['summary' => 's', 'user_goal' => 'g'])
        );
    }

    public function testFencedRawFromTheChatEngine(): void
    {
        $this->assertSame(
            ['summary' => 's', 'already_answered' => ['pricing']],
            PostPrompt::parsePostPromptData(['raw' => self::FENCED])
        );
    }

    public function testObjectWrappedInAListUnderParsed(): void
    {
        $this->assertSame(
            ['summary' => 's3'],
            PostPrompt::parsePostPromptData(['parsed' => [['summary' => 's3']], 'raw' => '...'])
        );
    }

    public function testParsedWrapperWinsOverTheGenericSweep(): void
    {
        $result = PostPrompt::parsePostPromptData(['parsed' => [['summary' => 's']]]);
        $this->assertArrayNotHasKey('parsed', $result);
    }

    public function testParsedAsABareDict(): void
    {
        $this->assertSame(
            ['summary' => 's'],
            PostPrompt::parsePostPromptData(['parsed' => ['summary' => 's']])
        );
    }

    public function testProseInsteadOfJsonIsKept(): void
    {
        $this->assertSame(
            ['summary' => 'They asked about pricing.'],
            PostPrompt::parsePostPromptData(['raw' => 'They asked about pricing.'])
        );
    }

    public function testJsonThatIsNotAnObject(): void
    {
        $this->assertSame(
            ['summary' => 'just a string'],
            PostPrompt::parsePostPromptData(['raw' => '"just a string"'])
        );
    }

    /** @return list<array{0: mixed}> */
    public static function junkData(): array
    {
        return [[null], [[]], ['text'], [42], [['raw' => '']], [['raw' => '   ']], [['raw' => null]]];
    }

    #[DataProvider('junkData')]
    public function testJunkDegradesRatherThanRaising(mixed $junk): void
    {
        $this->assertSame([], PostPrompt::parsePostPromptData($junk));
    }

    // ── strip fence ──────────────────────────────────────────────────────

    /** @return list<array{0: string, 1: string}> */
    public static function fences(): array
    {
        return [
            ["```json\n{\"a\":1}\n```", '{"a":1}'],
            ["```\nplain\n```", 'plain'],
            ['no fence at all', 'no fence at all'],
            ['', ''],
        ];
    }

    #[DataProvider('fences')]
    public function testStripFenceUnwraps(string $raw, string $expected): void
    {
        $this->assertSame($expected, PostPrompt::stripJsonFence($raw));
    }

    // ── dialogue turns ───────────────────────────────────────────────────

    public function testKeepsOnlyRealDialogue(): void
    {
        $this->assertSame(
            [['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => 'hello']],
            PostPrompt::dialogueTurns(self::log())
        );
    }

    public function testDropsTheChatSummaryEcho(): void
    {
        $log = [...self::log(), ['role' => 'assistant', 'content' => self::FENCED]];
        $this->assertNotContains(
            ['role' => 'assistant', 'content' => self::FENCED],
            PostPrompt::dialogueTurns($log, dropEcho: self::FENCED)
        );
    }

    public function testKeepsTheEchoWhenNotAskedToDropIt(): void
    {
        $log = [...self::log(), ['role' => 'assistant', 'content' => self::FENCED]];
        $this->assertCount(3, PostPrompt::dialogueTurns($log));
    }

    /** @return list<array{0: mixed}> */
    public static function junkLogs(): array
    {
        return [[null], [[]], ['nonsense'], [42]];
    }

    #[DataProvider('junkLogs')]
    public function testJunkLogsYieldNothing(mixed $junk): void
    {
        $this->assertSame([], PostPrompt::dialogueTurns($junk));
    }

    // ── normalize ────────────────────────────────────────────────────────

    public function testVoiceBody(): void
    {
        $result = PostPrompt::normalizePostPrompt([
            'conversation_type' => 'voice',
            'call_id' => 'c-1',
            'post_prompt_data' => ['parsed' => [['summary' => 'v']]],
            'raw_call_log' => [['role' => 'user', 'content' => 'hi']],
        ]);
        $this->assertSame('voice', $result->medium);
        $this->assertNull($result->conversation_id); // voice does not send one
        $this->assertSame(['summary' => 'v'], $result->summary);
        $this->assertSame('c-1', $result->call_id);
        $this->assertCount(1, $result->dialogue);
    }

    public function testChatBody(): void
    {
        $result = PostPrompt::normalizePostPrompt([
            'conversation_type' => 'chat',
            'conversation_id' => 'conv-9',
            'post_prompt_data' => ['raw' => self::FENCED],
            'raw_messages' => [
                ['role' => 'user', 'content' => 'hi'],
                ['role' => 'assistant', 'content' => self::FENCED],
            ],
        ]);
        $this->assertSame('chat', $result->medium);
        $this->assertSame('conv-9', $result->conversation_id);
        $this->assertSame(['pricing'], $result->summary['already_answered'] ?? null);
        // The echo is gone; only the real turn survives.
        $this->assertSame([['role' => 'user', 'content' => 'hi']], $result->dialogue);
    }

    public function testCallLogKeyIsAlsoAccepted(): void
    {
        $result = PostPrompt::normalizePostPrompt(['call_log' => [['role' => 'user', 'content' => 'hi']]]);
        $this->assertCount(1, $result->dialogue);
    }

    /** @return list<array{0: mixed}> */
    public static function junkBodies(): array
    {
        return [[null], ['text'], [42], [[]]];
    }

    #[DataProvider('junkBodies')]
    public function testJunkBodyYieldsEmptyFields(mixed $junk): void
    {
        $result = PostPrompt::normalizePostPrompt($junk);
        $this->assertSame('', $result->medium);
        $this->assertSame([], $result->summary);
        $this->assertSame([], $result->dialogue);
    }

    public function testRawIsPreserved(): void
    {
        $body = ['conversation_type' => 'voice', 'extra' => 'kept'];
        $this->assertSame($body, PostPrompt::normalizePostPrompt($body)->raw);
    }
}
