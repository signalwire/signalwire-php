<?php

declare(strict_types=1);

namespace SignalWire\POM;

/**
 * Builder for structured prompts using the Prompt Object Model.
 *
 * A flexible wrapper around {@see PromptObjectModel} that supports dynamic
 * creation of sections, appending content to existing sections, nesting
 * subsections, and rendering to Markdown or XML. There are no predefined
 * section types — any structure is allowed.
 *
 * Mirrors Python's ``signalwire.core.pom_builder.PomBuilder`` (wire/semantic
 * contract) and TS's ``PomBuilder`` (the canonical shape). The fluent mutators return
 * ``$this`` for method chaining, matching both references.
 *
 * Mirrors signalwire/signalwire/core/pom_builder.py
 */
class PomBuilder
{
    public PromptObjectModel $pom;

    /** @var array<string, Section> Title -> section lookup (auto-vivification). */
    private array $sections = [];

    public function __construct()
    {
        $this->pom = new PromptObjectModel();
    }

    /**
     * Add a new top-level section to the POM.
     *
     * @param list<string>|null $bullets     Optional bullet points.
     * @param list<mixed>|null  $subsections Optional subsection dicts (validated at runtime).
     *
     * @return $this
     */
    public function addSection(
        string $title,
        string $body = '',
        ?array $bullets = null,
        bool $numbered = false,
        bool $numberedBullets = false,
        ?array $subsections = null
    ): self {
        $section = $this->pom->addSection($title, [
            'body' => $body,
            'bullets' => $bullets ?? [],
            'numbered' => $numbered,
            'numberedBullets' => $numberedBullets,
        ]);
        $this->sections[$title] = $section;

        if ($subsections !== null) {
            foreach ($subsections as $sub) {
                if (is_array($sub) && array_key_exists('title', $sub) && is_string($sub['title'])) {
                    $subBody = (isset($sub['body']) && is_string($sub['body'])) ? $sub['body'] : '';
                    $subBullets = [];
                    if (isset($sub['bullets']) && is_array($sub['bullets'])) {
                        foreach ($sub['bullets'] as $b) {
                            if (is_string($b)) {
                                $subBullets[] = $b;
                            }
                        }
                    }
                    $section->addSubsection($sub['title'], [
                        'body' => $subBody,
                        'bullets' => $subBullets,
                    ]);
                }
            }
        }

        return $this;
    }

    /**
     * Append content to an existing section, creating it if absent.
     *
     * @param list<string>|null $bullets List of bullets to append.
     *
     * @return $this
     */
    public function addToSection(
        string $title,
        ?string $body = null,
        ?string $bullet = null,
        ?array $bullets = null
    ): self {
        if (!array_key_exists($title, $this->sections)) {
            $this->addSection($title);
        }

        $section = $this->sections[$title];

        if ($body !== null && $body !== '') {
            if ($section->body !== '') {
                $section->body = "{$section->body}\n\n{$body}";
            } else {
                $section->body = $body;
            }
        }

        if ($bullet !== null && $bullet !== '') {
            $section->bullets[] = $bullet;
        }

        if ($bullets !== null) {
            foreach ($bullets as $b) {
                $section->bullets[] = $b;
            }
        }

        return $this;
    }

    /**
     * Add a subsection to a section, creating the parent if absent.
     *
     * @param list<string>|null $bullets Optional bullet points.
     *
     * @return $this
     */
    public function addSubsection(
        string $parentTitle,
        string $title,
        string $body = '',
        ?array $bullets = null
    ): self {
        if (!array_key_exists($parentTitle, $this->sections)) {
            $this->addSection($parentTitle);
        }

        $parent = $this->sections[$parentTitle];
        $parent->addSubsection($title, [
            'body' => $body,
            'bullets' => $bullets ?? [],
        ]);

        return $this;
    }

    /**
     * Whether a top-level section with the given title exists.
     */
    public function hasSection(string $title): bool
    {
        return array_key_exists($title, $this->sections);
    }

    /**
     * Get a section by title, or null if not found.
     */
    public function getSection(string $title): ?Section
    {
        return $this->sections[$title] ?? null;
    }

    /**
     * Render the POM as Markdown.
     */
    public function renderMarkdown(): string
    {
        return $this->pom->renderMarkdown();
    }

    /**
     * Render the POM as XML.
     */
    public function renderXml(): string
    {
        return $this->pom->renderXml();
    }

    /**
     * Convert the POM to a list of section arrays.
     *
     * Projects onto Python's ``to_dict`` via the adapter's ``to_array -> to_dict``
     * method alias.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->pom->toArray();
    }

    /**
     * Convert the POM to a JSON string.
     */
    public function toJson(): string
    {
        return $this->pom->toJson();
    }

    /**
     * Create a PomBuilder from a list of section arrays.
     *
     * Only titled sections are addressable by title afterwards (Mirrors * ``from_sections`` indexes only sections whose ``title`` is truthy).
     *
     * @param list<array<string, mixed>> $sections
     */
    public static function fromSections(array $sections): self
    {
        $builder = new self();
        $builder->pom = PromptObjectModel::fromJson($sections);
        foreach ($builder->pom->sections as $section) {
            if ($section->title !== null && $section->title !== '') {
                $builder->sections[$section->title] = $section;
            }
        }
        return $builder;
    }
}
