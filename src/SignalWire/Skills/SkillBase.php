<?php

declare(strict_types=1);

namespace SignalWire\Skills;

abstract class SkillBase
{
    protected object $agent;
    protected array $params;
    protected array $swaigFields;

    public function __construct(object $agent, array $params = [])
    {
        $this->agent = $agent;
        $this->params = $params;
        $this->swaigFields = $params['swaig_fields'] ?? [];
    }

    abstract public function setup(): bool;

    abstract public function registerTools(): void;

    abstract public function getName(): string;

    abstract public function getDescription(): string;

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getRequiredEnvVars(): array
    {
        return [];
    }

    public function supportsMultipleInstances(): bool
    {
        return false;
    }

    public function getHints(): array
    {
        return [];
    }

    public function getGlobalData(): array
    {
        return [];
    }

    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        return [];
    }

    public function cleanup(): void
    {
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'swaig_fields' => [
                    'type' => 'array',
                    'description' => 'Additional SWAIG fields to merge into tool definitions',
                    'default' => [],
                ],
                'skip_prompt' => [
                    'type' => 'boolean',
                    'description' => 'If true, skip adding prompt sections for this skill',
                    'default' => false,
                ],
                'tool_name' => [
                    'type' => 'string',
                    'description' => 'Custom tool name override for this skill instance',
                ],
            ],
        ];
    }

    public function getInstanceKey(): string
    {
        $key = $this->getName();

        if (isset($this->params['tool_name'])) {
            $key .= '_' . $this->params['tool_name'];
        }

        return $key;
    }

    public function validateEnvVars(): array
    {
        $missing = [];

        foreach ($this->getRequiredEnvVars() as $var) {
            if (empty(getenv($var))) {
                $missing[] = $var;
            }
        }

        return $missing;
    }

    protected function defineTool(string $name, string $description, array $parameters, callable $handler): void
    {
        if (!empty($this->swaigFields)) {
            $parameters = array_merge($parameters, $this->swaigFields);
        }

        $this->agent->defineTool($name, $description, $parameters, $handler);
    }

    protected function getToolName(string $default): string
    {
        return $this->params['tool_name'] ?? $default;
    }
}
