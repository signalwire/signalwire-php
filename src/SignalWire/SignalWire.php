<?php

declare(strict_types=1);

namespace SignalWire;

use SignalWire\Logging\Logger;
use SignalWire\REST\RestClient as RESTClient;
use SignalWire\Skills\SkillBase;
use SignalWire\Skills\SkillRegistry;

final class SignalWire
{
    public const VERSION = '1.0.0';

    /**
     * Get a logger instance.
     */
    public static function getLogger(string $name = 'signalwire'): Logger
    {
        return Logger::getLogger($name);
    }

    /**
     * Construct a REST client.
     *
     * Mirrors Python's top-level ``signalwire.RestClient(*args, **kwargs)``
     * factory — a thin wrapper that lazy-imports
     * ``signalwire.rest.RestClient`` and instantiates it.
     *
     * The signature accepts two parallel parameters that mirror Python's
     * variadic shape (``*args`` -> ``$args`` array, ``**kwargs`` ->
     * ``$kwargs`` associative array). Either positional credentials in
     * ``$args`` or keyword credentials in ``$kwargs`` (or both) work.
     * The cross-language audit recognises ``list<*>`` ↔ var_positional
     * and ``dict<string,*>`` ↔ var_keyword via type-driven leniency.
     *
     * @param list<string>          $args   Positional credentials
     *                                       (project, token, space)
     * @param array<string, string> $kwargs Keyword credentials
     */
    public static function RestClient(array $args = [], array $kwargs = []): RESTClient
    {
        $project = $args[0] ?? $kwargs['project']
            ?? $kwargs['project_id']
            ?? \getenv('SIGNALWIRE_PROJECT_ID') ?: null;
        $token = $args[1] ?? $kwargs['token']
            ?? \getenv('SIGNALWIRE_API_TOKEN') ?: null;
        $space = $args[2] ?? $kwargs['space']
            ?? $kwargs['host']
            ?? \getenv('SIGNALWIRE_SPACE') ?: null;
        if (!$project || !$token || !$space) {
            throw new \InvalidArgumentException(
                'project, token, and space are required. '
                . 'Provide them as args/kwargs or set SIGNALWIRE_PROJECT_ID, '
                . 'SIGNALWIRE_API_TOKEN, and SIGNALWIRE_SPACE environment variables.'
            );
        }
        return new RESTClient((string)$project, (string)$token, (string)$space);
    }

    /**
     * Register a custom skill class with the global skill registry.
     *
     * Mirrors Python's ``signalwire.register_skill(skill_class)``.
     * Delegates to the singleton {@see SkillRegistry} instance.
     *
     * @param class-string<SkillBase> $skillClass
     */
    public static function register_skill(string $skillClass): void
    {
        // Derive the registration name. SkillBase::getName() is an
        // instance method; instantiate the class to ask, falling back to
        // a SKILL_NAME constant or the snake-cased class basename.
        $name = null;
        if (\defined("$skillClass::SKILL_NAME")) {
            $name = \constant("$skillClass::SKILL_NAME");
        }
        if (!$name && \method_exists($skillClass, 'getName')) {
            try {
                $instance = new $skillClass();
                $name = $instance->getName();
            } catch (\Throwable $e) {
                $name = null;
            }
        }
        if (!$name) {
            $bare = \substr($skillClass, \strrpos($skillClass, '\\') + 1);
            $name = \strtolower(\preg_replace('/(?<!^)[A-Z]/', '_$0', $bare));
        }
        SkillRegistry::instance()->registerSkill($name, $skillClass);
    }

    /**
     * Add a directory to search for skills.
     *
     * Mirrors Python's ``signalwire.add_skill_directory(path)``. Delegates
     * to the singleton {@see SkillRegistry} instance.
     *
     * @throws \InvalidArgumentException when the path doesn't exist or
     *         isn't a directory.
     */
    public static function add_skill_directory(string $path): void
    {
        SkillRegistry::instance()->addSkillDirectory($path);
    }

    /**
     * Get complete schema for all available skills.
     *
     * Mirrors Python's ``signalwire.list_skills_with_params()``. Returns
     * an associative array keyed by skill name with metadata + parameter
     * schema. Useful for GUI configuration tools, API documentation, or
     * programmatic skill discovery.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function list_skills_with_params(): array
    {
        $registry = SkillRegistry::instance();
        if (\method_exists($registry, 'getAllSkillsSchema')) {
            return $registry->getAllSkillsSchema();
        }
        $out = [];
        foreach ($registry->listSkills() as $name) {
            $out[$name] = ['name' => $name, 'parameters' => []];
        }
        return $out;
    }
}
