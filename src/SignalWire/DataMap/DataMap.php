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
     * Build a complete SWAIG function definition with a single webhook.
     *
     * @param array<array{name: string, type: string, description: string, required?: bool, enum?: array<string>}> $parameters
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public static function createSimpleApiTool(
        string $name,
        string $purpose,
        array $parameters,
        string $method,
        string $url,
        mixed $output,
        array $headers = []
    ): array {
        $builder = new self($name);
        $builder->purpose($purpose);

        foreach ($parameters as $param) {
            $builder->parameter(
                $param['name'],
                $param['type'],
                $param['description'],
                $param['required'] ?? false,
                $param['enum'] ?? []
            );
        }

        $builder->webhook($method, $url, $headers);
        $builder->output($output);

        return $builder->toSwaigFunction();
    }

    /**
     * Build a complete SWAIG function definition with expressions only.
     *
     * @param array<array{name: string, type: string, description: string, required?: bool, enum?: array<string>}> $parameters
     * @param list<array<string, mixed>> $expressions
     * @return array<string, mixed>
     */
    public static function createExpressionTool(
        string $name,
        string $purpose,
        array $parameters,
        array $expressions
    ): array {
        $builder = new self($name);
        $builder->purpose($purpose);

        foreach ($parameters as $param) {
            $builder->parameter(
                $param['name'],
                $param['type'],
                $param['description'],
                $param['required'] ?? false,
                $param['enum'] ?? []
            );
        }

        foreach ($expressions as $expr) {
            $builder->expression(
                $expr['string'],
                $expr['pattern'],
                $expr['output'],
                $expr['nomatch_output'] ?? null
            );
        }

        return $builder->toSwaigFunction();
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
