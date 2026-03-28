<?php

declare(strict_types=1);

namespace SignalWire\SWML;

class Schema
{
    private static ?self $instance = null;

    /** @var array<string, array{name: string, schema_name: string, definition: array}> */
    private array $verbs = [];

    private array $schemaData = [];

    private function __construct()
    {
        $this->loadSchema();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset the singleton (for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Check whether a verb name is valid.
     */
    public function isValidVerb(string $name): bool
    {
        return isset($this->verbs[$name]);
    }

    /**
     * Get sorted list of all verb names.
     *
     * @return list<string>
     */
    public function getVerbNames(): array
    {
        $names = array_keys($this->verbs);
        sort($names);
        return $names;
    }

    /**
     * Get verb metadata, or null if not found.
     *
     * @return array{name: string, schema_name: string, definition: array}|null
     */
    public function getVerb(string $name): ?array
    {
        return $this->verbs[$name] ?? null;
    }

    /**
     * Number of verbs defined in the schema.
     */
    public function verbCount(): int
    {
        return count($this->verbs);
    }

    private function loadSchema(): void
    {
        $schemaPath = __DIR__ . '/schema.json';
        if (!file_exists($schemaPath)) {
            throw new \RuntimeException("SWML schema.json not found at {$schemaPath}");
        }

        $raw = file_get_contents($schemaPath);
        $this->schemaData = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $defs = $this->schemaData['$defs'] ?? [];
        $swmlMethod = $defs['SWMLMethod'] ?? [];
        $anyOf = $swmlMethod['anyOf'] ?? [];

        foreach ($anyOf as $entry) {
            $ref = $entry['$ref'] ?? null;
            if ($ref === null) {
                continue;
            }

            // e.g. "#/$defs/Answer" -> "Answer"
            $parts = explode('/', $ref);
            $defName = end($parts);

            $defn = $defs[$defName] ?? null;
            if ($defn === null) {
                continue;
            }

            $props = $defn['properties'] ?? [];
            if (empty($props)) {
                continue;
            }

            // The first property key is the actual verb name
            $actualVerb = array_key_first($props);
            $this->verbs[$actualVerb] = [
                'name' => $actualVerb,
                'schema_name' => $defName,
                'definition' => $defn,
            ];
        }
    }
}
