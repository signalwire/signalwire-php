<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;

class PlayBackgroundFile extends SkillBase
{
    /**
     * Initialize the skill with configuration parameters.
     *
     * Mirrors Python `PlayBackgroundFileSkill.__init__` (skill.py:90): after
     * the base constructor runs, validate the `files` configuration up front so
     * a misconfigured skill fails at construction.
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
        $files = $this->params['files'] ?? null;
        if (!is_array($files) || $files === []) {
            throw new \InvalidArgumentException('files parameter must be a non-empty list');
        }
        foreach ($files as $i => $file) {
            if (!is_array($file)) {
                throw new \InvalidArgumentException("File {$i} must be a dictionary");
            }
            foreach (['key', 'description', 'url'] as $field) {
                if (!array_key_exists($field, $file)) {
                    throw new \InvalidArgumentException("File {$i} missing required field: {$field}");
                }
                $value = $file[$field];
                if (!is_string($value) || trim($value) === '') {
                    throw new \InvalidArgumentException(
                        "File {$i} field '{$field}' must be a non-empty string"
                    );
                }
            }
            if (array_key_exists('wait', $file) && !is_bool($file['wait'])) {
                throw new \InvalidArgumentException("File {$i} field 'wait' must be a boolean");
            }
            $key = $file['key'];
            if (!is_string($key) || preg_replace('/[_-]/', '', $key) === '' || !ctype_alnum(preg_replace('/[_-]/', '', $key))) {
                throw new \InvalidArgumentException(
                    "File {$i} key '{$key}' must contain only alphanumeric characters, underscores, and hyphens"
                );
            }
        }
    }

    /** The name. */
    public function getName(): string
    {
        return 'play_background_file';
    }

    /**
     * Narrow a genuinely-mixed value (nested user file config) to a string.
     * Numeric scalars are stringified; anything else → $default.
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
        return 'Control background file playback';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    /**
     * Unique instance key combining the skill name and tool name.
     *
     * Mirrors Python `PlayBackgroundFileSkill.get_instance_key` (skill.py:138).
     */
    public function getInstanceKey(): string
    {
        return 'play_background_file_' . $this->getToolName('play_background_file');
    }

    /**
     * Parameter schema for the Play Background File skill.
     *
     * Mirrors Python `PlayBackgroundFileSkill.get_parameter_schema`
     * (skill.py:53): merges the base schema with the `files` array.
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        $schema = parent::getParameterSchema();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $schema['properties'] = array_merge($properties, [
            'files' => [
                'type' => 'array',
                'description' => 'Array of file configurations to make available for playback',
                'required' => true,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => [
                            'type' => 'string',
                            'description' => 'Unique identifier for the file',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Human-readable description of the file',
                        ],
                        'url' => [
                            'type' => 'string',
                            'description' => 'URL of the audio/video file to play',
                        ],
                        'wait' => [
                            'type' => 'boolean',
                            'description' => 'Whether to wait for file to finish playing',
                            'default' => false,
                        ],
                    ],
                    'required' => ['key', 'description', 'url'],
                ],
            ],
        ]);
        return $schema;
    }

    public function setup(): bool
    {
        if (empty($this->params['files']) || !is_array($this->params['files'])) {
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
     * Generate the SWAIG tool(s) with DataMap expressions.
     *
     * Mirrors Python `PlayBackgroundFileSkill.get_tools` (skill.py:165).
     *
     * @return list<array<string,mixed>>
     */
    public function getTools(): array
    {
        $toolName = $this->getToolName('play_background_file');
        $files = $this->paramArray('files');

        // Build action enum: start_{key} for each file + stop
        $actionEnum = [];
        $descriptions = [];

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $key = self::asString($file['key'] ?? null);
            $description = self::asString($file['description'] ?? null, $key);

            if ($key === '') {
                continue;
            }

            $actionEnum[] = 'start_' . $key;
            $descriptions['start_' . $key] = $description;
        }

        $actionEnum[] = 'stop';
        $descriptions['stop'] = 'Stop current playback';

        // Build DataMap expressions for each action
        $expressions = [];

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $key = self::asString($file['key'] ?? null);
            $url = self::asString($file['url'] ?? null);
            $description = self::asString($file['description'] ?? null, $key);
            $wait = (bool) ($file['wait'] ?? false);

            if ($key === '' || $url === '') {
                continue;
            }

            $actionKey = $wait ? 'play_background_file_wait' : 'play_background_file';

            $expressions[] = [
                'string' => '${args.action}',
                'pattern' => 'start_' . $key,
                'output' => [
                    'response' => 'Now playing: ' . $description,
                    'action' => [
                        [$actionKey => $url],
                    ],
                ],
            ];
        }

        // Stop expression
        $expressions[] = [
            'string' => '${args.action}',
            'pattern' => 'stop',
            'output' => [
                'response' => 'Stopping background playback.',
                'action' => [
                    ['stop_background_file' => true],
                ],
            ],
        ];

        $funcDef = [
            'function' => $toolName,
            'purpose' => 'Control background file playback for ' . $toolName,
            'argument' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'The playback action to perform',
                        'enum' => $actionEnum,
                    ],
                ],
                'required' => ['action'],
            ],
            'data_map' => [
                'expressions' => $expressions,
            ],
        ];

        return [$funcDef];
    }
}
