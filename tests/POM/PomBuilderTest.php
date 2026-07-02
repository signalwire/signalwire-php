<?php

declare(strict_types=1);

namespace SignalWire\Tests\POM;

use PHPUnit\Framework\TestCase;
use SignalWire\POM\PomBuilder;
use SignalWire\POM\Section;

/**
 * Real-behavior tests for SignalWire\POM\PomBuilder.
 *
 * Mirrors Python's signalwire.core.pom_builder.PomBuilder: section building,
 * auto-vivifying appends, subsections, Markdown/XML rendering, dict/json
 * export, and from_sections reconstruction. Assertions are on rendered output.
 */
final class PomBuilderTest extends TestCase
{
    public function testAddSectionAndRenderMarkdown(): void
    {
        $builder = (new PomBuilder())
            ->addSection('Role', 'You are a helpful agent.')
            ->addSection('Objectives', '', ['Greet the caller', 'Resolve the issue']);

        $md = $builder->renderMarkdown();
        $this->assertStringContainsString('## Role', $md);
        $this->assertStringContainsString('You are a helpful agent.', $md);
        $this->assertStringContainsString('## Objectives', $md);
        $this->assertStringContainsString('- Greet the caller', $md);
        $this->assertStringContainsString('- Resolve the issue', $md);
    }

    public function testHasAndGetSection(): void
    {
        $builder = (new PomBuilder())->addSection('Constraints', 'Be concise.');
        $this->assertTrue($builder->hasSection('Constraints'));
        $this->assertFalse($builder->hasSection('Missing'));

        $section = $builder->getSection('Constraints');
        $this->assertInstanceOf(Section::class, $section);
        $this->assertSame('Be concise.', $section->body);
        $this->assertNull($builder->getSection('Missing'));
    }

    public function testAddToSectionAutoVivifiesAndAppends(): void
    {
        $builder = (new PomBuilder())
            ->addSection('Notes', 'First.')
            ->addToSection('Notes', 'Second.', 'a bullet')
            ->addToSection('Fresh', null, null, ['x', 'y']); // auto-vivify

        $notes = $builder->getSection('Notes');
        $this->assertSame("First.\n\nSecond.", $notes->body);
        $this->assertSame(['a bullet'], $notes->bullets);

        $this->assertTrue($builder->hasSection('Fresh'));
        $this->assertSame(['x', 'y'], $builder->getSection('Fresh')->bullets);
    }

    public function testAddSubsectionAutoVivifiesParent(): void
    {
        $builder = (new PomBuilder())
            ->addSubsection('Parent', 'Child', 'child body', ['cb']);

        $this->assertTrue($builder->hasSection('Parent'));
        $parent = $builder->getSection('Parent');
        $this->assertCount(1, $parent->subsections);
        $this->assertSame('Child', $parent->subsections[0]->title);
        $this->assertSame('child body', $parent->subsections[0]->body);
    }

    public function testRenderXml(): void
    {
        $builder = (new PomBuilder())->addSection('Role', 'agent');
        $xml = $builder->renderXml();
        $this->assertStringContainsString('<prompt>', $xml);
        $this->assertStringContainsString('<title>Role</title>', $xml);
        $this->assertStringContainsString('<body>agent</body>', $xml);
    }

    public function testToArrayAndToJson(): void
    {
        $builder = (new PomBuilder())->addSection('S', 'body text', ['b1']);
        $array = $builder->toArray();
        $this->assertSame('S', $array[0]['title']);
        $this->assertSame('body text', $array[0]['body']);
        $this->assertSame(['b1'], $array[0]['bullets']);

        $json = $builder->toJson();
        $decoded = json_decode($json, true);
        $this->assertSame('S', $decoded[0]['title']);
    }

    public function testFromSectionsRebuildsTitleLookup(): void
    {
        $sections = [
            ['title' => 'A', 'body' => 'body a'],
            ['title' => 'B', 'bullets' => ['b1', 'b2']],
        ];
        $builder = PomBuilder::fromSections($sections);

        $this->assertTrue($builder->hasSection('A'));
        $this->assertTrue($builder->hasSection('B'));
        $md = $builder->renderMarkdown();
        $this->assertStringContainsString('## A', $md);
        $this->assertStringContainsString('- b1', $md);
    }
}
