<?php

declare(strict_types=1);

namespace SignalWire\Skills;

use SignalWire\Agent\AgentInterface;

class SkillManager
{
    protected AgentInterface $agent;
    /** @var array<string, SkillBase> */
    protected array $loadedSkills = [];
    protected SkillRegistry $registry;

    public function __construct(AgentInterface $agent)
    {
        $this->agent = $agent;
        $this->registry = SkillRegistry::instance();
    }

    /**
     * @param array<string,mixed> $params
     * @return array{bool, string}
     */
    public function loadSkill(string $skillName, array $params = [], ?string $skillClass = null): array
    {
        if ($skillClass === null) {
            $skillClass = $this->registry->getFactory($skillName);

            if ($skillClass === null) {
                return [false, "Skill '{$skillName}' not found in registry"];
            }
        }

        /** @var SkillBase $instance */
        $instance = new $skillClass($this->agent, $params);
        $instanceKey = $instance->getInstanceKey();

        if (isset($this->loadedSkills[$instanceKey])) {
            if (!$instance->supportsMultipleInstances()) {
                return [false, "Skill '{$instanceKey}' is already loaded and does not support multiple instances"];
            }
        }

        $missingVars = $instance->validateEnvVars();

        if (!empty($missingVars)) {
            return [false, 'Missing required environment variables: ' . implode(', ', $missingVars)];
        }

        if (!$instance->setup()) {
            return [false, "Skill '{$skillName}' setup failed"];
        }

        $instance->registerTools();

        $hints = $instance->getHints();
        if (!empty($hints)) {
            $this->agent->addHints($hints);
        }

        $globalData = $instance->getGlobalData();
        if (!empty($globalData)) {
            $this->agent->updateGlobalData($globalData);
        }

        $promptSections = $instance->getPromptSections();
        foreach ($promptSections as $section) {
            $this->agent->promptAddSection(
                $section['title'],
                $section['body'] ?? '',
                $section['bullets'] ?? [],
            );
        }

        $this->loadedSkills[$instanceKey] = $instance;

        return [true, ''];
    }

    public function unloadSkill(string $key): bool
    {
        if (!isset($this->loadedSkills[$key])) {
            return false;
        }

        $this->loadedSkills[$key]->cleanup();
        unset($this->loadedSkills[$key]);

        return true;
    }

    /**
     * List the instance keys of currently loaded skills.
     *
     * Mirrors Python `SkillManager.list_loaded_skills()`.
     *
     * @return list<string>
     */
    public function listLoadedSkills(): array
    {
        return array_keys($this->loadedSkills);
    }

    /** Whether there is a skill. */
    public function hasSkill(string $key): bool
    {
        return isset($this->loadedSkills[$key]);
    }

    /** The skill. */
    public function getSkill(string $key): ?SkillBase
    {
        return $this->loadedSkills[$key] ?? null;
    }
}
