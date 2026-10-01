<?php

declare(strict_types=1);

namespace SignalWire\SWML;

/**
 * Lazily-loaded singleton over the bundled SWML `schema.json`, from which the
 * verb table that drives auto-vivified verb methods is derived.
 *
 * Verbs are read from `$defs.SWMLMethod.anyOf`, following each entry's `$ref`.
 * Loading is strict about the file itself — a missing, unreadable, or
 * non-object `schema.json` raises — but tolerant about its shape: a schema
 * lacking the expected `$defs`/`SWMLMethod`/`anyOf` structure yields an EMPTY
 * verb table rather than an error, so every verb then reads as invalid.
 *
 * The constructor is private; reach the shared instance through
 * {@see Schema::instance()} and drop it in tests with {@see Schema::reset()}.
 */
class Schema
{
    private static ?self $instance = null;

    /** @var array<string, array{name: string, schema_name: string, definition: array<string,mixed>}> */
    private array $verbs = [];

    /**
     * Verbs the schema marks ``"deprecated": true`` (dial / eval / if). They are
     * not SDK surface — no auto-vivified method is offered for them (owner
     * ruling 2026-09-24; the reference leaves them out of
     * ``get_all_verb_names()``) — but {@see getVerb()} still knows them.
     *
     * @var array<string, true>
     */
    private array $deprecated = [];

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
     * Check whether a verb name is a verb the SDK offers (a deprecated verb is
     * not — see {@see $deprecated}).
     */
    public function isValidVerb(string $name): bool
    {
        return isset($this->verbs[$name]) && !isset($this->deprecated[$name]);
    }

    /**
     * Get sorted list of the verb names the SDK offers (deprecated verbs left out).
     *
     * @return list<string>
     */
    public function getVerbNames(): array
    {
        $names = array_values(array_filter(
            array_keys($this->verbs),
            fn (string $n): bool => !isset($this->deprecated[$n])
        ));
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
     * Number of verbs the SDK offers (deprecated verbs left out).
     */
    public function verbCount(): int
    {
        return count($this->verbs) - count($this->deprecated);
    }

    /**
     * Whether a SWML verb wrapper is marked deprecated in the schema: JSON
     * Schema's ``deprecated`` annotation on the wrapper or on its verb property
     * (mirrors the reference's private ``_verb_is_deprecated``).
     *
     * @internal shared with SchemaUtils; not public SDK surface.
     * @param array<string, mixed> $defn
     */
    public static function verbIsDeprecated(array $defn, string $verb): bool
    {
        if (($defn['deprecated'] ?? null) === true) {
            return true;
        }
        $props = $defn['properties'] ?? null;
        $prop = is_array($props) ? ($props[$verb] ?? null) : null;
        return is_array($prop) && ($prop['deprecated'] ?? null) === true;
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
            if (self::verbIsDeprecated($definition, $actualVerb)) {
                $this->deprecated[$actualVerb] = true;
            }
        }
    }
}
