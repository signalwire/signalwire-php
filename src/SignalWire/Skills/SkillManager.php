<?php

declare(strict_types=1);

namespace SignalWire\Skills;

class SkillManager
{
    protected object $agent;
    protected array $loadedSkills = [];
    protected SkillRegistry $registry;

    public function __construct(object $agent)
    {
        $this->agent = $agent;
        $this->registry = SkillRegistry::instance();
    }

    /**
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
            return [false, "Missing required environment variables: " . implode(', ', $missingVars)];
        }

        if (!$instance->setup()) {
            return [false, "Skill '{$skillName}' setup failed"];
        }

        $instance->registerTools();

        $hints = $instance->getHints();
        if (!empty($hints)) {
            $this->agent->mergeHints($hints);
        }

        $globalData = $instance->getGlobalData();
        if (!empty($globalData)) {
            $this->agent->mergeGlobalData($globalData);
        }

        $promptSections = $instance->getPromptSections();
        if (!empty($promptSections)) {
            $this->agent->mergePromptSections($promptSections);
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

    public function listSkills(): array
    {
        return array_keys($this->loadedSkills);
    }

    public function hasSkill(string $key): bool
    {
        return isset($this->loadedSkills[$key]);
    }

    public function getSkill(string $key): ?SkillBase
    {
        return $this->loadedSkills[$key] ?? null;
    }
}
