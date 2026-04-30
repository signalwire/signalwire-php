<?php

declare(strict_types=1);

namespace SignalWire\Skills;

class SkillRegistry
{
    private static ?self $instance = null;

    /** @var array<string, string> */
    private array $registeredSkills = [];

    /** @var list<string> External skill directories registered via addSkillDirectory(). */
    private array $externalPaths = [];

    private const BUILTIN_SKILL_NAMES = [
        'api_ninjas_trivia',
        'claude_skills',
        'custom_skills',
        'datasphere',
        'datasphere_serverless',
        'datetime',
        'google_maps',
        'info_gatherer',
        'joke',
        'math',
        'mcp_gateway',
        'native_vector_search',
        'play_background_file',
        'spider',
        'swml_transfer',
        'weather_api',
        'web_search',
        'wikipedia_search',
    ];

    private function __construct()
    {
    }

    public function registerSkill(string $name, string $className): void
    {
        $this->registeredSkills[$name] = $className;
    }

    public function getFactory(string $name): ?string
    {
        if (isset($this->registeredSkills[$name])) {
            return $this->registeredSkills[$name];
        }

        $camelName = self::snakeToCamel($name);
        $className = "SignalWire\\Skills\\Builtin\\{$camelName}";

        if (class_exists($className)) {
            $this->registeredSkills[$name] = $className;
            return $className;
        }

        return null;
    }

    public function listSkills(): array
    {
        foreach (self::BUILTIN_SKILL_NAMES as $name) {
            if (!isset($this->registeredSkills[$name])) {
                $camelName = self::snakeToCamel($name);
                $className = "SignalWire\\Skills\\Builtin\\{$camelName}";
                $this->registeredSkills[$name] = $className;
            }
        }

        $names = array_keys($this->registeredSkills);
        sort($names);

        return $names;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Add a directory to search for skills.
     *
     * Mirrors Python's
     * `signalwire.skills.registry.SkillRegistry.add_skill_directory`:
     * validate that the path exists and is a directory, then append it
     * (de-duplicated) to the external paths list. Throws
     * `InvalidArgumentException` (the PHP analog of Python's `ValueError`)
     * for invalid input.
     *
     * @throws \InvalidArgumentException when the path doesn't exist or
     *         isn't a directory.
     */
    public function addSkillDirectory(string $path): void
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("Skill directory does not exist: {$path}");
        }
        if (!is_dir($path)) {
            throw new \InvalidArgumentException("Path is not a directory: {$path}");
        }
        if (!in_array($path, $this->externalPaths, true)) {
            $this->externalPaths[] = $path;
        }
    }

    /**
     * Returns the registered external skill directories.
     * Parity surface for Python's `_external_paths`.
     *
     * @return list<string>
     */
    public function getExternalPaths(): array
    {
        return $this->externalPaths;
    }

    /**
     * Get complete schema for all registered skills.
     *
     * Mirrors Python's instance-method
     * ``SkillRegistry.get_all_skills_schema()`` — returns an associative
     * array keyed by skill name where each entry contains metadata +
     * parameter schema. PHP skills don't carry rich Python-style
     * parameter introspection in v1, so the value defaults to a minimal
     * shape with the skill name; built-ins that expose
     * ``getDescription`` / ``getVersion`` get those merged in.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllSkillsSchema(): array
    {
        $out = [];
        foreach ($this->listSkills() as $name) {
            $entry = ['name' => $name, 'parameters' => []];
            $className = $this->getFactory($name);
            if ($className && \class_exists($className)) {
                if (\method_exists($className, 'getDescription')) {
                    try {
                        $skill = new $className();
                        $entry['description'] = $skill->getDescription();
                    } catch (\Throwable $e) {
                        // Skip on construction failure
                    }
                }
                if (\method_exists($className, 'getVersion')) {
                    try {
                        $skill = $skill ?? new $className();
                        $entry['version'] = $skill->getVersion();
                    } catch (\Throwable $e) {
                        // Skip on construction failure
                    }
                }
            }
            $out[$name] = $entry;
        }
        return $out;
    }

    private static function snakeToCamel(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }
}
