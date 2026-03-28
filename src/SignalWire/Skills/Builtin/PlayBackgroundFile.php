<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class PlayBackgroundFile extends SkillBase
{
    public function getName(): string
    {
        return 'play_background_file';
    }

    public function getDescription(): string
    {
        return 'Control background file playback';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
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
        $toolName = $this->getToolName('play_background_file');
        $files = $this->params['files'] ?? [];

        // Build action enum: start_{key} for each file + stop
        $actionEnum = [];
        $descriptions = [];

        foreach ($files as $file) {
            $key = $file['key'] ?? '';
            $description = $file['description'] ?? $key;

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
            $key = $file['key'] ?? '';
            $url = $file['url'] ?? '';
            $description = $file['description'] ?? $key;
            $wait = $file['wait'] ?? false;

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

        if (!empty($this->swaigFields)) {
            $funcDef = array_merge($funcDef, $this->swaigFields);
        }

        $this->agent->registerSwaigFunction($funcDef);
    }
}
