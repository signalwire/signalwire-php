<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class SwmlTransfer extends SkillBase
{
    public function getName(): string
    {
        return 'swml_transfer';
    }

    public function getDescription(): string
    {
        return 'Transfer calls between agents based on pattern matching';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    public function setup(): bool
    {
        if (empty($this->params['transfers']) || !is_array($this->params['transfers'])) {
            return false;
        }

        return true;
    }

    public function registerTools(): void
    {
        $toolName = $this->getToolName('transfer_call');
        $transfers = $this->params['transfers'] ?? [];
        $description = $this->params['description'] ?? 'Transfer call based on pattern matching';
        $paramName = $this->params['parameter_name'] ?? 'transfer_type';
        $paramDescription = $this->params['parameter_description'] ?? 'The type of transfer to perform';
        $defaultMessage = $this->params['default_message'] ?? 'Transferring your call, please hold.';
        $requiredFields = $this->params['required_fields'] ?? [];

        $transferKeys = array_keys($transfers);

        $properties = [
            $paramName => [
                'type' => 'string',
                'description' => $paramDescription,
                'enum' => $transferKeys,
            ],
        ];

        $required = [$paramName];

        foreach ($requiredFields as $field) {
            $fieldName = $field['name'] ?? '';
            if ($fieldName === '') {
                continue;
            }
            $properties[$fieldName] = [
                'type' => $field['type'] ?? 'string',
                'description' => $field['description'] ?? $fieldName,
            ];
            $required[] = $fieldName;
        }

        // Build DataMap expressions from transfers
        $expressions = [];

        foreach ($transfers as $pattern => $config) {
            $url = $config['url'] ?? $config['address'] ?? '';
            $message = $config['message'] ?? $defaultMessage;
            $returnMessage = $config['return_message'] ?? '';
            $postProcess = $config['post_process'] ?? false;

            $expression = [
                'string' => '${args.' . $paramName . '}',
                'pattern' => $pattern,
                'output' => [
                    'response' => $message,
                    'action' => [],
                ],
            ];

            if (!empty($url)) {
                if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                    $expression['output']['action'][] = ['transfer_uri' => $url];
                } else {
                    $expression['output']['action'][] = [
                        'SWML' => [
                            'sections' => [
                                'main' => [
                                    ['connect' => ['to' => $url]],
                                ],
                            ],
                        ],
                    ];
                }
            }

            if ($postProcess) {
                $expression['output']['post_process'] = true;
            }

            $expressions[] = $expression;
        }

        $funcDef = [
            'function' => $toolName,
            'purpose' => $description,
            'argument' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
            ],
            'data_map' => [
                'expressions' => $expressions,
            ],
        ];

        if (!empty($this->swaigFields)) {
            $funcDef = array_merge($funcDef, $this->swaigFields);
        }

        $this->agent->registerSwaigFunction($funcDef);
    }

    public function getHints(): array
    {
        $hints = ['transfer', 'connect', 'speak to', 'talk to'];
        $transfers = $this->params['transfers'] ?? [];

        foreach (array_keys($transfers) as $key) {
            // Split transfer keys into hint words
            $words = preg_split('/[\s_\-]+/', (string) $key);
            foreach ($words as $word) {
                $word = trim($word);
                if ($word !== '' && !in_array($word, $hints, true)) {
                    $hints[] = $word;
                }
            }
        }

        return $hints;
    }

    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        $transfers = $this->params['transfers'] ?? [];
        $destinations = [];

        foreach ($transfers as $pattern => $config) {
            $message = $config['message'] ?? '';
            $destinations[] = $pattern . ($message !== '' ? ' - ' . $message : '');
        }

        $sections = [
            [
                'title' => 'Transferring',
                'body' => 'Available transfer destinations:',
                'bullets' => $destinations,
            ],
        ];

        if (!empty($destinations)) {
            $sections[] = [
                'title' => 'Transfer Instructions',
                'body' => 'When the user wants to be transferred:',
                'bullets' => [
                    'Confirm the transfer destination with the user before transferring.',
                    'Use the transfer tool with the appropriate transfer type.',
                ],
            ];
        }

        return $sections;
    }
}
