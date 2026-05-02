<?php

declare(strict_types=1);

namespace SignalWire\Tests\POM;

use PHPUnit\Framework\TestCase;
use SignalWire\POM\Section;

/**
 * Section-level parity tests. Translation of
 * signalwire-python tests/unit/pom/test_pom_object_model.py::TestSectionBasics
 * (plus targeted coverage for addBody / addBullets / addSubsection that the
 * higher-level ``PromptObjectModelTest`` doesn't already exercise).
 */
class SectionTest extends TestCase
{
    public function testSectionWithTitleOnly(): void
    {
        $s = new Section('Hello');
        $this->assertSame('Hello', $s->title);
        $this->assertSame('', $s->body);
        $this->assertSame([], $s->bullets);
        $this->assertSame([], $s->subsections);
    }

    public function testSectionAddBodyReplaces(): void
    {
        // ``Section.addBody`` is documented to "Add OR REPLACE the body" —
        // calling it overwrites any previous body.
        $s = new Section('X', ['body' => 'initial']);
        $s->addBody('replacement');
        $md = $s->renderMarkdown();
        $this->assertStringContainsString('replacement', $md);
        $this->assertStringNotContainsString('initial', $md);
    }

    public function testSectionAddBulletsAppends(): void
    {
        $s = new Section('X');
        $s->addBullets(['one', 'two']);
        // Calling again must append, not replace.
        $s->addBullets(['three']);
        $this->assertSame(['one', 'two', 'three'], $s->bullets);
        $md = $s->renderMarkdown();
        $this->assertStringContainsString('one', $md);
        $this->assertStringContainsString('two', $md);
        $this->assertStringContainsString('three', $md);
    }

    public function testSectionAddSubsectionReturnsSection(): void
    {
        $parent = new Section('P');
        $child = $parent->addSubsection('C', ['body' => 'cb']);
        $this->assertInstanceOf(Section::class, $child);
        $this->assertSame('C', $child->title);
        $this->assertCount(1, $parent->subsections);
        $this->assertSame($child, $parent->subsections[0]);
    }

    public function testSectionRenderMarkdownExactWithBodyAndBullets(): void
    {
        // Bare Section (no PromptObjectModel wrapper) defaults to level 2.
        $s = new Section('T', ['body' => 'b', 'bullets' => ['x']]);
        $expected = "## T\n\nb\n\n- x\n";
        $this->assertSame($expected, $s->renderMarkdown());
    }

    public function testSectionRenderXmlExactWithBodyAndBullets(): void
    {
        $s = new Section('T', ['body' => 'b', 'bullets' => ['x']]);
        $expected = "<section>\n"
            . "  <title>T</title>\n"
            . "  <body>b</body>\n"
            . "  <bullets>\n"
            . "    <bullet>x</bullet>\n"
            . "  </bullets>\n"
            . "</section>";
        $this->assertSame($expected, $s->renderXml());
    }

    public function testSectionToArrayKeyOrderingTitleBodyBulletsSubsections(): void
    {
        $s = new Section('T', ['body' => 'b', 'bullets' => ['x', 'y']]);
        $sub = $s->addSubsection('Sub', ['body' => 'sb']);
        $arr = $s->toArray();
        $this->assertSame(['title', 'body', 'bullets', 'subsections'], array_keys($arr));
        $this->assertSame('T', $arr['title']);
        $this->assertSame(['x', 'y'], $arr['bullets']);
        $this->assertSame('Sub', $arr['subsections'][0]['title']);
    }

    public function testSectionToArrayOmitsEmptyKeys(): void
    {
        // body == '', bullets == [], subsections == [] -> only ``title``
        $s = new Section('OnlyTitle');
        $this->assertSame(['title' => 'OnlyTitle'], $s->toArray());
    }

    public function testSectionToArrayIncludesNumberedFlagsOnlyWhenTrue(): void
    {
        $s = new Section('S', ['body' => 'b', 'numbered' => true, 'numberedBullets' => true]);
        $arr = $s->toArray();
        $this->assertTrue($arr['numbered']);
        $this->assertTrue($arr['numberedBullets']);

        $plain = new Section('P', ['body' => 'b']);
        $this->assertArrayNotHasKey('numbered', $plain->toArray());
        $this->assertArrayNotHasKey('numberedBullets', $plain->toArray());
    }
}
