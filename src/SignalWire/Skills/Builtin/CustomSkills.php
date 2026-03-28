<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class CustomSkills extends SkillBase
{
    public function getName(): string
    {
        return 'custom_skills';
    }

    public function getDescription(): string
    {
        return 'Register user-defined custom tools';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    public function setup(): bool
    {
        return true;
    }

    public function registerTools(): void
    {
        $tools = $this->params['tools'] ?? [];

        if (!is_array($tools)) {
            return;
        }

        foreach ($tools as $toolDef) {
            if (!is_array($toolDef)) {
                continue;
            }

            if (isset($toolDef['function'])) {
                // SWAIG function definition — register as raw SWAIG function
                $funcDef = $toolDef;

                if (!empty($this->swaigFields)) {
                    $funcDef = array_merge($funcDef, $this->swaigFields);
                }

                $this->agent->registerSwaigFunction($funcDef);
            } elseif (isset($toolDef['name'])) {
                // Standard tool definition — register with defineTool
                $name = $toolDef['name'];
                $description = $toolDef['description'] ?? $toolDef['purpose'] ?? '';
                $parameters = $toolDef['parameters'] ?? $toolDef['properties'] ?? [];
                $handler = $toolDef['handler'] ?? null;

                if ($handler !== null && is_callable($handler)) {
                    $this->defineTool($name, $description, $parameters, $handler);
                } else {
                    // Register as SWAIG function without handler
                    $funcDef = [
                        'function' => $name,
                        'purpose' => $description,
                        'argument' => [
                            'type' => 'object',
                            'properties' => $parameters,
                        ],
                    ];

                    // Copy over any extra fields from the tool definition
                    $extraKeys = ['data_map', 'web_hook_url', 'web_hook_auth_user',
                                  'web_hook_auth_password', 'meta_data', 'meta_data_token',
                                  'fillers', 'secure'];

                    foreach ($extraKeys as $key) {
                        if (isset($toolDef[$key])) {
                            $funcDef[$key] = $toolDef[$key];
                        }
                    }

                    if (!empty($this->swaigFields)) {
                        $funcDef = array_merge($funcDef, $this->swaigFields);
                    }

                    $this->agent->registerSwaigFunction($funcDef);
                }
            }
        }
    }
}
