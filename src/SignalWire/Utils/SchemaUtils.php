<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Utils;

/**
 * SchemaUtils — PHP port of signalwire.utils.schema_utils.SchemaUtils.
 *
 * Loads the SWML JSON Schema, extracts verb metadata, and validates
 * either a single verb config or a complete SWML document.
 *
 * Construction rules mirror Python:
 *   - Pass schemaPath=null to use the bundled schema.json.
 *   - schemaValidation=false disables validation (validate_verb returns
 *     valid=true for every call).
 *   - The env var SWML_SKIP_SCHEMA_VALIDATION=1/true/yes also disables
 *     validation regardless of the constructor argument.
 *
 * The PHP port currently ships only the lightweight validator (verb
 * existence + required-property check). Full JSON Schema validation
 * can be wired in via justinrainbow/json-schema by extending
 * initFullValidator(). The lightweight contract matches Python's
 * _validate_verb_lightweight() exactly.
 */
final class SchemaUtils
{
    /** @var array<string, mixed> Parsed JSON Schema document. */
    private array $schema;

    /** @var string|null Path the schema was loaded from (null = embedded). */
    private ?string $schemaPath;

    /** @var bool Whether validation is enabled (false when the env var disabled it). */
    private bool $validationEnabled;

    /** @var array<string, array{name: string, schema_name: string, definition: array}> */
    private array $verbs = [];

    /** @var mixed|null Optional full JSON Schema validator (reserved). */
    private $fullValidator = null;

    /**
     * Construct a SchemaUtils.
     *
     * @param string|null $schemaPath        Path to a schema.json file; null for the bundled copy.
     * @param bool        $schemaValidation  Whether to enable schema validation (env override applies).
     */
    public function __construct(?string $schemaPath = null, bool $schemaValidation = true)
    {
        $envSkip = $this->envBoolish((string) (getenv('SWML_SKIP_SCHEMA_VALIDATION') ?: ''));
        $this->schemaPath = $schemaPath;
        $this->validationEnabled = $schemaValidation && !$envSkip;
        $this->schema = $this->loadSchema();
        $this->extractVerbs();
        if ($this->validationEnabled && !empty($this->schema)) {
            $this->initFullValidator();
        }
    }

    private function envBoolish(string $v): bool
    {
        $s = strtolower(trim($v));
        return $s === '1' || $s === 'true' || $s === 'yes';
    }

    /**
     * Read and parse the JSON Schema. Mirrors Python's load_schema().
     *
     * @return array<string, mixed>
     */
    public function loadSchema(): array
    {
        $path = $this->schemaPath ?? $this->defaultSchemaPath();
        if ($path === null || !is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function defaultSchemaPath(): ?string
    {
        $candidates = [
            __DIR__ . '/../SWML/schema.json',
            getcwd() . '/schema.json',
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        return null;
    }

    private function extractVerbs(): void
    {
        $defs = $this->schema['$defs'] ?? null;
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
            $prefix = '#/$defs/';
            if (!str_starts_with($ref, $prefix)) {
                continue;
            }
            $schemaName = substr($ref, strlen($prefix));
            $defn = $defs[$schemaName] ?? null;
            if (!is_array($defn)) {
                continue;
            }
            $props = $defn['properties'] ?? null;
            if (!is_array($props) || empty($props)) {
                continue;
            }
            $actualVerb = (string) array_key_first($props);
            $this->verbs[$actualVerb] = [
                'name' => $actualVerb,
                'schema_name' => $schemaName,
                'definition' => $defn,
            ];
        }
    }

    /**
     * Initialize the full JSON Schema validator. The PHP port currently
     * leaves this empty — extend by wiring justinrainbow/json-schema or
     * a similar library here.
     */
    private function initFullValidator(): void
    {
        $this->fullValidator = null;
    }

    /**
     * Whether full JSON Schema validation is wired up.
     * Mirrors Python's full_validation_available property.
     */
    public function isFullValidationAvailable(): bool
    {
        return $this->fullValidator !== null;
    }

    /**
     * Sorted list of all known verb names.
     * Mirrors Python's get_all_verb_names().
     *
     * @return list<string>
     */
    public function getAllVerbNames(): array
    {
        $names = array_keys($this->verbs);
        sort($names);
        return $names;
    }

    /**
     * The properties[verb_name] block for a verb, or [] when unknown.
     * Mirrors Python's get_verb_properties(verb_name).
     *
     * @return array<string, mixed>
     */
    public function getVerbProperties(string $verbName): array
    {
        $verb = $this->verbs[$verbName] ?? null;
        if ($verb === null) {
            return [];
        }
        $outerProps = $verb['definition']['properties'] ?? null;
        if (!is_array($outerProps)) {
            return [];
        }
        $inner = $outerProps[$verbName] ?? null;
        if (!is_array($inner)) {
            return [];
        }
        return $inner;
    }

    /**
     * The required list for a verb, or [] when unknown / no required.
     * Mirrors Python's get_verb_required_properties(verb_name).
     *
     * @return list<string>
     */
    public function getVerbRequiredProperties(string $verbName): array
    {
        $inner = $this->getVerbProperties($verbName);
        $req = $inner['required'] ?? null;
        if (!is_array($req)) {
            return [];
        }
        return array_values(array_filter($req, 'is_string'));
    }

    /**
     * Parameter-definition block used by code-gen tooling.
     * Mirrors Python's get_verb_parameters(verb_name).
     *
     * @return array<string, mixed>
     */
    public function getVerbParameters(string $verbName): array
    {
        $inner = $this->getVerbProperties($verbName);
        $props = $inner['properties'] ?? null;
        if (!is_array($props)) {
            return [];
        }
        return $props;
    }

    /**
     * Validate a verb config against the schema.
     * Mirrors Python's validate_verb(verb_name, verb_config).
     *
     * @param array<string, mixed> $verbConfig
     * @return array{0: bool, 1: list<string>} Tuple of (valid, errors).
     */
    public function validateVerb(string $verbName, array $verbConfig): array
    {
        if (!$this->validationEnabled) {
            return [true, []];
        }
        if (!isset($this->verbs[$verbName])) {
            return [false, ["Unknown verb: $verbName"]];
        }
        if ($this->fullValidator !== null) {
            return $this->validateVerbFull($verbName, $verbConfig);
        }
        return $this->validateVerbLightweight($verbName, $verbConfig);
    }

    /**
     * @param array<string, mixed> $verbConfig
     * @return array{0: bool, 1: list<string>}
     */
    private function validateVerbFull(string $verbName, array $verbConfig): array
    {
        // Reserved for full-validator wiring; falls back to lightweight check.
        return $this->validateVerbLightweight($verbName, $verbConfig);
    }

    /**
     * @param array<string, mixed> $verbConfig
     * @return array{0: bool, 1: list<string>}
     */
    private function validateVerbLightweight(string $verbName, array $verbConfig): array
    {
        $errors = [];
        foreach ($this->getVerbRequiredProperties($verbName) as $prop) {
            if (!array_key_exists($prop, $verbConfig)) {
                $errors[] = "Missing required property '$prop' for verb '$verbName'";
            }
        }
        return [count($errors) === 0, $errors];
    }

    /**
     * Validate a complete SWML document. Mirrors Python's
     * validate_document(document). Returns (false, ['Schema validator
     * not initialized']) when no full validator is wired in.
     *
     * @param array<string, mixed> $document
     * @return array{0: bool, 1: list<string>}
     */
    public function validateDocument(array $document): array
    {
        if ($this->fullValidator === null) {
            return [false, ['Schema validator not initialized']];
        }
        // Reserved for full-validator wiring.
        return [true, []];
    }

    /**
     * Generate a Python-style method signature string for a verb.
     * Mirrors Python's generate_method_signature(verb_name).
     */
    public function generateMethodSignature(string $verbName): string
    {
        $params = $this->getVerbParameters($verbName);
        $required = array_flip($this->getVerbRequiredProperties($verbName));
        $parts = ['self'];
        $keys = array_keys($params);
        sort($keys);
        foreach ($keys as $name) {
            $name = (string) $name;
            $t = $this->pythonTypeAnnotation($params[$name]);
            if (isset($required[$name])) {
                $parts[] = "$name: $t";
            } else {
                $parts[] = "$name: Optional[$t] = None";
            }
        }
        $parts[] = '**kwargs';
        $doc = "\"\"\"\n        Add the $verbName verb to the current document\n        \n";
        foreach ($keys as $name) {
            $name = (string) $name;
            $desc = '';
            if (is_array($params[$name])) {
                $rawDesc = $params[$name]['description'] ?? '';
                $desc = is_string($rawDesc) ? trim(str_replace("\n", ' ', $rawDesc)) : '';
            }
            $doc .= "        Args:\n            $name: $desc\n";
        }
        $doc .= "        \n        Returns:\n            True if the verb was added successfully, False otherwise\n        \"\"\"\n";
        return "def $verbName(" . implode(', ', $parts) . ") -> bool:\n$doc";
    }

    /**
     * Generate a Python-style method body string for a verb.
     * Mirrors Python's generate_method_body(verb_name).
     */
    public function generateMethodBody(string $verbName): string
    {
        $params = $this->getVerbParameters($verbName);
        $keys = array_keys($params);
        sort($keys);
        $lines = [
            '        # Prepare the configuration',
            '        config = {}',
        ];
        foreach ($keys as $name) {
            $name = (string) $name;
            $lines[] = "        if $name is not None:";
            $lines[] = "            config['$name'] = $name";
        }
        $lines[] = '        # Add any additional parameters from kwargs';
        $lines[] = '        for key, value in kwargs.items():';
        $lines[] = '            if value is not None:';
        $lines[] = '                config[key] = value';
        $lines[] = '';
        $lines[] = "        # Add the $verbName verb";
        $lines[] = "        return self.add_verb('$verbName', config)";
        return implode("\n", $lines);
    }

    /**
     * @param mixed $def
     */
    private function pythonTypeAnnotation($def): string
    {
        if (!is_array($def)) {
            return 'Any';
        }
        $t = $def['type'] ?? null;
        if ($t === 'string') {
            return 'str';
        }
        if ($t === 'integer') {
            return 'int';
        }
        if ($t === 'number') {
            return 'float';
        }
        if ($t === 'boolean') {
            return 'bool';
        }
        if ($t === 'array') {
            $item = 'Any';
            if (isset($def['items']) && is_array($def['items'])) {
                $item = $this->pythonTypeAnnotation($def['items']);
            }
            return "List[$item]";
        }
        if ($t === 'object') {
            return 'Dict[str, Any]';
        }
        return 'Any';
    }
}
