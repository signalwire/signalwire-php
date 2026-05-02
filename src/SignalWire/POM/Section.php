<?php

declare(strict_types=1);

namespace SignalWire\POM;

/**
 * Represents a section in the Prompt Object Model.
 *
 * Each section contains a title, optional body text, optional bullet
 * points, and any number of nested subsections. Mirrors Python's
 * ``signalwire.pom.pom.Section`` byte-for-byte for ``render_markdown``,
 * ``render_xml``, ``to_dict`` (and indirectly ``to_json`` / ``to_yaml``).
 *
 * Python parity: signalwire/signalwire/pom/pom.py::Section
 *
 * Constructor params (``params`` array — PHP idiom for kwargs):
 *   - title  (?string)         section title; null is permitted only on the
 *                              first top-level section of a PromptObjectModel
 *   - body   (string)          paragraph text; default ''
 *   - bullets (?list<string>)  bullet items; default []
 *   - numbered (?bool)         null = inherit; true/false = explicit
 *   - numberedBullets (bool)   render bullets as "1. foo" instead of "- foo"
 */
class Section
{
    public ?string $title;
    public string $body;
    /** @var list<string> */
    public array $bullets;
    /** @var list<Section> */
    public array $subsections;
    public ?bool $numbered;
    public bool $numberedBullets;

    /**
     * @param array{
     *   title?: string|null,
     *   body?: string,
     *   bullets?: list<string>|null,
     *   numbered?: bool|null,
     *   numberedBullets?: bool
     * } $params
     */
    public function __construct(?string $title = null, array $params = [])
    {
        $this->title = $title;

        $body = $params['body'] ?? '';
        if (!is_string($body)) {
            throw new \InvalidArgumentException(
                'body must be a string. If you meant to pass a list of bullet points, '
                . 'use the bullets parameter instead.'
            );
        }
        $this->body = $body;

        $bullets = $params['bullets'] ?? null;
        if ($bullets !== null && !is_array($bullets)) {
            throw new \InvalidArgumentException('bullets must be a list or null');
        }
        $this->bullets = $bullets === null ? [] : array_values($bullets);

        $this->subsections = [];
        $this->numbered = $params['numbered'] ?? null;
        $this->numberedBullets = (bool) ($params['numberedBullets'] ?? false);
    }

    /**
     * Add or replace the body text for this section.
     */
    public function addBody(string $body): void
    {
        $this->body = $body;
    }

    /**
     * Append bullet points to this section.
     *
     * @param list<string> $bullets
     */
    public function addBullets(array $bullets): void
    {
        foreach ($bullets as $b) {
            if (!is_string($b)) {
                throw new \InvalidArgumentException('bullets must contain only strings');
            }
            $this->bullets[] = $b;
        }
    }

    /**
     * Add a subsection to this section.
     *
     * @param array{
     *   body?: string,
     *   bullets?: list<string>|null,
     *   numbered?: bool|null,
     *   numberedBullets?: bool
     * } $params
     *
     * @throws \InvalidArgumentException If $title is null
     */
    public function addSubsection(string $title, array $params = []): Section
    {
        // Python raises ValueError when title is None — PHP enforces non-null
        // via the type system; preserve the error path for empty titles.
        // Python's ``if title is None`` never fires for empty string ''.
        $sub = new Section($title, $params);
        $this->subsections[] = $sub;
        return $sub;
    }

    /**
     * Convert the section to a dictionary representation.
     *
     * Key ordering (must match Python): title, body, bullets, subsections,
     * numbered, numberedBullets.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];
        if ($this->title !== null) {
            $data['title'] = $this->title;
        }
        if ($this->body !== '') {
            $data['body'] = $this->body;
        }
        if (!empty($this->bullets)) {
            $data['bullets'] = $this->bullets;
        }
        if (!empty($this->subsections)) {
            $data['subsections'] = array_map(
                static fn (Section $s): array => $s->toArray(),
                $this->subsections
            );
        }
        if ($this->numbered) {
            $data['numbered'] = $this->numbered;
        }
        if ($this->numberedBullets) {
            $data['numberedBullets'] = $this->numberedBullets;
        }
        return $data;
    }

    /**
     * Render this section and all its subsections as Markdown.
     *
     * @param int                $level         heading level (default 2 -> ##)
     * @param list<int>|null     $sectionNumber numbering breadcrumb
     */
    public function renderMarkdown(int $level = 2, ?array $sectionNumber = null): string
    {
        $md = [];
        $sectionNumber = $sectionNumber ?? [];

        if ($this->title !== null) {
            $prefix = '';
            if (!empty($sectionNumber)) {
                $prefix = implode('.', $sectionNumber) . '. ';
            }
            $md[] = str_repeat('#', $level) . ' ' . $prefix . $this->title . "\n";
        }

        if ($this->body !== '') {
            $md[] = $this->body . "\n";
        }

        foreach ($this->bullets as $i => $bullet) {
            if ($this->numberedBullets) {
                $md[] = ($i + 1) . '. ' . $bullet;
            } else {
                $md[] = '- ' . $bullet;
            }
        }
        if (!empty($this->bullets)) {
            $md[] = '';
        }

        $anySubNumbered = false;
        foreach ($this->subsections as $sub) {
            if ($sub->numbered === true) {
                $anySubNumbered = true;
                break;
            }
        }

        foreach ($this->subsections as $i => $sub) {
            $idx = $i + 1;
            if ($this->title !== null || !empty($sectionNumber)) {
                if ($anySubNumbered && $sub->numbered !== false) {
                    $newNumber = array_merge($sectionNumber, [$idx]);
                } else {
                    $newNumber = $sectionNumber;
                }
                $nextLevel = $level + 1;
            } else {
                $newNumber = $sectionNumber;
                $nextLevel = $level;
            }
            $md[] = $sub->renderMarkdown($nextLevel, $newNumber);
        }

        return implode("\n", $md);
    }

    /**
     * Render this section and all its subsections as XML.
     *
     * @param int            $indent        indentation level (each = 2 spaces)
     * @param list<int>|null $sectionNumber numbering breadcrumb
     */
    public function renderXml(int $indent = 0, ?array $sectionNumber = null): string
    {
        $indentStr = str_repeat('  ', $indent);
        $xml = [];
        $sectionNumber = $sectionNumber ?? [];

        $xml[] = $indentStr . '<section>';

        if ($this->title !== null) {
            $prefix = '';
            if (!empty($sectionNumber)) {
                $prefix = implode('.', $sectionNumber) . '. ';
            }
            $xml[] = $indentStr . '  <title>' . $prefix . $this->title . '</title>';
        }

        if ($this->body !== '') {
            $xml[] = $indentStr . '  <body>' . $this->body . '</body>';
        }

        if (!empty($this->bullets)) {
            $xml[] = $indentStr . '  <bullets>';
            foreach ($this->bullets as $i => $bullet) {
                if ($this->numberedBullets) {
                    $xml[] = $indentStr . '    <bullet id="' . ($i + 1) . '">' . $bullet . '</bullet>';
                } else {
                    $xml[] = $indentStr . '    <bullet>' . $bullet . '</bullet>';
                }
            }
            $xml[] = $indentStr . '  </bullets>';
        }

        if (!empty($this->subsections)) {
            $xml[] = $indentStr . '  <subsections>';
            $anySubNumbered = false;
            foreach ($this->subsections as $sub) {
                if ($sub->numbered === true) {
                    $anySubNumbered = true;
                    break;
                }
            }

            foreach ($this->subsections as $i => $sub) {
                $idx = $i + 1;
                if ($this->title !== null || !empty($sectionNumber)) {
                    if ($anySubNumbered && $sub->numbered !== false) {
                        $newNumber = array_merge($sectionNumber, [$idx]);
                    } else {
                        $newNumber = $sectionNumber;
                    }
                } else {
                    $newNumber = $sectionNumber;
                }
                $xml[] = $sub->renderXml($indent + 2, $newNumber);
            }
            $xml[] = $indentStr . '  </subsections>';
        }

        $xml[] = $indentStr . '</section>';

        return implode("\n", $xml);
    }
}
