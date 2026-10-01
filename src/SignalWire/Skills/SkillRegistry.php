<?php

declare(strict_types=1);

namespace SignalWire\Skills;

/**
 * Name → implementing-class map for skills, with a process-wide singleton
 * ({@see SkillRegistry::instance()}) that {@see SkillManager} consults.
 *
 * Resolution is lazy and convention-based rather than eager: an unregistered
 * name is snake_case→CamelCase'd and looked up under
 * `SignalWire\Skills\Builtin\`, and a successful hit is memoized into the map.
 * That is why the built-in skill names are a static table here — nothing scans
 * the filesystem to find them.
 */
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

    /**
     * Bind a skill name to its implementing class, overriding the
     * convention-based `Builtin\<CamelName>` lookup {@see getFactory()} would
     * otherwise perform. Re-registering a name replaces the previous binding;
     * the class is not validated here (existence is checked at resolve time by
     * {@see getSkillClass()}).
     *
     * @param string $name      the snake_case skill name callers load by.
     * @param string $className fully-qualified {@see SkillBase} subclass name.
     */
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

    /** The factory. */
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
     * ``SkillRegistry.get_all_skills_schema()`` (registry.py:274) — an
     * associative array keyed by skill name, each entry carrying the same
     * eight fields the reference documents: `name`, `description`, `version`,
     * `supports_multiple_instances`, `required_packages`, `required_env_vars`,
     * `parameters` and `source`.
     *
     * **Metadata is read from the CLASS, never from a live skill.** The
     * reference reads class attributes (``skill_class.SKILL_DESCRIPTION``
     * et al.) and calls ``get_parameter_schema()`` off the class, so it never
     * constructs a skill just to describe one. PHP expresses the same metadata
     * as instance methods returning constants, and {@see SkillBase} requires an
     * {@see \SignalWire\Agent\AgentInterface} at construction — so this uses
     * {@see \ReflectionClass::newInstanceWithoutConstructor()} to obtain an
     * un-constructed instance purely as a receiver for those constant readers.
     * That keeps introspection free of an agent binding, exactly as the
     * reference is: describing a skill must not require standing one up.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllSkillsSchema(): array
    {
        $out = [];
        foreach ($this->listSkills() as $name) {
            $className = $this->getSkillClass($name);
            if ($className === null) {
                continue;
            }

            $source = \in_array($name, self::BUILTIN_SKILL_NAMES, true) ? 'built-in' : 'registered';

            try {
                $meta = self::describeClass($className);
            } catch (\Throwable $e) {
                // One malformed skill must not abort the whole scan (the
                // reference logs and skips too) — but it is LOGGED, never
                // swallowed silently. A silent catch here is what previously
                // turned a hard ArgumentCountError into permanently absent
                // metadata that no caller could see.
                \SignalWire\Logging\LoggingConfig::getLogger('signalwire.skills.registry')
                    ->error("Failed to get schema for skill '{$name}': " . $e->getMessage());
                continue;
            }

            $out[$name] = ['name' => $name] + $meta + ['source' => $source];
        }
        return $out;
    }

    /**
     * Read one skill class's metadata without constructing it against an agent.
     *
     * @param class-string<SkillBase> $className
     * @return array<string, mixed>
     */
    private static function describeClass(string $className): array
    {
        $reflection = new \ReflectionClass($className);
        if ($reflection->isAbstract() || !$reflection->isSubclassOf(SkillBase::class)) {
            throw new \RuntimeException("not a concrete SkillBase subclass: {$className}");
        }

        /** @var SkillBase $probe */
        $probe = $reflection->newInstanceWithoutConstructor();

        return [
            'description' => $probe->getDescription(),
            'version' => $probe->getVersion(),
            'supports_multiple_instances' => $probe->supportsMultipleInstances(),
            'required_packages' => self::requiredPackagesOf($reflection, $probe),
            'required_env_vars' => $probe->getRequiredEnvVars(),
            'parameters' => $probe->getParameterSchema(),
        ];
    }

    /**
     * {@see SkillBase::getRequiredPackages()} is `protected` (skills declare
     * their requirements for {@see SkillBase::validatePackages()}, not for
     * callers), so reach it reflectively rather than widening the contract just
     * to describe a skill. The reference's `REQUIRED_PACKAGES` is a plain class
     * attribute, hence readable without any such accommodation.
     *
     * @param \ReflectionClass<SkillBase> $reflection
     * @return list<string>
     */
    private static function requiredPackagesOf(\ReflectionClass $reflection, SkillBase $probe): array
    {
        $method = $reflection->getMethod('getRequiredPackages');
        $method->setAccessible(true);
        /** @var list<string> $packages */
        $packages = $method->invoke($probe);
        return $packages;
    }

    private static function snakeToCamel(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }
}
