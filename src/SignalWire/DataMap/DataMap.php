<?php

declare(strict_types=1);

namespace SignalWire\DataMap;

use SignalWire\SWAIG\FunctionResult;

class DataMap
{
    private string $functionName;
    private string $purpose = '';

    /** @var array<string, array{type: string, description: string, enum?: array<string>}> */
    private array $properties = [];

    /** @var list<string> */
    private array $requiredParams = [];

    /** @var list<array<string, mixed>> */
    private array $expressions = [];

    /** @var list<array<string, mixed>> */
    private array $webhooks = [];

    /** @var mixed */
    private $globalOutput = null;
    private bool $hasGlobalOutput = false;

    /** @var array<string>|null */
    private ?array $globalErrorKeys = null;

    public function __construct(string $functionName)
    {
        $this->functionName = $functionName;
    }

    /**
     * Set the LLM-facing tool description (the "purpose"). PROMPT
     * ENGINEERING, not developer documentation.
     *
     * The description string is rendered into the OpenAI tool schema
     * `description` field on every LLM turn. The model reads it to
     * decide WHEN to call this tool. A vague purpose() is the #1
     * cause of "the model has the right tool but doesn't call it"
     * failures with data-map tools.
     *
     * Bad vs good:
     *
     *   BAD : ->purpose('weather api')
     *   GOOD: ->purpose('Get the current weather conditions and '
     *                 . 'forecast for a specific city. Use this '
     *                 . 'whenever the user asks about weather, '
     *                 . 'temperature, rain, or similar conditions '
     *                 . 'in a named location.')
     */
    public function purpose(string $desc): self
    {
        $this->purpose = $desc;
        return $this;
    }

    /**
     * Alias for purpose(). Sets the LLM-facing tool description.
     * This string is read by the model to decide WHEN to call this
     * tool. See purpose() for bad-vs-good examples.
     */
    public function description(string $desc): self
    {
        return $this->purpose($desc);
    }

    /**
     * Add a parameter to this data-map tool — the `$description` is
     * LLM-FACING.
     *
     * Each parameter description is rendered into the OpenAI tool
     * schema under parameters.properties.<name>.description and sent
     * to the model. The model uses it to decide HOW to fill in the
     * argument from user speech. It is prompt engineering, not
     * developer FYI.
     *
     * Bad vs good:
     *
     *   BAD : ->parameter('city', 'string', 'the city')
     *   GOOD: ->parameter('city', 'string',
     *             'The name of the city to get weather for, e.g. '
     *           . '"San Francisco". Ask the user if they did not '
     *           . 'provide one. Include the state or country if the '
     *           . 'city name is ambiguous.')
     *
     * @param array<string> $enum
     */
    public function parameter(
        string $name,
        string $type,
        string $description,
        bool $required = false,
        array $enum = []
    ): self {
        $prop = [
            'type' => $type,
            'description' => $description,
        ];

        if (!empty($enum)) {
            $prop['enum'] = $enum;
        }

        $this->properties[$name] = $prop;

        if ($required && !in_array($name, $this->requiredParams, true)) {
            $this->requiredParams[] = $name;
        }

        return $this;
    }

    /**
     * Add an expression rule.
     */
    public function expression(
        string $testValue,
        string $pattern,
        mixed $output,
        mixed $nomatchOutput = null
    ): self {
        $expr = [
            'string' => $testValue,
            'pattern' => $pattern,
            'output' => $output,
        ];

        if ($nomatchOutput !== null) {
            $expr['nomatch_output'] = $nomatchOutput;
        }

        $this->expressions[] = $expr;
        return $this;
    }

    /**
     * Add a webhook definition.
     *
     * @param array<string, string> $headers
     * @param array<string> $requireArgs
     */
    public function webhook(
        string $method,
        string $url,
        array $headers = [],
        string $formParam = '',
        bool $inputArgsAsParams = false,
        array $requireArgs = []
    ): self {
        $wh = [
            'method' => $method,
            'url' => $url,
        ];

        if (!empty($headers)) {
            $wh['headers'] = $headers;
        }

        if ($formParam !== '') {
            $wh['form_param'] = $formParam;
        }

        if ($inputArgsAsParams) {
            $wh['input_args_as_params'] = true;
        }

        if (!empty($requireArgs)) {
            $wh['require_args'] = $requireArgs;
        }

        $this->webhooks[] = $wh;
        return $this;
    }

    /**
     * Set expressions on the last webhook.
     *
     * @param list<array<string, mixed>> $expressions
     */
    public function webhookExpressions(array $expressions): self
    {
        if (!empty($this->webhooks)) {
            $this->webhooks[array_key_last($this->webhooks)]['expressions'] = $expressions;
        }
        return $this;
    }

    /**
     * Set body on the last webhook.
     *
     * @param array<string, mixed> $data
     */
    public function body(array $data): self
    {
        if (!empty($this->webhooks)) {
            $this->webhooks[array_key_last($this->webhooks)]['body'] = $data;
        }
        return $this;
    }

    /**
     * Set params on the last webhook.
     *
     * @param array<string, mixed> $data
     */
    public function params(array $data): self
    {
        if (!empty($this->webhooks)) {
            $this->webhooks[array_key_last($this->webhooks)]['params'] = $data;
        }
        return $this;
    }

    /**
     * Set foreach on the last webhook.
     *
     * @param array{input_key: string, output_key: string, append?: bool} $config
     */
    public function foreach(array $config): self
    {
        if (!empty($this->webhooks)) {
            $this->webhooks[array_key_last($this->webhooks)]['foreach'] = $config;
        }
        return $this;
    }

    /**
     * Set output on the last webhook.
     *
     * @param mixed $result FunctionResult, array, or string
     */
    public function output(mixed $result): self
    {
        if (!empty($this->webhooks)) {
            $this->webhooks[array_key_last($this->webhooks)]['output'] = self::resolveOutput($result);
        }
        return $this;
    }

    /**
     * Set global fallback output.
     *
     * @param mixed $result FunctionResult, array, or string
     */
    public function fallbackOutput(mixed $result): self
    {
        $this->globalOutput = self::resolveOutput($result);
        $this->hasGlobalOutput = true;
        return $this;
    }

    /**
     * Set error_keys on the last webhook.
     *
     * @param array<string> $keys
     */
    public function errorKeys(array $keys): self
    {
        if (!empty($this->webhooks)) {
            $this->webhooks[array_key_last($this->webhooks)]['error_keys'] = $keys;
        }
        return $this;
    }

    /**
     * Set global error_keys.
     *
     * @param array<string> $keys
     */
    public function globalErrorKeys(array $keys): self
    {
        $this->globalErrorKeys = $keys;
        return $this;
    }

    /**
     * Serialize to a SWAIG function definition array.
     *
     * @return array<string, mixed>
     */
    public function toSwaigFunction(): array
    {
        $func = [
            'function' => $this->functionName,
        ];

        if ($this->purpose !== '') {
            $func['purpose'] = $this->purpose;
        }

        if (!empty($this->properties)) {
            $argument = [
                'type' => 'object',
                'properties' => $this->properties,
            ];

            if (!empty($this->requiredParams)) {
                $argument['required'] = $this->requiredParams;
            }

            $func['argument'] = $argument;
        }

        $dataMap = [];

        if (!empty($this->expressions)) {
            $dataMap['expressions'] = $this->expressions;
        }

        if (!empty($this->webhooks)) {
            $dataMap['webhooks'] = $this->webhooks;
        }

        if ($this->hasGlobalOutput) {
            $dataMap['output'] = $this->globalOutput;
        }

        if ($this->globalErrorKeys !== null) {
            $dataMap['error_keys'] = $this->globalErrorKeys;
        }

        if (!empty($dataMap)) {
            $func['data_map'] = $dataMap;
        }

        return $func;
    }

    // ── Static Helpers ──────────────────────────────────────────────────

    /**
     * Create a simple API tool with minimal configuration.
     *
     * Mirrors Python's module-level `create_simple_api_tool(name, url,
     * response_template, parameters=None, method="GET", headers=None,
     * body=None, error_keys=None)` free function. PHP (PSR-4, file-per-class)
     * cannot declare a module-level free function, so it is hosted here as a
     * static factory on DataMap and projected onto the canonical
     * `signalwire.create_simple_api_tool` via FREE_FUNCTION_PROJECTIONS.
     * Returns the configured {@see DataMap} builder for chaining.
     *
     * @param array<string, array{type?: string, description?: string, required?: bool}>|null $parameters
     * @param array<string, string>|null $headers
     * @param array<string, mixed>|null  $body
     * @param list<string>|null           $errorKeys
     */
    public static function createSimpleApiTool(
        string $name,
        string $url,
        string $responseTemplate,
        ?array $parameters = null,
        string $method = 'GET',
        ?array $headers = null,
        ?array $body = null,
        ?array $errorKeys = null
    ): self {
        $dataMap = new self($name);

        if ($parameters !== null) {
            foreach ($parameters as $paramName => $paramDef) {
                $required = (bool)($paramDef['required'] ?? false);
                $dataMap->parameter(
                    $paramName,
                    $paramDef['type'] ?? 'string',
                    $paramDef['description'] ?? "{$paramName} parameter",
                    $required
                );
            }
        }

        $dataMap->webhook($method, $url, $headers ?? []);

        if ($body !== null) {
            $dataMap->body($body);
        }

        if ($errorKeys !== null) {
            $dataMap->errorKeys($errorKeys);
        }

        $dataMap->output(new FunctionResult($responseTemplate));

        return $dataMap;
    }

    /**
     * Create an expression-based tool for pattern-matching responses.
     *
     * Mirrors Python's module-level `create_expression_tool(name, patterns,
     * parameters=None)` free function, where `patterns` maps a test value to a
     * `(pattern, FunctionResult)` tuple. PHP has no tuple type; each entry is
     * `[test_value => [pattern, FunctionResult]]`. Hosted as a static factory
     * on DataMap (PSR-4) and projected onto the canonical
     * `signalwire.create_expression_tool`. Returns the configured DataMap.
     *
     * @param array<string, array{0: string, 1: FunctionResult}> $patterns
     * @param array<string, array{type?: string, description?: string, required?: bool}>|null $parameters
     */
    public static function createExpressionTool(
        string $name,
        array $patterns,
        ?array $parameters = null
    ): self {
        $dataMap = new self($name);

        if ($parameters !== null) {
            foreach ($parameters as $paramName => $paramDef) {
                $required = (bool)($paramDef['required'] ?? false);
                $dataMap->parameter(
                    $paramName,
                    $paramDef['type'] ?? 'string',
                    $paramDef['description'] ?? "{$paramName} parameter",
                    $required
                );
            }
        }

        foreach ($patterns as $testValue => $spec) {
            [$pattern, $result] = $spec;
            $dataMap->expression($testValue, $pattern, $result);
        }

        return $dataMap;
    }

    /**
     * Convert a result value: FunctionResult calls toArray(), arrays and strings pass through.
     */
    private static function resolveOutput(mixed $result): mixed
    {
        if ($result instanceof FunctionResult) {
            return $result->toArray();
        }

        return $result;
    }
}
