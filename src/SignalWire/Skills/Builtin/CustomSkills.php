<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;

/**
 * Registers caller-supplied tool definitions from the `tools` param, so an
 * application can inject its own SWAIG functions through the skill system.
 *
 * Each entry is dispatched on its shape: an entry with a `function` key is
 * registered as a RAW SWAIG definition (with this skill's `swaig_fields`
 * merged in at the top level); an entry with a `name` key is a standard tool
 * definition, registered with its `handler` when one is callable and as a
 * handler-less SWAIG function otherwise. Malformed entries are skipped
 * silently rather than raising.
 */
class CustomSkills extends SkillBase
{
    /** The name. */
    public function getName(): string
    {
        return 'custom_skills';
    }

    /** The description. */
    public function getDescription(): string
    {
        return 'Register user-defined custom tools';
    }

    /**
     * True — an agent may load several custom-tool bundles. This skill does
     * not override {@see \SignalWire\Skills\SkillBase::getInstanceKey()}, so
     * they are kept apart by the base key's `tool_name` suffix.
     */
    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    /** Always succeeds — this skill has no configuration to validate. */
    public function setup(): bool
    {
        return true;
    }

    /**
     * Register each entry of the `tools` param. A non-array `tools` value, or
     * an entry that is neither a `function`- nor `name`-keyed array, is
     * skipped without error.
     */
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
                if (!is_string($name)) {
                    continue;
                }
                $rawDescription = $toolDef['description'] ?? $toolDef['purpose'] ?? '';
                $description = is_string($rawDescription) ? $rawDescription : '';
                $rawParameters = $toolDef['parameters'] ?? $toolDef['properties'] ?? [];
                $parameters = [];
                if (is_array($rawParameters)) {
                    foreach ($rawParameters as $paramKey => $paramValue) {
                        $parameters[(string) $paramKey] = $paramValue;
                    }
                }
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
