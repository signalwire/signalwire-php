<?php

declare(strict_types=1);

namespace SignalWire\Skills;

use SignalWire\Agent\AgentInterface;

abstract class SkillBase
{
    // $agent is duck-typed via the INTERNAL AgentInterface: in production it is
    // a SignalWire\Agent\AgentBase (constructed via SkillManager), but the
    // skill-contract tooling (scripts/emit_skills.php) passes a lightweight
    // CapturingAgent fake. Both satisfy AgentInterface, which declares exactly
    // the methods skills invoke on the agent (mirrors TS's RelayClientLike).
    protected AgentInterface $agent;
    /** @var array<string,mixed> */
    protected array $params;
    /** @var array<string,mixed> */
    protected array $swaigFields;

    /**
     * @param array<string,mixed> $params
     */
    public function __construct(AgentInterface $agent, array $params = [])
    {
        $this->agent = $agent;
        $this->params = $params;
        $swaigFields = $params['swaig_fields'] ?? [];
        $this->swaigFields = is_array($swaigFields) ? $swaigFields : [];
    }

    /**
     * The agent this skill was constructed against. The reference exposes it as
     * a plain ``self.agent`` attribute; php held it ``protected`` with no public
     * reader, so a caller who passed the agent in could not read it back.
     */
    public function getAgent(): AgentInterface
    {
        return $this->agent;
    }

    /**
     * The skill's configuration params, as supplied at construction (the
     * reference's ``self.params``).
     *
     * @return array<string,mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Read a string param, narrowing the genuinely-mixed value. Non-string
     * values (including absent keys) fall back to $default. Numeric values
     * are stringified to match the loose typing the platform sends.
     */
    protected function paramString(string $key, string $default = ''): string
    {
        $value = $this->params[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return $default;
    }

    /**
     * Read an int param, narrowing the genuinely-mixed value. Numeric
     * strings and floats are coerced; anything else falls back to $default.
     */
    protected function paramInt(string $key, int $default = 0): int
    {
        $value = $this->params[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        return $default;
    }

    /**
     * Read a float param, narrowing the genuinely-mixed value. Numeric
     * strings and ints are coerced; anything else falls back to $default.
     */
    protected function paramFloat(string $key, float $default = 0.0): float
    {
        $value = $this->params[$key] ?? null;
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        return $default;
    }

    /**
     * Read a bool param, narrowing the genuinely-mixed value via PHP's
     * standard truthiness so '', 0, null, [] are falsey.
     */
    protected function paramBool(string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $this->params)) {
            return $default;
        }
        return (bool) $this->params[$key];
    }

    /**
     * Read an array param, narrowing the genuinely-mixed value. Non-arrays
     * (including absent keys) fall back to an empty array.
     *
     * @return array<mixed>
     */
    protected function paramArray(string $key): array
    {
        $value = $this->params[$key] ?? null;
        return is_array($value) ? $value : [];
    }

    abstract public function setup(): bool;

    abstract public function registerTools(): void;

    abstract public function getName(): string;

    abstract public function getDescription(): string;

    /** The version. */
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

    /**
     * Whether several instances of this skill may be loaded on one agent.
     *
     * False by default, which makes {@see SkillManager::loadSkill()} reject a
     * second load. A skill that overrides this to true must be distinguishable
     * by a `tool_name` param, since that is what varies
     * {@see SkillBase::getInstanceKey()} and keeps the instances from
     * colliding in the manager and in namespaced global_data.
     */
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
     * Prompt sections this skill contributes to the agent, or `[]` when the
     * caller passed `skip_prompt`.
     *
     * FINAL TEMPLATE METHOD — the `skip_prompt` guard lives here and here only,
     * so it cannot be forgotten by a subclass. Mirrors Python
     * `SkillBase.get_prompt_sections` (core/skill_base.py:88-93), which applies
     * the same guard and delegates to a protected hook. Subclasses override
     * {@see self::_getPromptSections()}, never this method.
     *
     * @return list<array{title: string, body?: string, bullets?: list<string>}>
     */
    final public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        return $this->_getPromptSections();
    }

    /**
     * Override this in subclasses to provide prompt sections.
     *
     * Protected hook behind the {@see self::getPromptSections()} template
     * method; the `skip_prompt` guard has already been applied by the time this
     * runs. Mirrors Python `SkillBase._get_prompt_sections`
     * (core/skill_base.py:95-97) — protected there and here, so it is internal
     * plumbing on both sides and is exported by neither.
     *
     * @return list<array{title: string, body?: string, bullets?: list<string>}>
     */
    protected function _getPromptSections(): array
    {
        return [];
    }

    /**
     * Release any resources this skill holds. Called by
     * {@see SkillManager::unloadSkill()}; the base implementation is a no-op
     * that subclasses override. It is NOT expected to un-register the tools,
     * hints, or prompt sections the skill added to the agent.
     */
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

    /** The instance key. */
    public function getInstanceKey(): string
    {
        $key = $this->getName();

        $toolName = $this->params['tool_name'] ?? null;
        if (is_string($toolName) && $toolName !== '') {
            $key .= '_' . $toolName;
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
     * Define a SWAIG tool on the owning agent, automatically merging this
     * skill's swaig_fields into the definition. Skills should use this instead
     * of calling $agent->defineTool() directly. Mirrors Python's
     * `SkillBase.define_tool` (core/skill_base.py:59) and TS's
     * `SkillBase.defineTool`.
     *
     * @param array<string,mixed> $parameters
     */
    public function defineTool(string $name, string $description, array $parameters, callable $handler): void
    {
        // swaig_fields (e.g. meta_data_token) are TOP-LEVEL SWAIG function-definition
        // fields — siblings of `argument`, NOT entries in the parameters schema.
        // Mirrors Python `SkillBase.define_tool`, which forwards them as **swaig_fields
        // (extra_swaig_fields) to agent.define_tool, landing at the function-def top level.
        $this->agent->defineTool($name, $description, $parameters, $handler, false, $this->swaigFields);
    }

    /**
     * Check that every required Composer/PHP package (or extension) declared by
     * this skill is available in the current runtime. Returns false and logs
     * the missing names when any are absent. Mirrors Python's
     * `SkillBase.validate_packages` (core/skill_base.py:114) and TS's
     * `SkillBase.validatePackages`.
     *
     * PHP has no runtime `import` of arbitrary packages the way Python's
     * importlib does; a declared requirement is satisfied when the named
     * class/interface/function exists (autoloadable) or the named extension is
     * loaded — the PHP-idiomatic notion of "the package is installed".
     */
    public function validatePackages(): bool
    {
        $missing = [];
        foreach ($this->getRequiredPackages() as $package) {
            if (!self::packageAvailable($package)) {
                $missing[] = $package;
            }
        }
        if ($missing !== []) {
            \SignalWire\Logging\LoggingConfig::getLogger('signalwire.skills.' . $this->getName())
                ->error('Missing required packages: ' . implode(', ', $missing));
            return false;
        }
        return true;
    }

    /**
     * Required package identifiers for this skill. Override in subclasses.
     * Each entry may be a class/interface/function name (autoload check) or a
     * loaded-extension name.
     *
     * @return list<string>
     */
    protected function getRequiredPackages(): array
    {
        return [];
    }

    private static function packageAvailable(string $package): bool
    {
        return class_exists($package)
            || interface_exists($package)
            || function_exists($package)
            || extension_loaded($package);
    }

    protected function getToolName(string $default): string
    {
        $toolName = $this->params['tool_name'] ?? null;
        return is_string($toolName) && $toolName !== '' ? $toolName : $default;
    }

    /**
     * Get the namespaced key for this skill instance's global_data.
     *
     * Mirrors Python `SkillBase._get_skill_namespace`: uses the `prefix`
     * param when set, otherwise falls back to the instance key, so multiple
     * skill instances store state in global_data without collisions.
     */
    private function getSkillNamespace(): string
    {
        $prefix = $this->params['prefix'] ?? null;
        if (is_string($prefix) && $prefix !== '') {
            return "skill:{$prefix}";
        }
        return 'skill:' . $this->getInstanceKey();
    }

    /**
     * Read this skill instance's namespaced data from raw_data global_data.
     *
     * Mirrors Python `SkillBase.get_skill_data(raw_data)`. Returns the skill's
     * namespaced state, or an empty array if not present.
     *
     * @param array<string,mixed> $rawData The raw_data dict passed to SWAIG
     *        function handlers, expected to contain a `global_data` key.
     * @return array<string,mixed>
     */
    public function getSkillData(array $rawData): array
    {
        $namespace = $this->getSkillNamespace();
        $globalData = $rawData['global_data'] ?? [];
        if (!is_array($globalData)) {
            return [];
        }
        $scoped = $globalData[$namespace] ?? [];
        return is_array($scoped) ? $scoped : [];
    }

    /**
     * Write this skill instance's namespaced data into a FunctionResult.
     *
     * Mirrors Python `SkillBase.update_skill_data(result, data)`: wraps `data`
     * under the skill's namespace key and calls
     * {@see \SignalWire\SWAIG\FunctionResult::updateGlobalData}. Returns the
     * same FunctionResult for chaining.
     *
     * @param array<string,mixed> $data The skill state to store under the namespace.
     */
    public function updateSkillData(
        \SignalWire\SWAIG\FunctionResult $result,
        array $data
    ): \SignalWire\SWAIG\FunctionResult {
        $namespace = $this->getSkillNamespace();
        $result->updateGlobalData([$namespace => $data]);
        return $result;
    }
}
