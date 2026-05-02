<?php

declare(strict_types=1);

namespace SignalWire\Tests\POM;

use PHPUnit\Framework\TestCase;
use SignalWire\POM\PromptObjectModel;
use SignalWire\POM\Section;

/**
 * Cross-port parity tests for SignalWire\POM\PromptObjectModel.
 *
 * Translation of signalwire-python tests/unit/pom/test_pom_render_parity.py
 * (commit 191210c). Every assertion is byte-for-byte identical to the Python
 * reference output — substring-only assertions are NOT acceptable in a port
 * parity suite because they don't catch whitespace / ordering drift.
 *
 * The matching basic tests from tests/unit/pom/test_pom_object_model.py are
 * also translated and live under \SignalWire\Tests\POM\SectionTest.
 */
class PromptObjectModelTest extends TestCase
{
    // -----------------------------------------------------------------------
    // TestEmptyPom
    // -----------------------------------------------------------------------

    public function testEmptyRenderMarkdownIsEmptyString(): void
    {
        $pom = new PromptObjectModel();
        $this->assertSame('', $pom->renderMarkdown());
    }

    public function testEmptyRenderXmlIsJustPromptTags(): void
    {
        $pom = new PromptObjectModel();
        $expected = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<prompt>\n</prompt>";
        $this->assertSame($expected, $pom->renderXml());
    }

    public function testEmptyToJsonIsEmptyArray(): void
    {
        $pom = new PromptObjectModel();
        $this->assertSame('[]', $pom->toJson());
    }

    public function testEmptyToYaml(): void
    {
        $pom = new PromptObjectModel();
        $this->assertSame("[]\n", $pom->toYaml());
    }

    // -----------------------------------------------------------------------
    // TestSimpleSection
    // -----------------------------------------------------------------------

    public function testRenderMarkdownExact(): void
    {
        $pom = new PromptObjectModel();
        $pom->addSection('Greeting', ['body' => 'Hello world']);
        $expected = "## Greeting\n\nHello world\n";
        $this->assertSame($expected, $pom->renderMarkdown());
    }

    public function testRenderXmlExact(): void
    {
        $pom = new PromptObjectModel();
        $pom->addSection('Greeting', ['body' => 'Hello world']);
        $expected = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<prompt>\n"
            . "  <section>\n"
            . "    <title>Greeting</title>\n"
            . "    <body>Hello world</body>\n"
            . "  </section>\n"
            . "</prompt>";
        $this->assertSame($expected, $pom->renderXml());
    }

    // -----------------------------------------------------------------------
    // TestBullets
    // -----------------------------------------------------------------------

    public function testRenderMarkdownWithBullets(): void
    {
        $pom = new PromptObjectModel();
        $pom->addSection('Goals', [
            'body' => 'Be helpful',
            'bullets' => ['Be concise', 'Be clear'],
        ]);
        $expected = "## Goals\n\nBe helpful\n\n- Be concise\n- Be clear\n";
        $this->assertSame($expected, $pom->renderMarkdown());
    }

    public function testRenderXmlWithBullets(): void
    {
        $pom = new PromptObjectModel();
        $pom->addSection('Goals', [
            'body' => 'Be helpful',
            'bullets' => ['Be concise', 'Be clear'],
        ]);
        $expected = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<prompt>\n"
            . "  <section>\n"
            . "    <title>Goals</title>\n"
            . "    <body>Be helpful</body>\n"
            . "    <bullets>\n"
            . "      <bullet>Be concise</bullet>\n"
            . "      <bullet>Be clear</bullet>\n"
            . "    </bullets>\n"
            . "  </section>\n"
            . "</prompt>";
        $this->assertSame($expected, $pom->renderXml());
    }

    // -----------------------------------------------------------------------
    // TestSubsections
    // -----------------------------------------------------------------------

    public function testRenderMarkdownWithSubsection(): void
    {
        $pom = new PromptObjectModel();
        $s = $pom->addSection('Top', ['body' => 'Top body']);
        $s->addSubsection('Sub1', ['body' => 'Sub1 body', 'bullets' => ['a', 'b']]);
        $expected = "## Top\n\nTop body\n\n### Sub1\n\nSub1 body\n\n- a\n- b\n";
        $this->assertSame($expected, $pom->renderMarkdown());
    }

    public function testRenderXmlWithSubsection(): void
    {
        $pom = new PromptObjectModel();
        $s = $pom->addSection('Top', ['body' => 'Top body']);
        $s->addSubsection('Sub1', ['body' => 'Sub1 body', 'bullets' => ['a', 'b']]);
        $expected = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<prompt>\n"
            . "  <section>\n"
            . "    <title>Top</title>\n"
            . "    <body>Top body</body>\n"
            . "    <subsections>\n"
            . "      <section>\n"
            . "        <title>Sub1</title>\n"
            . "        <body>Sub1 body</body>\n"
            . "        <bullets>\n"
            . "          <bullet>a</bullet>\n"
            . "          <bullet>b</bullet>\n"
            . "        </bullets>\n"
            . "      </section>\n"
            . "    </subsections>\n"
            . "  </section>\n"
            . "</prompt>";
        $this->assertSame($expected, $pom->renderXml());
    }

    // -----------------------------------------------------------------------
    // TestNumberedSections
    // -----------------------------------------------------------------------

    public function testRenderMarkdownNumberedPropagatesToSiblings(): void
    {
        $pom = new PromptObjectModel();
        $pom->addSection('S1', ['body' => 'b1', 'numbered' => true]);
        $pom->addSection('S2', ['body' => 'b2']);
        $expected = "## 1. S1\n\nb1\n\n## 2. S2\n\nb2\n";
        $this->assertSame($expected, $pom->renderMarkdown());
    }

    public function testRenderXmlNumberedPropagates(): void
    {
        $pom = new PromptObjectModel();
        $pom->addSection('S1', ['body' => 'b1', 'numbered' => true]);
        $pom->addSection('S2', ['body' => 'b2']);
        $expected = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<prompt>\n"
            . "  <section>\n"
            . "    <title>1. S1</title>\n"
            . "    <body>b1</body>\n"
            . "  </section>\n"
            . "  <section>\n"
            . "    <title>2. S2</title>\n"
            . "    <body>b2</body>\n"
            . "  </section>\n"
            . "</prompt>";
        $this->assertSame($expected, $pom->renderXml());
    }

    // -----------------------------------------------------------------------
    // TestNumberedBullets
    // -----------------------------------------------------------------------

    public function testRenderMarkdownNumberedBullets(): void
    {
        $pom = new PromptObjectModel();
        $pom->addSection('X', ['bullets' => ['one', 'two'], 'numberedBullets' => true]);
        $expected = "## X\n\n1. one\n2. two\n";
        $this->assertSame($expected, $pom->renderMarkdown());
    }

    public function testRenderXmlNumberedBulletsUseIdAttr(): void
    {
        $pom = new PromptObjectModel();
        $pom->addSection('X', ['bullets' => ['one', 'two'], 'numberedBullets' => true]);
        $expected = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<prompt>\n"
            . "  <section>\n"
            . "    <title>X</title>\n"
            . "    <bullets>\n"
            . "      <bullet id=\"1\">one</bullet>\n"
            . "      <bullet id=\"2\">two</bullet>\n"
            . "    </bullets>\n"
            . "  </section>\n"
            . "</prompt>";
        $this->assertSame($expected, $pom->renderXml());
    }

    // -----------------------------------------------------------------------
    // TestSerialization
    // -----------------------------------------------------------------------

    public function testToJsonExactShape(): void
    {
        $pom = new PromptObjectModel();
        $s = $pom->addSection('A', ['body' => 'ab']);
        $s->addSubsection('A1', ['body' => 'a1b', 'bullets' => ['x']]);
        $expected = "[\n"
            . "  {\n"
            . "    \"title\": \"A\",\n"
            . "    \"body\": \"ab\",\n"
            . "    \"subsections\": [\n"
            . "      {\n"
            . "        \"title\": \"A1\",\n"
            . "        \"body\": \"a1b\",\n"
            . "        \"bullets\": [\n"
            . "          \"x\"\n"
            . "        ]\n"
            . "      }\n"
            . "    ]\n"
            . "  }\n"
            . "]";
        $this->assertSame($expected, $pom->toJson());
    }

    public function testToYamlExactShape(): void
    {
        $pom = new PromptObjectModel();
        $s = $pom->addSection('A', ['body' => 'ab']);
        $s->addSubsection('A1', ['body' => 'a1b', 'bullets' => ['x']]);
        $expected = "- title: A\n"
            . "  body: ab\n"
            . "  subsections:\n"
            . "  - title: A1\n"
            . "    body: a1b\n"
            . "    bullets:\n"
            . "    - x\n";
        $this->assertSame($expected, $pom->toYaml());
    }

    public function testFromJsonRoundTripPreservesStructure(): void
    {
        $pom = new PromptObjectModel();
        $s = $pom->addSection('A', ['body' => 'ab']);
        $s->addSubsection('A1', ['body' => 'a1b', 'bullets' => ['x', 'y']]);
        $jsonStr = $pom->toJson();
        $restored = PromptObjectModel::fromJson($jsonStr);
        $this->assertSame($jsonStr, $restored->toJson());
    }

    public function testFromYamlRoundTripPreservesStructure(): void
    {
        $pom = new PromptObjectModel();
        $s = $pom->addSection('A', ['body' => 'ab']);
        $s->addSubsection('A1', ['body' => 'a1b', 'bullets' => ['x', 'y']]);
        $yamlStr = $pom->toYaml();
        $restored = PromptObjectModel::fromYaml($yamlStr);
        $this->assertSame($yamlStr, $restored->toYaml());
    }

    // -----------------------------------------------------------------------
    // TestFindSection
    // -----------------------------------------------------------------------

    public function testFindSectionTopLevel(): void
    {
        $pom = new PromptObjectModel();
        $pom->addSection('One', ['body' => 'b1']);
        $pom->addSection('Two', ['body' => 'b2']);
        $s = $pom->findSection('Two');
        $this->assertNotNull($s);
        $this->assertSame('b2', $s->body);
    }

    public function testFindSectionRecursesIntoSubsections(): void
    {
        $pom = new PromptObjectModel();
        $s = $pom->addSection('Outer', ['body' => 'ob']);
        $s->addSubsection('Inner', ['body' => 'ib']);
        $found = $pom->findSection('Inner');
        $this->assertNotNull($found);
        $this->assertSame('ib', $found->body);
    }

    public function testFindSectionReturnsNullForMissing(): void
    {
        $pom = new PromptObjectModel();
        $pom->addSection('Only', ['body' => 'b']);
        $this->assertNull($pom->findSection('Missing'));
    }

    // -----------------------------------------------------------------------
    // TestAddPomAsSubsection
    // -----------------------------------------------------------------------

    public function testAddPomToExistingSectionByTitle(): void
    {
        $host = new PromptObjectModel();
        $host->addSection('Host', ['body' => 'hb']);

        $guest = new PromptObjectModel();
        $guest->addSection('Guest', ['body' => 'gb']);

        $host->addPomAsSubsection('Host', $guest);
        $hostSection = $host->findSection('Host');
        $this->assertNotNull($hostSection);
        $this->assertCount(1, $hostSection->subsections);
        $this->assertSame('Guest', $hostSection->subsections[0]->title);
        $this->assertSame('gb', $hostSection->subsections[0]->body);
    }

    public function testAddPomToSectionObjectDirectly(): void
    {
        $host = new PromptObjectModel();
        $target = $host->addSection('Host', ['body' => 'hb']);

        $guest = new PromptObjectModel();
        $guest->addSection('GuestA', ['body' => 'ab']);
        $guest->addSection('GuestB', ['body' => 'bb']);

        $host->addPomAsSubsection($target, $guest);
        $titles = array_map(static fn (Section $s): ?string => $s->title, $target->subsections);
        $this->assertSame(['GuestA', 'GuestB'], $titles);
    }

    // -----------------------------------------------------------------------
    // Edge cases / error paths (Python ValueError parity)
    // -----------------------------------------------------------------------

    public function testAddSectionWithNullTitleAfterFirstThrows(): void
    {
        $pom = new PromptObjectModel();
        $pom->addSection('First', ['body' => 'b']);
        $this->expectException(\InvalidArgumentException::class);
        $pom->addSection(null, ['body' => 'b2']);
    }

    public function testAddPomAsSubsectionMissingTitleThrows(): void
    {
        $host = new PromptObjectModel();
        $guest = new PromptObjectModel();
        $guest->addSection('G', ['body' => 'b']);
        $this->expectException(\InvalidArgumentException::class);
        $host->addPomAsSubsection('NoSuchTitle', $guest);
    }

    public function testFromJsonInvalidStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PromptObjectModel::fromJson('{not valid json');
    }

    public function testFromJsonAcceptsArrayInput(): void
    {
        $pom = PromptObjectModel::fromJson([
            ['title' => 'X', 'body' => 'xb'],
        ]);
        $this->assertNotNull($pom->findSection('X'));
    }

    public function testFromYamlAcceptsArrayInput(): void
    {
        $pom = PromptObjectModel::fromYaml([
            ['title' => 'B', 'body' => 'y'],
        ]);
        $this->assertNotNull($pom->findSection('B'));
    }

    public function testFromArraySubsequentSectionsWithoutTitleAreAutoTitled(): void
    {
        // Python parity: only the first top-level section may omit ``title``;
        // subsequent ones get auto-titled "Untitled Section".
        $pom = PromptObjectModel::fromJson([
            ['body' => 'first body'],
            ['body' => 'second body'],
        ]);
        $this->assertCount(2, $pom->sections);
        $this->assertNull($pom->sections[0]->title);
        $this->assertSame('Untitled Section', $pom->sections[1]->title);
    }

    public function testFromArraySectionWithoutContentThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PromptObjectModel::fromJson([
            ['title' => 'EmptyOne'],
        ]);
    }

    public function testFromArraySubsectionWithoutTitleThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PromptObjectModel::fromJson([
            [
                'title' => 'Parent',
                'body' => 'pb',
                'subsections' => [
                    ['body' => 'no title here'],
                ],
            ],
        ]);
    }

    public function testToArrayMatchesSectionToArray(): void
    {
        $pom = new PromptObjectModel();
        $pom->addSection('A', ['body' => 'ab']);
        $pom->addSection('B', ['body' => 'bb']);
        $arr = $pom->toArray();
        $this->assertCount(2, $arr);
        $this->assertSame('A', $arr[0]['title']);
        $this->assertSame('ab', $arr[0]['body']);
        $this->assertSame('B', $arr[1]['title']);
    }
}
