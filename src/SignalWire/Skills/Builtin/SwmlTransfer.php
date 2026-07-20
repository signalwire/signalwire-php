<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;

class SwmlTransfer extends SkillBase
{
    /** The name. */
    public function getName(): string
    {
        return 'swml_transfer';
    }

    /**
     * Narrow a genuinely-mixed value (nested user transfer config) to a
     * string. Numeric scalars are stringified; anything else → $default.
     */
    private static function asString(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return $default;
    }

    /** The description. */
    public function getDescription(): string
    {
        return 'Transfer calls between agents based on pattern matching';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    /**
     * Unique instance key for this skill instance.
     *
     * Mirrors Python `SWMLTransferSkill.get_instance_key` (skill.py:121): the
     * tool name (default 'transfer_call') differentiates instances.
     */
    public function getInstanceKey(): string
    {
        return 'swml_transfer_' . $this->getToolName('transfer_call');
    }

    /**
     * Parameter schema for the SWML Transfer skill.
     *
     * Mirrors Python `SWMLTransferSkill.get_parameter_schema` (skill.py:30).
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        $schema = parent::getParameterSchema();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $schema['properties'] = array_merge($properties, [
            'transfers' => [
                'type' => 'object',
                'description' => 'Transfer configurations mapping patterns to destinations',
                'required' => true,
                'additionalProperties' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'SWML endpoint URL for agent transfer',
                        ],
                        'address' => [
                            'type' => 'string',
                            'description' => 'Phone number or SIP address for direct connect',
                        ],
                        'message' => [
                            'type' => 'string',
                            'description' => 'Message to say before transferring',
                            'default' => 'Transferring you now...',
                        ],
                        'return_message' => [
                            'type' => 'string',
                            'description' => 'Message when returning from transfer',
                            'default' => 'The transfer is complete. How else can I help you?',
                        ],
                        'post_process' => [
                            'type' => 'boolean',
                            'description' => 'Whether to process message with AI before saying',
                            'default' => true,
                        ],
                        'final' => [
                            'type' => 'boolean',
                            'description' => 'Whether transfer is permanent (true) or temporary (false)',
                            'default' => true,
                        ],
                        'from_addr' => [
                            'type' => 'string',
                            'description' => 'Caller ID for connect action (optional)',
                        ],
                    ],
                ],
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Description for the transfer tool',
                'default' => 'Transfer call based on pattern matching',
                'required' => false,
            ],
            'parameter_name' => [
                'type' => 'string',
                'description' => 'Name of the parameter that accepts the transfer type',
                'default' => 'transfer_type',
                'required' => false,
            ],
            'parameter_description' => [
                'type' => 'string',
                'description' => 'Description for the transfer type parameter',
                'default' => 'The type of transfer to perform',
                'required' => false,
            ],
            'default_message' => [
                'type' => 'string',
                'description' => 'Message when no pattern matches',
                'default' => 'Please specify a valid transfer type.',
                'required' => false,
            ],
            'default_post_process' => [
                'type' => 'boolean',
                'description' => 'Whether to process default message with AI',
                'default' => false,
                'required' => false,
            ],
            'required_fields' => [
                'type' => 'object',
                'description' => 'Additional required fields to collect before transfer',
                'default' => [],
                'required' => false,
                'additionalProperties' => [
                    'type' => 'string',
                    'description' => 'Field description',
                ],
            ],
        ]);
        return $schema;
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
        $transfers = $this->paramArray('transfers');
        $description = $this->paramString('description', 'Transfer call based on pattern matching');
        $paramName = $this->paramString('parameter_name', 'transfer_type');
        $paramDescription = $this->paramString('parameter_description', 'The type of transfer to perform');
        $defaultMessage = $this->paramString('default_message', 'Transferring your call, please hold.');
        $requiredFields = $this->paramArray('required_fields');

        $properties = [
            $paramName => [
                'type' => 'string',
                'description' => $paramDescription,
            ],
        ];

        $required = [$paramName];

        foreach ($requiredFields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $fieldName = self::asString($field['name'] ?? null);
            if ($fieldName === '') {
                continue;
            }
            $properties[$fieldName] = [
                'type' => self::asString($field['type'] ?? null, 'string'),
                'description' => self::asString($field['description'] ?? null, $fieldName),
            ];
            $required[] = $fieldName;
        }

        // Build DataMap expressions from transfers
        $expressions = [];

        foreach ($transfers as $pattern => $config) {
            if (!is_array($config)) {
                continue;
            }
            $url = self::asString($config['url'] ?? $config['address'] ?? null);
            $message = self::asString($config['message'] ?? null, $defaultMessage);
            $postProcess = (bool) ($config['post_process'] ?? false);

            $expression = [
                'string' => '${args.' . $paramName . '}',
                'pattern' => (string) $pattern,
                'output' => [
                    'response' => $message,
                    'action' => [],
                ],
            ];

            if ($url !== '') {
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

    /**
     * @return list<string>
     */
    public function getHints(): array
    {
        $hints = ['transfer', 'connect', 'speak to', 'talk to'];
        $transfers = $this->paramArray('transfers');

        foreach (array_keys($transfers) as $key) {
            // Split transfer keys into hint words
            $words = preg_split('/[\s_\-]+/', (string) $key) ?: [];
            foreach ($words as $word) {
                $word = trim($word);
                if ($word !== '' && !in_array($word, $hints, true)) {
                    $hints[] = $word;
                }
            }
        }

        return $hints;
    }

    /**
     * @return list<array{title: string, body?: string, bullets?: list<string>}>
     */
    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        $transfers = $this->paramArray('transfers');
        $destinations = [];

        foreach ($transfers as $pattern => $config) {
            $message = is_array($config) ? self::asString($config['message'] ?? null) : '';
            $destinations[] = (string) $pattern . ($message !== '' ? ' - ' . $message : '');
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
