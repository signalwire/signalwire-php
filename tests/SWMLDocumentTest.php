<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\SWML\Document;

class SWMLDocumentTest extends TestCase
{
    public function testDefaultVersion(): void
    {
        $doc = new Document();
        $this->assertSame('1.0.0', $doc->getVersion());
    }

    public function testDefaultMainSection(): void
    {
        $doc = new Document();
        $this->assertTrue($doc->hasSection('main'));
        $this->assertSame([], $doc->getVerbs('main'));
    }

    public function testAddSection(): void
    {
        $doc = new Document();
        $this->assertTrue($doc->addSection('custom'));
        $this->assertTrue($doc->hasSection('custom'));
        $this->assertSame([], $doc->getVerbs('custom'));
    }

    public function testAddSectionDuplicate(): void
    {
        $doc = new Document();
        $this->assertTrue($doc->addSection('custom'));
        $this->assertFalse($doc->addSection('custom'));
    }

    public function testHasSectionFalse(): void
    {
        $doc = new Document();
        $this->assertFalse($doc->hasSection('nonexistent'));
    }

    public function testAddVerb(): void
    {
        $doc = new Document();
        $doc->addVerb('answer', ['max_duration' => 3600]);

        $verbs = $doc->getVerbs('main');
        $this->assertCount(1, $verbs);
        $this->assertSame(['answer' => ['max_duration' => 3600]], $verbs[0]);
    }

    public function testAddMultipleVerbs(): void
    {
        $doc = new Document();
        $doc->addVerb('answer', ['max_duration' => 3600]);
        $doc->addVerb('hangup', []);

        $verbs = $doc->getVerbs('main');
        $this->assertCount(2, $verbs);
        $this->assertSame(['answer' => ['max_duration' => 3600]], $verbs[0]);
        $this->assertSame(['hangup' => []], $verbs[1]);
    }

    public function testAddVerbToSection(): void
    {
        $doc = new Document();
        $doc->addSection('custom');
        $doc->addVerbToSection('custom', 'play', ['url' => 'https://example.com/audio.mp3']);

        $verbs = $doc->getVerbs('custom');
        $this->assertCount(1, $verbs);
        $this->assertSame(['play' => ['url' => 'https://example.com/audio.mp3']], $verbs[0]);
    }

    public function testAddVerbToNonexistentSection(): void
    {
        $doc = new Document();
        $this->expectException(\InvalidArgumentException::class);
        $doc->addVerbToSection('missing', 'answer', []);
    }

    public function testAddRawVerb(): void
    {
        $doc = new Document();
        $doc->addRawVerb('main', ['answer' => ['max_duration' => 3600]]);

        $verbs = $doc->getVerbs('main');
        $this->assertCount(1, $verbs);
        $this->assertSame(['answer' => ['max_duration' => 3600]], $verbs[0]);
    }

    public function testClearSection(): void
    {
        $doc = new Document();
        $doc->addVerb('answer', []);
        $doc->addVerb('hangup', []);
        $this->assertCount(2, $doc->getVerbs('main'));

        $doc->clearSection('main');
        $this->assertSame([], $doc->getVerbs('main'));
        $this->assertTrue($doc->hasSection('main'));
    }

    public function testReset(): void
    {
        $doc = new Document();
        $doc->addSection('custom');
        $doc->addVerb('answer', []);
        $doc->addVerbToSection('custom', 'hangup', []);

        $doc->reset();
        $this->assertTrue($doc->hasSection('main'));
        $this->assertFalse($doc->hasSection('custom'));
        $this->assertSame([], $doc->getVerbs('main'));
    }

    public function testSleepVerbInteger(): void
    {
        $doc = new Document();
        $doc->addVerb('sleep', 2000);

        $verbs = $doc->getVerbs('main');
        $this->assertSame(['sleep' => 2000], $verbs[0]);
    }

    public function testToArray(): void
    {
        $doc = new Document();
        $doc->addVerb('answer', ['max_duration' => 3600]);
        $doc->addVerb('hangup', []);

        $arr = $doc->toArray();
        $this->assertSame('1.0.0', $arr['version']);
        $this->assertArrayHasKey('main', $arr['sections']);
        $this->assertCount(2, $arr['sections']['main']);
    }

    public function testRender(): void
    {
        $doc = new Document();
        $doc->addVerb('hangup', []);

        $json = $doc->render();
        $decoded = json_decode($json, true);
        $this->assertSame('1.0.0', $decoded['version']);
        $this->assertCount(1, $decoded['sections']['main']);
    }

    public function testRenderPretty(): void
    {
        $doc = new Document();
        $doc->addVerb('hangup', []);

        $json = $doc->renderPretty();
        // Pretty JSON should contain newlines
        $this->assertStringContainsString("\n", $json);
        $decoded = json_decode($json, true);
        $this->assertSame('1.0.0', $decoded['version']);
    }

    public function testJsonRoundTrip(): void
    {
        $doc = new Document();
        $doc->addVerb('answer', ['max_duration' => 3600]);
        $doc->addVerb('sleep', 2000);
        $doc->addVerb('hangup', []);

        $json = $doc->render();
        $decoded = json_decode($json, true);

        $this->assertSame('1.0.0', $decoded['version']);
        $verbs = $decoded['sections']['main'];
        $this->assertCount(3, $verbs);
        $this->assertSame(['answer' => ['max_duration' => 3600]], $verbs[0]);
        $this->assertSame(['sleep' => 2000], $verbs[1]);
        $this->assertSame(['hangup' => []], $verbs[2]);
    }

    public function testGetVerbsNonexistentSection(): void
    {
        $doc = new Document();
        $this->assertSame([], $doc->getVerbs('missing'));
    }

    public function testGetVerbsReturnsCopy(): void
    {
        $doc = new Document();
        $doc->addVerb('answer', []);

        $verbs = $doc->getVerbs('main');
        $verbs[] = ['extra' => 'should not affect original'];

        $this->assertCount(1, $doc->getVerbs('main'));
    }
}
