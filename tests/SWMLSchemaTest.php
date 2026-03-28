<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\SWML\Schema;

class SWMLSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        Schema::reset();
    }

    protected function tearDown(): void
    {
        Schema::reset();
    }

    public function testSingleton(): void
    {
        $a = Schema::instance();
        $b = Schema::instance();
        $this->assertSame($a, $b);
    }

    public function testResetCreatesFreshInstance(): void
    {
        $a = Schema::instance();
        Schema::reset();
        $b = Schema::instance();
        $this->assertNotSame($a, $b);
    }

    public function testVerbCountAtLeast38(): void
    {
        $schema = Schema::instance();
        $this->assertGreaterThanOrEqual(38, $schema->verbCount());
    }

    public function testKnownVerbsExist(): void
    {
        $schema = Schema::instance();
        $knownVerbs = [
            'answer', 'ai', 'hangup', 'connect', 'sleep', 'play',
            'record', 'sip_refer', 'send_sms', 'pay', 'tap',
            'transfer', 'record_call', 'set', 'unset', 'cond',
            'switch', 'execute', 'goto', 'label', 'return',
        ];

        foreach ($knownVerbs as $verb) {
            $this->assertTrue(
                $schema->isValidVerb($verb),
                "Expected verb '{$verb}' to be valid"
            );
        }
    }

    public function testUnknownVerbInvalid(): void
    {
        $schema = Schema::instance();
        $this->assertFalse($schema->isValidVerb('nonexistent'));
        $this->assertFalse($schema->isValidVerb(''));
        $this->assertFalse($schema->isValidVerb('foobar'));
    }

    public function testGetVerbMetadata(): void
    {
        $schema = Schema::instance();
        $verb = $schema->getVerb('answer');

        $this->assertNotNull($verb);
        $this->assertSame('answer', $verb['name']);
        $this->assertSame('Answer', $verb['schema_name']);
        $this->assertArrayHasKey('definition', $verb);
    }

    public function testGetVerbNull(): void
    {
        $schema = Schema::instance();
        $this->assertNull($schema->getVerb('nonexistent'));
    }

    public function testGetVerbNames(): void
    {
        $schema = Schema::instance();
        $names = $schema->getVerbNames();

        $this->assertIsArray($names);
        $this->assertGreaterThanOrEqual(38, count($names));
        $this->assertContains('answer', $names);
        $this->assertContains('hangup', $names);
        $this->assertContains('ai', $names);

        // Should be sorted
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    public function testSleepVerbExists(): void
    {
        $schema = Schema::instance();
        $this->assertTrue($schema->isValidVerb('sleep'));

        $verb = $schema->getVerb('sleep');
        $this->assertSame('Sleep', $verb['schema_name']);
    }

    public function testAiVerbExists(): void
    {
        $schema = Schema::instance();
        $this->assertTrue($schema->isValidVerb('ai'));

        $verb = $schema->getVerb('ai');
        $this->assertSame('AI', $verb['schema_name']);
    }

    public function testAll38VerbsPresent(): void
    {
        $schema = Schema::instance();
        $expected = [
            'answer', 'ai', 'amazon_bedrock', 'cond', 'connect', 'denoise',
            'detect_machine', 'enter_queue', 'execute', 'goto', 'hangup',
            'join_conference', 'join_room', 'label', 'live_transcribe',
            'live_translate', 'pay', 'play', 'prompt', 'receive_fax',
            'record', 'record_call', 'request', 'return', 'sip_refer',
            'send_digits', 'send_fax', 'send_sms', 'set', 'sleep',
            'stop_denoise', 'stop_record_call', 'stop_tap', 'switch',
            'tap', 'transfer', 'unset', 'user_event',
        ];

        foreach ($expected as $verb) {
            $this->assertTrue(
                $schema->isValidVerb($verb),
                "Missing expected verb: {$verb}"
            );
        }
    }
}
