<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

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

    public function getName(): string
    {
        return 'api_ninjas_trivia';
    }

    public function getDescription(): string
    {
        return 'Get trivia questions from API Ninjas';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
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
        $toolName = $this->getToolName('get_trivia');
        $apiKey = $this->params['api_key'] ?? '';
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
                        'enum' => $categories,
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

        if (!empty($this->swaigFields)) {
            $funcDef = array_merge($funcDef, $this->swaigFields);
        }

        $this->agent->registerSwaigFunction($funcDef);
    }
}
