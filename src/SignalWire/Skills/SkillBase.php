<?php

declare(strict_types=1);

namespace SignalWire\Skills;

abstract class SkillBase
{
    // $agent is duck-typed: in production it is a SignalWire\Agent\AgentBase
    // (constructed via SkillManager from AgentBase), but the skill-contract
    // tooling (scripts/emit_skills.php) passes a lightweight capturing fake
    // that only implements defineTool()/registerSwaigFunction(). Keeping the
    // declared type as `object` preserves that contract. See the deferred
    // phpstan notes for the typed-interface option.
    protected object $agent;
    /** @var array<string,mixed> */
    protected array $params;
    /** @var array<string,mixed> */
    protected array $swaigFields;

    /**
     * @param array<string,mixed> $params
     */
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

    /**
     * @return list<string>
     */
    public function getRequiredEnvVars(): array
    {
        return [];
    }

    public function supportsMultipleInstances(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function getHints(): array
    {
        return [];
    }

    /**
     * @return array<string,mixed>
     */
    public function getGlobalData(): array
    {
        return [];
    }

    /**
     * @return list<array{title: string, body?: string, bullets?: list<string>}>
     */
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

    /**
     * @return array<string,mixed>
     */
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

    /**
     * @return list<string>
     */
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

    /**
     * @param array<string,mixed> $parameters
     */
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
