<?php

declare(strict_types=1);

namespace SignalWire\SWML;

class Document
{
    private string $version = '1.0.0';

    /** @var array<string, list<array<string, mixed>>> */
    private array $sections = [];

    public function __construct()
    {
        $this->sections['main'] = [];
    }

    /** The version. */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Add a new named section. Returns true if created, false if it already existed.
     */
    public function addSection(string $name): bool
    {
        if (isset($this->sections[$name])) {
            return false;
        }
        $this->sections[$name] = [];
        return true;
    }

    /** Whether there is a section. */
    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]);
    }

    /**
     * Get a copy of the verbs for a section.
     *
     * @return list<array<string, mixed>>
     */
    public function getVerbs(string $section = 'main'): array
    {
        return $this->sections[$section] ?? [];
    }

    /**
     * Append a verb to the main section.
     */
    public function addVerb(string $verbName, mixed $config): void
    {
        $this->addVerbToSection('main', $verbName, $config);
    }

    /**
     * Append a verb to a named section.
     *
     * A verb with no arguments (an empty array config) is stored as an empty
     * OBJECT (``\stdClass``) so it renders on the wire as ``{"verb":{}}``, NOT
     * ``{"verb":[]}``. The SWML wire contract (and the python reference,
     * swml_service.add_verb, which stores ``config = {}``) is that a verb's
     * config is a JSON OBJECT; PHP's empty ``[]`` would json_encode to a JSON
     * ARRAY, which the server's SWML schema rejects for a verb body. A ``sleep``
     * verb (whose config is a bare integer) and any non-empty config pass
     * through unchanged.
     */
    public function addVerbToSection(string $section, string $verbName, mixed $config): void
    {
        if (!isset($this->sections[$section])) {
            throw new \InvalidArgumentException("Section '{$section}' does not exist");
        }
        if (is_array($config) && $config === []) {
            $config = new \stdClass();
        }
        $this->sections[$section][] = [$verbName => $config];
    }

    /**
     * Append a pre-formatted verb hash to a section.
     *
     * @param array<string, mixed> $verbHash
     */
    public function addRawVerb(string $section, array $verbHash): void
    {
        if (!isset($this->sections[$section])) {
            throw new \InvalidArgumentException("Section '{$section}' does not exist");
        }
        $this->sections[$section][] = $verbHash;
    }

    /**
     * Clear all verbs in a section.
     */
    public function clearSection(string $section): void
    {
        if (isset($this->sections[$section])) {
            $this->sections[$section] = [];
        }
    }

    /**
     * Reset document to initial state.
     */
    public function reset(): void
    {
        $this->sections = ['main' => []];
    }

    /**
     * Return document as associative array.
     *
     * @return array{version: string, sections: array<string, list<array<string, mixed>>>}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'sections' => $this->sections,
        ];
    }

    /**
     * Compact JSON string.
     */
    public function render(): string
    {
        $encoded = json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('json_encode failed');
        }
        return $encoded;
    }

    /**
     * Pretty-printed JSON string.
     */
    public function renderPretty(): string
    {
        $encoded = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('json_encode failed');
        }
        return $encoded;
    }
}
