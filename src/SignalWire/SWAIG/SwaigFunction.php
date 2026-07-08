<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\SWAIG;

use SignalWire\Logging\Logger;

/**
 * A SWAIG function — the same concept as a "tool" in native OpenAI / Anthropic
 * tool calling. Each SwaigFunction is rendered into the OpenAI tool schema and
 * sent to the model on every turn; the model reads `description` (and each
 * per-parameter `description`) to decide WHEN to call the tool and HOW to fill
 * in the arguments.
 *
 * Mirrors the `signalwire.core.swaig_function.SWAIGFunction` API
 * (core/swaig_function.py) and the TypeScript `SwaigFunction` (SwaigFunction.ts).
 * The Python/TS `__call__` dunder (a thin `handler(*args)` passthrough) is
 * intentionally not ported — PHP callers invoke {@see execute()} which is the
 * capability; see PORT_OMISSIONS.md.
 */
class SwaigFunction
{
    public string $name;
    /** @var callable */
    private $handler;
    public string $description;
    /** @var array<string,mixed> */
    public array $parameters;
    public bool $secure;
    /** @var array<string,list<string>>|null */
    public ?array $fillers;
    public ?string $waitFile;
    public ?int $waitFileLoops;
    public ?string $webhookUrl;
    /** @var list<string> */
    public array $required;
    /** @var array<string,mixed> */
    public array $extraFields;
    public bool $isTypedHandler;
    /** Whether this tool is externally hosted (has a webhookUrl). */
    public bool $isExternal;

    /**
     * @param array<string,mixed>|null       $parameters JSON Schema properties (or a full schema object).
     * @param array<string,list<string>>|null $fillers   Language-keyed filler phrases.
     * @param list<string>|null              $required   Required parameter names.
     * @param array<string,mixed>            $extraFields Additional SWAIG-only fields merged into
     *   the serialized definition (Python's `**extra_swaig_fields`, e.g. meta_data_token,
     *   web_hook_auth_user). Merged verbatim in {@see toSwaig()}.
     */
    public function __construct(
        string $name,
        callable $handler,
        string $description,
        ?array $parameters = null,
        bool $secure = false,
        ?array $fillers = null,
        ?string $waitFile = null,
        ?int $waitFileLoops = null,
        ?string $webhookUrl = null,
        ?array $required = null,
        bool $isTypedHandler = false,
        array $extraFields = [],
    ) {
        $this->name = $name;
        $this->handler = $handler;
        $this->description = $description;
        $this->parameters = $parameters ?? [];
        $this->secure = $secure;
        $this->fillers = $fillers;
        $this->waitFile = $waitFile;
        $this->waitFileLoops = $waitFileLoops;
        $this->webhookUrl = $webhookUrl;
        $this->required = $required ?? [];
        $this->isTypedHandler = $isTypedHandler;
        $this->extraFields = $extraFields;
        $this->isExternal = $webhookUrl !== null;
    }

    /**
     * Ensure the parameters are correctly structured as a full JSON Schema
     * object (`{type: object, properties: {...}}`). Mirrors Python's
     * `_ensure_parameter_structure`.
     *
     * @return array<string,mixed>
     */
    private function ensureParameterStructure(): array
    {
        if (empty($this->parameters)) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }

        // Already a full schema.
        if (isset($this->parameters['type']) && isset($this->parameters['properties'])) {
            return $this->parameters;
        }

        $result = ['type' => 'object', 'properties' => $this->parameters];
        if (!empty($this->required)) {
            $result['required'] = $this->required;
        }
        return $result;
    }

    /**
     * Invoke the handler with the given arguments and return a serialized
     * result dict (from FunctionResult::toArray()). Mirrors Python's
     * `SWAIGFunction.execute`: any handler return is coerced to a SWAIG
     * response dict, and a thrown handler is contained (never propagated) —
     * the user sees a generic error message so a live call is never broken.
     *
     * @param array<string,mixed>      $args    Parsed arguments from the AI.
     * @param array<string,mixed>|null $rawData Full raw request payload.
     * @return array<string,mixed>
     */
    public function execute(array $args, ?array $rawData = null): array
    {
        try {
            $rawData ??= [];
            $result = ($this->handler)($args, $rawData);

            if ($result instanceof FunctionResult) {
                return $result->toArray();
            }
            if (is_array($result) && array_key_exists('response', $result)) {
                // Already in the correct SWAIG response shape — pass through.
                return $result;
            }
            if (is_array($result)) {
                // Dict without a response key — Python replaces it entirely.
                return (new FunctionResult('Function completed successfully'))->toArray();
            }
            // String / scalar / null — wrap the stringified value.
            return (new FunctionResult(is_scalar($result) ? (string) $result : ''))->toArray();
        } catch (\Throwable $e) {
            Logger::getLogger('SwaigFunction')->error(
                "Error executing SWAIG function {$this->name}: " . $e->getMessage()
            );
            return (new FunctionResult(
                "Sorry, I couldn't complete that action. Please try again or contact support if the issue persists."
            ))->toArray();
        }
    }

    /**
     * Validate arguments against the parameter JSON schema. Honours the
     * `required` and per-property `type`/`enum` constraint keywords (the
     * subset the platform emits). When the schema declares no properties,
     * validation is skipped and args are considered valid — matching Python's
     * early-return path.
     *
     * @param array<string,mixed> $args
     * @return array{0: bool, 1: list<string>} Tuple of (isValid, errors).
     */
    public function validateArgs(array $args): array
    {
        $schema = $this->ensureParameterStructure();
        $properties = $schema['properties'] ?? null;
        if (!is_array($properties) || $properties === []) {
            return [true, []];
        }

        $errors = [];

        // Required-property presence.
        $required = $schema['required'] ?? [];
        if (is_array($required)) {
            foreach ($required as $req) {
                if (is_string($req) && !array_key_exists($req, $args)) {
                    $errors[] = "'{$req}' is a required property";
                }
            }
        }

        // Per-property type + enum constraints.
        foreach ($properties as $prop => $spec) {
            if (!is_string($prop) || !array_key_exists($prop, $args) || !is_array($spec)) {
                continue;
            }
            $value = $args[$prop];
            $expected = $spec['type'] ?? null;
            if (is_string($expected) && !self::matchesJsonType($value, $expected)) {
                $errors[] = "'{$prop}' is not of type '{$expected}'";
            }
            $enum = $spec['enum'] ?? null;
            if (is_array($enum) && !in_array($value, $enum, true)) {
                $errors[] = "'{$prop}' is not one of the allowed values";
            }
        }

        return [count($errors) === 0, $errors];
    }

    /** Check whether a value satisfies a JSON Schema primitive `type`. */
    private static function matchesJsonType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string'  => is_string($value),
            'integer' => is_int($value),
            'number'  => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array'   => is_array($value) && array_is_list($value),
            'object'  => is_array($value) || is_object($value),
            'null'    => $value === null,
            default   => true,
        };
    }

    /**
     * Convert this function to a SWAIG-compatible definition for inclusion in
     * SWML. Mirrors Python's `to_swaig` / TS `toSwaig`.
     *
     * @param bool $includeAuth Whether to include auth credentials in the URL
     *   (matches Python; the token/call_id query is only appended when both
     *   are present, so this flag mirrors the reference signature).
     * @return array<string,mixed>
     */
    public function toSwaig(string $baseUrl, ?string $token = null, ?string $callId = null, bool $includeAuth = true): array
    {
        $url = "{$baseUrl}/swaig";
        if ($includeAuth && $token !== null && $token !== '' && $callId !== null && $callId !== '') {
            $url = "{$url}?token={$token}&call_id={$callId}";
        }

        $def = [
            'function' => $this->name,
            'description' => $this->description,
            'parameters' => $this->ensureParameterStructure(),
        ];

        $def['web_hook_url'] = $url;
        if (!empty($this->fillers)) {
            $def['fillers'] = $this->fillers;
        }
        if ($this->waitFile !== null) {
            $def['wait_file'] = $this->waitFile;
        }
        if ($this->waitFileLoops !== null) {
            $def['wait_file_loops'] = $this->waitFileLoops;
        }

        foreach ($this->extraFields as $k => $v) {
            $def[$k] = $v;
        }

        return $def;
    }
}
