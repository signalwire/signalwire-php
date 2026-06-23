<?php

declare(strict_types=1);

namespace SignalWire\SWML;

class Schema
{
    private static ?self $instance = null;

    /** @var array<string, array{name: string, schema_name: string, definition: array<string,mixed>}> */
    private array $verbs = [];

    /** @var array<string,mixed> */
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
     * @return array{name: string, schema_name: string, definition: array<string,mixed>}|null
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
        if ($raw === false) {
            throw new \RuntimeException("Failed to read SWML schema.json at {$schemaPath}");
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException("SWML schema.json did not decode to an object at {$schemaPath}");
        }
        // The top-level schema is a JSON object, so all keys are strings.
        $schemaData = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k)) {
                $schemaData[$k] = $v;
            }
        }
        $this->schemaData = $schemaData;

        $defs = $this->schemaData['$defs'] ?? null;
        if (!is_array($defs)) {
            return;
        }
        $swmlMethod = $defs['SWMLMethod'] ?? null;
        if (!is_array($swmlMethod)) {
            return;
        }
        $anyOf = $swmlMethod['anyOf'] ?? null;
        if (!is_array($anyOf)) {
            return;
        }

        foreach ($anyOf as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $ref = $entry['$ref'] ?? null;
            if (!is_string($ref)) {
                continue;
            }

            // e.g. "#/$defs/Answer" -> "Answer"
            $parts = explode('/', $ref);
            $defName = end($parts);

            $defn = $defs[$defName] ?? null;
            if (!is_array($defn)) {
                continue;
            }

            $props = $defn['properties'] ?? null;
            if (!is_array($props) || empty($props)) {
                continue;
            }

            // The first property key is the actual verb name
            $actualVerb = array_key_first($props);
            if (!is_string($actualVerb)) {
                continue;
            }

            // JSON objects decode to string keys; keep only those so the
            // definition matches the declared array<string, mixed> shape.
            $definition = [];
            foreach ($defn as $k => $v) {
                if (is_string($k)) {
                    $definition[$k] = $v;
                }
            }

            $this->verbs[$actualVerb] = [
                'name' => $actualVerb,
                'schema_name' => $defName,
                'definition' => $definition,
            ];
        }
    }
}
