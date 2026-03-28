<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

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

    public function getGlobalData(): array
    {
        return ['joke_skill_enabled' => true];
    }

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
