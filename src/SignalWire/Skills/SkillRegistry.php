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
        'native_vector_search',
        'play_background_file',
        'spider',
        'swml_transfer',
        'weather_api',
        'web_search',
        'wikipedia_search',
    ];

    /**
     * Construct a fresh registry.
     *
     * Mirrors Python's `SkillRegistry.__init__` (registry.py:26), which
     * initializes the skill map and external-path list. PHP keeps a
     * process-wide singleton via {@see instance()}, but the constructor is
     * public — like Python's module-global `skill_registry = SkillRegistry()`,
     * a fresh registry can be constructed directly. {@see instance()} still
     * returns one shared instance.
     */
    public function __construct()
    {
    }

    public function registerSkill(string $name, string $className): void
    {
        $this->registeredSkills[$name] = $className;
    }

    /**
     * Discover and return all available skills.
     *
     * Mirrors Python's `SkillRegistry.discover_skills` (registry.py:136):
     * skills load on-demand, so there is nothing to eagerly register; this
     * scans the built-in skill set and returns each skill's name so callers
     * can enumerate what is available.
     *
     * @return list<array{name: string}>
     */
    public function discoverSkills(): array
    {
        $out = [];
        foreach ($this->listSkills() as $name) {
            $out[] = ['name' => $name];
        }
        return $out;
    }

    /**
     * Get a skill's implementing class by name, loading on-demand if needed.
     *
     * Mirrors Python's `SkillRegistry.get_skill_class` (registry.py:239):
     * returns the fully-qualified class name for the skill, or null when no
     * such skill is registered or discoverable. PHP returns the class-string
     * (autoloadable FQCN) rather than a Python `type` object.
     *
     * @return class-string<SkillBase>|null
     */
    public function getSkillClass(string $skillName): ?string
    {
        $className = $this->getFactory($skillName);
        if ($className !== null && class_exists($className)) {
            /** @var class-string<SkillBase> $className */
            return $className;
        }
        return null;
    }

    /**
     * List all skill sources and the skills available from each.
     *
     * Mirrors Python's `SkillRegistry.list_all_skill_sources` (registry.py:506):
     * returns a map of source type -> list of skill names. PHP discovers
     * built-ins from the {@see BUILTIN_SKILL_NAMES} table, external skills
     * from directories registered via {@see addSkillDirectory()}, and any
     * directly-registered skills that aren't built-ins.
     *
     * @return array{'built-in': list<string>, external_paths: list<string>, entry_points: list<string>, registered: list<string>}
     */
    public function listAllSkillSources(): array
    {
        $sources = [
            'built-in' => self::BUILTIN_SKILL_NAMES,
            'external_paths' => [],
            'entry_points' => [],
            'registered' => [],
        ];

        // External path skills: each registered directory contributes its
        // immediate sub-directories that look like a skill package.
        foreach ($this->externalPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }
            $entries = scandir($path);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $item) {
                if ($item === '.' || $item === '..' || str_starts_with($item, '__')) {
                    continue;
                }
                if (is_dir($path . DIRECTORY_SEPARATOR . $item)) {
                    $sources['external_paths'][] = $item;
                }
            }
        }

        // Directly-registered skills that aren't built-ins.
        foreach (array_keys($this->registeredSkills) as $skillName) {
            if (!in_array($skillName, $sources['built-in'], true)) {
                $sources['registered'][] = $skillName;
            }
        }

        return $sources;
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

    /**
     * @return list<string>
     */
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
                try {
                    $skill = new $className();
                    if (\method_exists($skill, 'getDescription')) {
                        $entry['description'] = $skill->getDescription();
                    }
                    if (\method_exists($skill, 'getVersion')) {
                        $entry['version'] = $skill->getVersion();
                    }
                } catch (\Throwable $e) {
                    // Skip on construction failure
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
