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

    /** @var array<string, array{name: string, schema_name: string, definition: array<string,mixed>}> */
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
     *
     * Mirrors Python's `SchemaUtils.full_validation_available` property. PHP
     * exposes it as a bare boolean accessor (the property idiom → method),
     * named to match the canonical `full_validation_available` after
     * camelCase→snake_case projection.
     */
    public function fullValidationAvailable(): bool
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
        return self::onlyStringKeys($inner);
    }

    /**
     * Keep only string-keyed entries. JSON objects always decode to string
     * keys, so this narrows array<mixed,mixed> to array<string,mixed> without
     * discarding any real schema data.
     *
     * @param array<mixed, mixed> $arr
     * @return array<string, mixed>
     */
    private static function onlyStringKeys(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }
        return $out;
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
        return self::onlyStringKeys($props);
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

        // Closed-key + type check (strict-render contract, Wave-2 P#5). The
        // reference enforces this via jsonschema-rs full-document validation:
        // a MISSPELLED or UNKNOWN key on a closed verb, and a wrong-typed value,
        // must be REJECTED -- never silently dropped/accepted. We walk the
        // verb's resolved inner schema (the $defs fragment under
        // properties[verb], following $ref/oneOf) rather than pull in a full
        // Draft-2020-12 engine. Only verbs whose schema is CLOSED
        // (unevaluatedProperties/additionalProperties disallows extras) get the
        // stray-key check, so an open verb never false-reds.
        if (isset($this->verbs[$verbName])) {
            $inner = $this->getVerbProperties($verbName);
            $errors = array_merge(
                $errors,
                $this->validateAgainstInnerSchema($verbName, $verbConfig, $inner)
            );
        }

        return [count($errors) === 0, $errors];
    }

    /**
     * Validate a verb config against its resolved inner schema fragment.
     *
     * Handles the three shapes the SWML verb schemas actually use:
     *   - a plain object schema ({type:object, properties, unevaluatedProperties})
     *     -- e.g. answer / record / prompt,
     *   - a `oneOf` of such objects (each `$ref`-resolved) -- e.g. play, where the
     *     config must match exactly one branch,
     *   - a `$ref` to another `$defs` entry -- e.g. ai -> AIObject.
     *
     * @param array<string, mixed> $verbConfig
     * @param array<string, mixed> $inner  the properties[verb] fragment
     * @return list<string>
     */
    private function validateAgainstInnerSchema(string $verbName, array $verbConfig, array $inner): array
    {
        $inner = $this->resolveRef($inner);

        // oneOf: the config must satisfy exactly one branch. Report the branch
        // with the FEWEST errors (the closest match) so the message is useful,
        // mirroring jsonschema's "did not match any branch" surfacing.
        if (isset($inner['oneOf']) && is_array($inner['oneOf'])) {
            $bestErrors = null;
            foreach ($inner['oneOf'] as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $branchErrors = $this->validateAgainstObjectSchema(
                    $verbName,
                    $verbConfig,
                    $this->resolveRef(self::onlyStringKeys($branch))
                );
                if ($branchErrors === []) {
                    return [];  // matched a branch cleanly
                }
                if ($bestErrors === null || count($branchErrors) < count($bestErrors)) {
                    $bestErrors = $branchErrors;
                }
            }
            return $bestErrors ?? [
                "Verb '$verbName' config did not match any allowed shape",
            ];
        }

        return $this->validateAgainstObjectSchema($verbName, $verbConfig, $inner);
    }

    /**
     * Closed-key + type check against a single object schema fragment.
     *
     * @param array<string, mixed> $verbConfig
     * @param array<string, mixed> $schema  a resolved {type:object, properties, ...} fragment
     * @return list<string>
     */
    private function validateAgainstObjectSchema(string $verbName, array $verbConfig, array $schema): array
    {
        $errors = [];
        $props = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $closed = $this->schemaIsClosed($schema);

        foreach ($verbConfig as $key => $value) {
            if (!array_key_exists($key, $props)) {
                if ($closed) {
                    $errors[] = "Unknown property '$key' for verb '$verbName'";
                }
                continue;
            }
            $propSchema = $props[$key];
            if (is_array($propSchema)) {
                $propSchema = self::onlyStringKeys($propSchema);
                if (!$this->valueMatchesType($value, $propSchema)) {
                    $expected = $this->expectedTypeLabel($propSchema);
                    $errors[] = "Property '$key' for verb '$verbName' has the wrong type"
                        . ($expected !== '' ? " (expected $expected)" : '');
                }
            }
        }

        return $errors;
    }

    /**
     * Whether an object schema disallows additional/unevaluated properties.
     *
     * The SWML verb schemas close a verb via `unevaluatedProperties: {not: {}}`
     * (an "allow nothing extra" JSON Schema idiom; decodes to an object with a
     * `not` key). `additionalProperties: false` is the classic form. Either
     * closes the verb; absence leaves it open (and stray keys are tolerated).
     *
     * @param array<string, mixed> $schema
     */
    private function schemaIsClosed(array $schema): bool
    {
        if (array_key_exists('additionalProperties', $schema)
            && $schema['additionalProperties'] === false) {
            return true;
        }
        $uneval = $schema['unevaluatedProperties'] ?? null;
        // {not: {}} (allow-nothing) closes it; `true`/absent leaves it open.
        if (is_array($uneval)) {
            return array_key_exists('not', $uneval);
        }
        if ($uneval === false) {
            return true;
        }
        return false;
    }

    /**
     * Whether a value satisfies a property schema's declared type(s).
     *
     * Understands a direct `type` and an `anyOf` of `{type}`/`{$ref}` branches
     * (the SWML idiom for "an integer OR a ${var} template string"). A branch
     * that is a `$ref` (e.g. SWMLVar -- a template-string escape hatch) is
     * treated as permissive: any string satisfies it, so a genuinely-wrong type
     * still fails while a valid `${...}` var passes. When no type can be
     * determined, the value is accepted (never false-red on an unmodelled
     * schema).
     *
     * @param array<string, mixed> $propSchema
     */
    private function valueMatchesType(mixed $value, array $propSchema): bool
    {
        if (isset($propSchema['anyOf']) && is_array($propSchema['anyOf'])) {
            foreach ($propSchema['anyOf'] as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $branch = self::onlyStringKeys($branch);
                // A `$ref` branch (e.g. SWMLVar -- a ${var} template-string
                // escape hatch) is resolved to its target and matched by that
                // target's type + pattern. This is what keeps
                // {max_duration: "notanumber"} rejected while
                // {max_duration: "${dur}"} passes: SWMLVar is a string whose
                // pattern demands the ${...}/%{...} form.
                if (isset($branch['$ref'])) {
                    if ($this->valueMatchesType($value, $this->resolveRef($branch))) {
                        return true;
                    }
                    continue;
                }
                if ($this->valueMatchesType($value, $branch)) {
                    return true;
                }
            }
            return false;
        }

        $type = $propSchema['type'] ?? null;
        if (!is_string($type)) {
            return true;  // untyped / composite we don't model -- accept
        }
        if (!$this->valueMatchesJsonType($value, $type)) {
            return false;
        }
        // Honor a string `pattern` when present (SWMLVar and friends).
        $pattern = $propSchema['pattern'] ?? null;
        if ($type === 'string' && is_string($pattern) && is_string($value)) {
            $delim = '~' . str_replace('~', '\~', $pattern) . '~';
            if (@preg_match($delim, $value) !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a PHP value matches a single JSON Schema primitive type name.
     */
    private function valueMatchesJsonType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            // JSON has one number type; an int is a valid number. PHP's
            // is_int/is_float are already false for booleans.
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value),
            'null' => $value === null,
            default => true,
        };
    }

    /**
     * Human-readable expected-type label for an error message.
     *
     * @param array<string, mixed> $propSchema
     */
    private function expectedTypeLabel(array $propSchema): string
    {
        if (is_string($propSchema['type'] ?? null)) {
            return $propSchema['type'];
        }
        if (isset($propSchema['anyOf']) && is_array($propSchema['anyOf'])) {
            $types = [];
            foreach ($propSchema['anyOf'] as $branch) {
                if (is_array($branch) && is_string($branch['type'] ?? null)) {
                    $types[] = $branch['type'];
                }
            }
            return implode(' or ', $types);
        }
        return '';
    }

    /**
     * Resolve a single-level `$ref` into the `$defs` fragment it points at.
     * Non-ref schemas are returned unchanged. Only local `#/$defs/<name>` refs
     * are resolved (the only form the SWML schema uses).
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function resolveRef(array $schema): array
    {
        $ref = $schema['$ref'] ?? null;
        if (!is_string($ref)) {
            return $schema;
        }
        $prefix = '#/$defs/';
        if (!str_starts_with($ref, $prefix)) {
            return $schema;
        }
        $name = substr($ref, strlen($prefix));
        $defs = $this->schema['$defs'] ?? null;
        $target = is_array($defs) ? ($defs[$name] ?? null) : null;
        return is_array($target) ? self::onlyStringKeys($target) : $schema;
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
