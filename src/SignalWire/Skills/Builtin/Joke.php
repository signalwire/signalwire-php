<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;

class Joke extends SkillBase
{
    public function getName(): string
    {
        return 'joke';
    }

    public function getDescription(): string
    {
        return 'Tell jokes using the API Ninjas joke API';
    }

    public function setup(): bool
    {
        if (empty($this->params['api_key'])) {
            return false;
        }

        return true;
    }

    /**
     * Speech-recognition hints for this skill.
     *
     * Mirrors Python `JokeSkill.get_hints` (skill.py:101): no hints provided.
     *
     * @return list<string>
     */
    public function getHints(): array
    {
        return [];
    }

    /**
     * Parameter schema for the joke skill.
     *
     * Mirrors Python `JokeSkill.get_parameter_schema` (skill.py:29): merges the
     * base schema with api_key + tool_name.
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        $schema = parent::getParameterSchema();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $schema['properties'] = array_merge($properties, [
            'api_key' => [
                'type' => 'string',
                'description' => 'API Ninjas API key for joke service',
                'required' => true,
                'hidden' => true,
                'env_var' => 'API_NINJAS_KEY',
            ],
            'tool_name' => [
                'type' => 'string',
                'description' => 'Custom name for the joke tool',
                'default' => 'get_joke',
                'required' => false,
            ],
        ]);
        return $schema;
    }

    public function registerTools(): void
    {
        $toolName = $this->getToolName('get_joke');
        $apiKey = $this->params['api_key'] ?? '';

        $funcDef = [
            'function' => $toolName,
            'purpose' => 'Get a random joke from API Ninjas',
            'argument' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'description' => 'The type of joke to retrieve',
                        'enum' => ['jokes', 'dadjokes'],
                    ],
                ],
                'required' => ['type'],
            ],
            'data_map' => [
                'webhooks' => [
                    [
                        'url' => 'https://api.api-ninjas.com/v1/${args.type}',
                        'method' => 'GET',
                        'headers' => [
                            'X-Api-Key' => $apiKey,
                        ],
                        'output' => [
                            'response' => "Here's a joke: \${array[0].joke}",
                            'action' => [['say_it' => true]],
                        ],
                        'error_output' => [
                            'response' => "Why don't scientists trust atoms? Because they make up everything!",
                            'action' => [['say_it' => true]],
                        ],
                    ],
                ],
            ],
        ];

        if (!empty($this->swaigFields)) {
            $funcDef = array_merge($funcDef, $this->swaigFields);
        }

        $this->agent->registerSwaigFunction($funcDef);
    }

    /**
     * @return array<string,mixed>
     */
    public function getGlobalData(): array
    {
        return ['joke_skill_enabled' => true];
    }

    /**
     * @return list<array{title: string, body?: string, bullets?: list<string>}>
     */
    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        return [
            [
                'title' => 'Joke Telling',
                'body' => 'You can tell jokes to the user.',
                'bullets' => [
                    'Use the joke tool to fetch a random joke.',
                    'Available joke types: "jokes" for general jokes, "dadjokes" for dad jokes.',
                ],
            ],
        ];
    }
}
