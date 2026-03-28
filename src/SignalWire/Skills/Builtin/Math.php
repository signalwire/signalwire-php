<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class Math extends SkillBase
{
    public function getName(): string
    {
        return 'math';
    }

    public function getDescription(): string
    {
        return 'Perform basic mathematical calculations';
    }

    public function setup(): bool
    {
        return true;
    }

    public function registerTools(): void
    {
        $this->defineTool(
            'calculate',
            'Perform a mathematical calculation with basic operations (+, -, *, /, %, **)',
            [
                'expression' => [
                    'type' => 'string',
                    'description' => 'The mathematical expression to evaluate (e.g., "2 + 3 * 4")',
                    'required' => true,
                ],
            ],
            function (array $args, array $rawData): FunctionResult {
                $result = new FunctionResult();
                $expression = $args['expression'] ?? '';

                if ($expression === '') {
                    $result->setResponse('Error: No expression provided.');
                    return $result;
                }

                // Validate: only allow numbers, operators, parentheses, dots, spaces
                if (!preg_match('/^[\d\s\+\-\*\/\%\.\(\)\^]+$/', $expression)) {
                    $result->setResponse('Error: Invalid characters in expression. Only numbers, operators (+, -, *, /, %, **), parentheses, and decimal points are allowed.');
                    return $result;
                }

                // Replace ^ with ** for exponentiation
                $expression = str_replace('^', '**', $expression);

                try {
                    // Safe eval with restricted expression
                    $value = @eval('return (' . $expression . ');');

                    if ($value === false || $value === null) {
                        $result->setResponse('Error: Could not evaluate expression "' . $args['expression'] . '".');
                    } elseif (is_infinite($value)) {
                        $result->setResponse('Error: Division by zero or overflow in expression.');
                    } elseif (is_nan($value)) {
                        $result->setResponse('Error: Result is not a number.');
                    } else {
                        $result->setResponse('The result of ' . $args['expression'] . ' is ' . (string) $value);
                    }
                } catch (\Throwable $e) {
                    $result->setResponse('Error evaluating expression: ' . $e->getMessage());
                }

                return $result;
            }
        );
    }

    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        return [
            [
                'title' => 'Mathematical Calculations',
                'body' => 'You can perform mathematical calculations.',
                'bullets' => [
                    'Supported operators: + (add), - (subtract), * (multiply), / (divide), % (modulo), ** (power)',
                    'Parentheses can be used for grouping.',
                    'Use the calculate tool with a string expression.',
                ],
            ],
        ];
    }
}
