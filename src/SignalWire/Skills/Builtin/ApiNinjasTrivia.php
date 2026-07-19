<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;

class ApiNinjasTrivia extends SkillBase
{
    private const ALL_CATEGORIES = [
        'artliterature',
        'language',
        'sciencenature',
        'general',
        'fooddrink',
        'peopleplaces',
        'geography',
        'historyholidays',
        'entertainment',
        'toysgames',
        'music',
        'mathematics',
        'religionmythology',
        'sportsleisure',
    ];

    /**
     * Initialize the skill with configuration parameters.
     *
     * Mirrors Python `ApiNinjasTriviaSkill.__init__` (skill.py:82): after the
     * base constructor runs, validate the api_key and categories up front so a
     * misconfigured skill fails at construction rather than at register time.
     *
     * @param array<string,mixed> $params
     */
    public function __construct(\SignalWire\Agent\AgentInterface $agent, array $params = [])
    {
        parent::__construct($agent, $params);
        $this->validateConfig();
    }

    private function validateConfig(): void
    {
        $apiKey = $this->params['api_key'] ?? null;
        if (!is_string($apiKey) || $apiKey === '') {
            throw new \InvalidArgumentException(
                'api_key parameter is required and must be a non-empty string'
            );
        }

        $categories = $this->params['categories'] ?? self::ALL_CATEGORIES;
        if (!is_array($categories) || $categories === []) {
            throw new \InvalidArgumentException('categories parameter must be a non-empty list');
        }
        foreach ($categories as $i => $category) {
            if (!is_string($category)) {
                throw new \InvalidArgumentException("Category {$i} must be a string");
            }
            if (!in_array($category, self::ALL_CATEGORIES, true)) {
                $valid = implode(', ', self::ALL_CATEGORIES);
                throw new \InvalidArgumentException(
                    "Category '{$category}' is not valid. Valid categories: {$valid}"
                );
            }
        }
    }

    /** The name. */
    public function getName(): string
    {
        return 'api_ninjas_trivia';
    }

    /** The description. */
    public function getDescription(): string
    {
        return 'Get trivia questions from API Ninjas';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    /**
     * Unique instance key combining the skill name and tool name.
     *
     * Mirrors Python `ApiNinjasTriviaSkill.get_instance_key` (skill.py:146).
     */
    public function getInstanceKey(): string
    {
        return 'api_ninjas_trivia_' . $this->getToolName('get_trivia');
    }

    /**
     * Parameter schema for the API Ninjas Trivia skill.
     *
     * Mirrors Python `ApiNinjasTriviaSkill.get_parameter_schema` (skill.py:211):
     * merges the base schema with api_key + categories.
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        $schema = parent::getParameterSchema();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $categoryOptions = [];
        foreach (self::ALL_CATEGORIES as $key) {
            $categoryOptions[] = $key;
        }
        $schema['properties'] = array_merge($properties, [
            'api_key' => [
                'type' => 'string',
                'description' => 'API Ninjas API key',
                'required' => true,
                'hidden' => true,
                'env_var' => 'API_NINJAS_KEY',
            ],
            'categories' => [
                'type' => 'array',
                'description' => 'List of trivia categories to enable. Available: '
                    . implode(', ', $categoryOptions),
                'default' => self::ALL_CATEGORIES,
                'required' => false,
                'items' => ['type' => 'string'],
            ],
            'tool_name' => [
                'type' => 'string',
                'description' => 'Custom name for the trivia tool',
                'default' => 'get_trivia',
                'required' => false,
            ],
        ]);
        return $schema;
    }

    public function setup(): bool
    {
        if (empty($this->params['api_key'])) {
            return false;
        }

        return true;
    }

    public function registerTools(): void
    {
        foreach ($this->getTools() as $funcDef) {
            if (!empty($this->swaigFields)) {
                $funcDef = array_merge($funcDef, $this->swaigFields);
            }
            $this->agent->registerSwaigFunction($funcDef);
        }
    }

    /**
     * Generate the SWAIG tool(s) with the DataMap webhook.
     *
     * Mirrors Python `ApiNinjasTriviaSkill.get_tools` (skill.py:155).
     *
     * @return list<array<string,mixed>>
     */
    public function getTools(): array
    {
        $toolName = $this->getToolName('get_trivia');
        $apiKey = $this->paramString('api_key');
        $categories = $this->params['categories'] ?? self::ALL_CATEGORIES;

        if (!is_array($categories) || empty($categories)) {
            $categories = self::ALL_CATEGORIES;
        }

        $funcDef = [
            'function' => $toolName,
            'purpose' => 'Get trivia questions for ' . $toolName,
            'argument' => [
                'type' => 'object',
                'properties' => [
                    'category' => [
                        'type' => 'string',
                        'description' => 'The trivia category to get a question from',
                        'enum' => array_values($categories),
                    ],
                ],
                'required' => ['category'],
            ],
            'data_map' => [
                'webhooks' => [
                    [
                        'url' => 'https://api.api-ninjas.com/v1/trivia?category=%{args.category}',
                        'method' => 'GET',
                        'headers' => [
                            'X-Api-Key' => $apiKey,
                        ],
                        'output' => [
                            'response' => 'Category %{array[0].category} question: %{array[0].question} Answer: %{array[0].answer}, be sure to give the user time to answer before saying the answer.',
                            'action' => [['say_it' => true]],
                        ],
                        'error_output' => [
                            'response' => 'Unable to retrieve a trivia question at this time. Please try again.',
                            'action' => [['say_it' => true]],
                        ],
                    ],
                ],
            ],
        ];

        return [$funcDef];
    }
}
