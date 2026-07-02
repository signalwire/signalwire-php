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
        if (!empty($this->swaigFields)) {
            $parameters = array_merge($parameters, $this->swaigFields);
        }

        $this->agent->defineTool($name, $description, $parameters, $handler);
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
