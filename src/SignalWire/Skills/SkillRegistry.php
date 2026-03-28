<?php

declare(strict_types=1);

namespace SignalWire\Skills;

class SkillRegistry
{
    private static ?self $instance = null;

    /** @var array<string, string> */
    private array $registeredSkills = [];

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

    private static function snakeToCamel(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }
}
