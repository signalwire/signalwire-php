<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\SWAIG;

/**
 * Runtime schema inference and typed-handler wrapping for SWAIG tools.
 *
 * Parity with the Python reference module
 * `signalwire.core.agent.tools.type_inference` (infer_schema /
 * create_typed_handler_wrapper) and the TS oracle `TypeInference.ts`
 * (inferSchema / createTypedHandlerWrapper). Where Python inspects the
 * signature via `inspect` and TS parses `fn.toString()`, PHP uses native
 * Reflection (typed params, defaults, nullability) — the idiomatic
 * introspection path.
 *
 * PHP has no module-level free functions (PSR-4 file-per-class): the two
 * capabilities are hosted as static methods and projected onto the Python
 * module-level names
 *   * signalwire.core.agent.tools.type_inference.infer_schema
 *   * signalwire.core.agent.tools.type_inference.create_typed_handler_wrapper
 * via scripts/enumerate_signatures.py FREE_FUNCTION_PROJECTIONS (mirrors the
 * url_validator.validate_url / security_utils host precedent).
 */
final class TypeInference
{
    /** PHP scalar/builtin type name -> JSON Schema type. */
    private const TYPE_MAP = [
        'string' => 'string',
        'int'    => 'integer',
        'float'  => 'number',
        'bool'   => 'boolean',
        'array'  => 'array',
    ];

    /**
     * Inspect a handler's signature to infer a SWAIG parameter JSON Schema.
     *
     * Mirrors Python's `infer_schema`: returns
     * [parameters, required, description, isTyped, hasRawData]. A handler in
     * the legacy `(array $args, array $rawData)` shape (untyped) is reported as
     * NOT typed (isTyped=false); a handler with named, typed parameters is
     * reported as typed and its schema is built from the parameter types. A
     * `rawData` parameter is excluded from the schema and flagged in hasRawData.
     *
     * @return array{0: array<string,array<string,mixed>>, 1: list<string>, 2: string|null, 3: bool, 4: bool}
     */
    public static function inferSchema(callable $func): array
    {
        $ref = self::reflect($func);
        $params = $ref->getParameters();

        // Variadic (*args / **kwargs analog) — fall back to old style.
        foreach ($params as $p) {
            if ($p->isVariadic()) {
                return [[], [], null, false, false];
            }
        }

        $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $params);

        // Legacy (args, raw_data) / (args) shape with no meaningful hints -> old style.
        if ($names === ['args', 'raw_data'] || $names === ['args']) {
            $meaningful = false;
            foreach ($params as $p) {
                $t = $p->getType();
                if ($t instanceof \ReflectionNamedType && !in_array($t->getName(), ['array', 'mixed'], true)) {
                    $meaningful = true;
                    break;
                }
            }
            if (!$meaningful) {
                return [[], [], null, false, false];
            }
        }

        $description = self::firstDocLine($ref);

        // Zero-param typed tool.
        if ($params === []) {
            return [[], [], $description, true, false];
        }

        $hasRawData = in_array('raw_data', $names, true) || in_array('rawData', $names, true);

        $schemaParams = array_values(array_filter(
            $params,
            static fn (\ReflectionParameter $p): bool => !in_array($p->getName(), ['raw_data', 'rawData'], true),
        ));

        if ($schemaParams === []) {
            return [[], [], $description, true, $hasRawData];
        }

        // Require at least one typed param to treat as typed (parity with
        // Python's has_annotations check).
        $hasTypes = false;
        foreach ($schemaParams as $p) {
            if ($p->getType() !== null) {
                $hasTypes = true;
                break;
            }
        }
        if (!$hasTypes) {
            return [[], [], null, false, false];
        }

        $parameters = [];
        $required = [];
        foreach ($schemaParams as $p) {
            [$prop, $isOptional] = self::resolveType($p->getType());
            $parameters[$p->getName()] = $prop;
            if (!$p->isDefaultValueAvailable() && !$isOptional && !$p->allowsNull()) {
                $required[] = $p->getName();
            }
        }

        return [$parameters, $required, $description, true, $hasRawData];
    }

    /**
     * Wrap a typed handler so it can be invoked with the standard SWAIG
     * calling convention `(array $args, ?array $rawData)`. The wrapper unpacks
     * $args into named arguments for the original handler, passing $rawData as
     * a named `rawData`/`raw_data` argument when $hasRawData is true.
     *
     * Mirrors Python's `create_typed_handler_wrapper`.
     */
    public static function createTypedHandlerWrapper(callable $func, bool $hasRawData): callable
    {
        return static function (array $args, ?array $rawData = null) use ($func, $hasRawData): mixed {
            if ($hasRawData) {
                // PHP named-argument spread: the handler declares rawData (or
                // raw_data); pass it alongside the unpacked args.
                $ref = self::reflect($func);
                $rawKey = 'rawData';
                foreach ($ref->getParameters() as $p) {
                    if ($p->getName() === 'raw_data') {
                        $rawKey = 'raw_data';
                        break;
                    }
                }
                return $func(...[...$args, $rawKey => $rawData ?? []]);
            }
            return $func(...$args);
        };
    }

    /**
     * Resolve a PHP reflection type to a JSON Schema property dict.
     *
     * @return array{0: array<string,mixed>, 1: bool} [schema, isOptional]
     */
    private static function resolveType(?\ReflectionType $type): array
    {
        if (!$type instanceof \ReflectionNamedType) {
            // Union/intersection/unknown -> string, non-optional.
            return [['type' => 'string'], false];
        }
        $name = $type->getName();
        $optional = $type->allowsNull();

        if ($name === 'null') {
            return [['type' => 'string'], true];
        }
        $jsonType = self::TYPE_MAP[$name] ?? 'string';
        return [['type' => $jsonType], $optional];
    }

    private static function reflect(callable $func): \ReflectionFunctionAbstract
    {
        if (is_array($func)) {
            return new \ReflectionMethod($func[0], (string) $func[1]);
        }
        if (is_object($func) && !$func instanceof \Closure) {
            return new \ReflectionMethod($func, '__invoke');
        }
        return new \ReflectionFunction(\Closure::fromCallable($func));
    }

    /** First non-blank line of the handler's docblock, or null. */
    private static function firstDocLine(\ReflectionFunctionAbstract $ref): ?string
    {
        $doc = $ref->getDocComment();
        if ($doc === false) {
            return null;
        }
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $clean = trim(ltrim(trim($line), '/*'));
            if ($clean !== '' && !str_starts_with($clean, '@')) {
                return $clean;
            }
        }
        return null;
    }
}
