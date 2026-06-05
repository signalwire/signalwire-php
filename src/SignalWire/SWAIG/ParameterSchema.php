<?php

declare(strict_types=1);

namespace SignalWire\SWAIG;

/**
 * Fluent, type-safe builder for a SWAIG tool's parameter schema.
 *
 * Defining a tool's parameters today means hand-writing the JSON-Schema
 * `properties` blob as a nested associative array — exactly the shape
 * {@see \SignalWire\SWML\Service::defineTool()} expects for its
 * `$parameters` argument:
 *
 *     $agent->defineTool('lookup', 'Look it up', [
 *         'service' => ['type' => 'string', 'description' => 'The service'],
 *         'date'    => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
 *     ], $handler);
 *
 * That untyped array is error-prone: a misspelled `'tpye'`, a wrong
 * nesting level, or an `enum` value that drifts from a closed set fails
 * silently at runtime (the model just sees a malformed schema). This
 * builder produces the **byte-identical** `properties` map type-safely —
 * one method per JSON-Schema property kind, with the kind, description,
 * and modifiers checked at the call site:
 *
 *     $params = ParameterSchema::create()
 *         ->string('service', 'The service to look up')
 *         ->string('date', 'YYYY-MM-DD')
 *         ->enum('fmt', RecordFormat::cases(), 'Recording format')
 *         ->required('service', 'date')
 *         ->toArray();           // <- same array as the hand-written form
 *
 *     $agent->defineTool('lookup', 'Look it up', $params, $handler);
 *
 * This is a typed convenience over the SAME wire output, NOT a new
 * format. The untyped-array path keeps working unchanged; the builder is
 * purely additive (Python's reference takes a bare `Dict[str, Any]`, so
 * there is no Python equivalent — see PORT_ADDITIONS.md).
 *
 * Two outputs, matching the two registration paths:
 *
 *   - {@see toArray()} returns the bare `properties` map for the
 *     `defineTool($name, $desc, $parameters, …)` slot. Per-`defineTool`
 *     convention the schema-level `required` list has no home there, so
 *     `required()` names are emitted as a per-property `'required' => true`
 *     flag (the convention used by the built-in Math / WikipediaSearch
 *     skills).
 *   - {@see toArgument()} returns the full `argument` object
 *     `{type: object, properties: {…}, required: […]}` for the
 *     `registerSwaigFunction()` path, with `required` at the argument
 *     level — byte-identical to the hand-written `argument` blocks (the
 *     Joke / ApiNinjasTrivia / GoogleMaps skills).
 *
 * Each property carries a kind + description and accepts optional
 * modifiers (default, enum, format, nested items/object). Closed-set
 * enums integrate the Tier-1 typed enums
 * ({@see RecordFormat} / {@see RecordDirection} / {@see TapDirection} /
 * {@see Codec}) via {@see enum()} — pass `RecordFormat::cases()` and the
 * backing `->value` strings become the schema `enum` array.
 */
final class ParameterSchema
{
    /**
     * Ordered property name => JSON-Schema fragment.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $properties = [];

    /**
     * Names flagged required, in declaration order, de-duplicated.
     *
     * @var list<string>
     */
    private array $required = [];

    /**
     * Start a new, empty schema builder.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Add a `string` property.
     *
     * @param array{default?: mixed, enum?: list<string>|null, format?: string|null, required?: bool} $opts
     */
    public function string(string $name, string $description, array $opts = []): self
    {
        return $this->add($name, 'string', $description, $opts);
    }

    /**
     * Add a `number` property (JSON-Schema `number`: any numeric value).
     *
     * @param array{default?: mixed, enum?: list<mixed>|null, format?: string|null, required?: bool} $opts
     */
    public function number(string $name, string $description, array $opts = []): self
    {
        return $this->add($name, 'number', $description, $opts);
    }

    /**
     * Add an `integer` property.
     *
     * @param array{default?: mixed, enum?: list<int>|null, format?: string|null, required?: bool} $opts
     */
    public function integer(string $name, string $description, array $opts = []): self
    {
        return $this->add($name, 'integer', $description, $opts);
    }

    /**
     * Add a `boolean` property.
     *
     * @param array{default?: mixed, required?: bool} $opts
     */
    public function boolean(string $name, string $description, array $opts = []): self
    {
        return $this->add($name, 'boolean', $description, $opts);
    }

    /**
     * Add a closed-set `string` property whose value must be one of
     * `$values`.
     *
     * The JSON-Schema kind is `string` with an `enum` constraint — the
     * wire shape the built-in skills already emit (e.g. Joke's
     * `{'type':'string','description':…,'enum':['jokes','dadjokes']}`).
     *
     * `$values` accepts:
     *   - a list of scalar wire values: `['jokes', 'dadjokes']`;
     *   - the Tier-1 typed enums via `cases()`: `RecordFormat::cases()` —
     *     each `\BackedEnum` is normalized to its backing `->value`, so
     *     the typo-checked enum and the bare-string form produce the
     *     SAME `enum` array.
     *
     * @param list<\BackedEnum|string|int> $values
     * @param array{default?: mixed, format?: string|null, required?: bool} $opts
     */
    public function enum(string $name, array $values, string $description, array $opts = []): self
    {
        $opts['enum'] = self::normalizeEnumValues($values);
        return $this->add($name, 'string', $description, $opts);
    }

    /**
     * Add an `array` property whose elements are all of kind `$itemsKind`
     * (`string`, `number`, `integer`, `boolean`, or `object`).
     *
     * For `object` items, pass a nested {@see ParameterSchema} via
     * `$opts['items_schema']` to describe the element shape.
     *
     * @param array{
     *     default?: mixed,
     *     items_enum?: list<\BackedEnum|string|int>|null,
     *     items_schema?: self|null,
     *     required?: bool
     * } $opts
     */
    public function array(string $name, string $itemsKind, string $description, array $opts = []): self
    {
        $items = ['type' => $itemsKind];
        if (isset($opts['items_enum']) && $opts['items_enum'] !== null) {
            $items['enum'] = self::normalizeEnumValues($opts['items_enum']);
        }
        if (isset($opts['items_schema']) && $opts['items_schema'] instanceof self) {
            $items['type'] = 'object';
            // Nested object schemas render `required` as a schema-level list
            // (the JSON-Schema norm for nested objects), so use the raw
            // properties — NOT the per-property-flag form toArray() produces.
            $items['properties'] = $opts['items_schema']->properties;
            $itemsRequired = $opts['items_schema']->requiredNames();
            if ($itemsRequired !== []) {
                $items['required'] = $itemsRequired;
            }
        }
        unset($opts['items_enum'], $opts['items_schema']);

        $opts['__items'] = $items;
        return $this->add($name, 'array', $description, $opts);
    }

    /**
     * Add a nested `object` property described by another
     * {@see ParameterSchema} (`$schema`). The nested schema's `required`
     * names are emitted at the nested-object level.
     *
     * @param array{default?: mixed, required?: bool} $opts
     */
    public function object(string $name, self $schema, string $description, array $opts = []): self
    {
        $opts['__object'] = $schema;
        return $this->add($name, 'object', $description, $opts);
    }

    /**
     * Flag one or more already-declared (or yet-to-be-declared) property
     * names as required. Idempotent; preserves first-seen order.
     */
    public function required(string ...$names): self
    {
        foreach ($names as $name) {
            if (!in_array($name, $this->required, true)) {
                $this->required[] = $name;
            }
        }
        return $this;
    }

    /**
     * The bare JSON-Schema `properties` map — the value
     * {@see \SignalWire\SWML\Service::defineTool()} expects for its
     * `$parameters` argument.
     *
     * Byte-identical to the hand-written nested-array form. Because the
     * `defineTool` slot carries no schema-level `required`, names passed
     * to {@see required()} surface as a per-property `'required' => true`
     * flag on each matching property (the Math / WikipediaSearch skill
     * convention). Use {@see toArgument()} when you want the schema-level
     * `required` list instead.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->properties as $name => $frag) {
            if (in_array($name, $this->required, true)) {
                $frag['required'] = true;
            }
            $out[$name] = $frag;
        }
        return $out;
    }

    /**
     * The full JSON-Schema `argument` object:
     * `{type: 'object', properties: {…}}`, plus a top-level `required`
     * list when any names were flagged.
     *
     * Byte-identical to the hand-written `argument` blocks used with
     * {@see \SignalWire\SWML\Service::registerSwaigFunction()} (the Joke /
     * ApiNinjasTrivia / GoogleMaps skills). Here `required` lives at the
     * argument level, so individual properties are NOT marked — use this
     * form, not {@see toArray()}, when you want the schema-level list.
     *
     * @return array<string, mixed>
     */
    public function toArgument(): array
    {
        $argument = [
            'type' => 'object',
            'properties' => $this->properties,
        ];
        if ($this->required !== []) {
            $argument['required'] = $this->required;
        }
        return $argument;
    }

    /**
     * The required-property names, in declaration order.
     *
     * @return list<string>
     */
    public function requiredNames(): array
    {
        return $this->required;
    }

    /**
     * Build one property fragment, preserving a stable key order
     * (type → description → format → enum → items/properties → default)
     * so the output is byte-identical to the conventional hand-written
     * form.
     *
     * @param array<string, mixed> $opts
     */
    private function add(string $name, string $type, string $description, array $opts): self
    {
        $frag = [
            'type' => $type,
            'description' => $description,
        ];

        if (isset($opts['format']) && $opts['format'] !== null) {
            $frag['format'] = $opts['format'];
        }
        if (isset($opts['enum']) && $opts['enum'] !== null) {
            $frag['enum'] = $opts['enum'];
        }
        if (isset($opts['__items'])) {
            $frag['items'] = $opts['__items'];
        }
        if (isset($opts['__object']) && $opts['__object'] instanceof self) {
            // Nested object: schema-level `required` list (the JSON-Schema
            // norm), so use the raw properties rather than the per-property
            // flag form that toArray() emits for the top-level defineTool slot.
            $frag['properties'] = $opts['__object']->properties;
            $nestedRequired = $opts['__object']->requiredNames();
            if ($nestedRequired !== []) {
                $frag['required'] = $nestedRequired;
            }
        }
        if (array_key_exists('default', $opts)) {
            $frag['default'] = $opts['default'];
        }

        $this->properties[$name] = $frag;

        if (!empty($opts['required'])) {
            $this->required($name);
        }

        return $this;
    }

    /**
     * Normalize a heterogeneous list of enum values to their scalar wire
     * form: a `\BackedEnum` (the Tier-1 closed-set enums) yields its
     * backing `->value`; a scalar is passed through unchanged.
     *
     * @param list<\BackedEnum|string|int> $values
     * @return list<string|int>
     */
    private static function normalizeEnumValues(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $out[] = $value instanceof \BackedEnum ? $value->value : $value;
        }
        return $out;
    }
}
