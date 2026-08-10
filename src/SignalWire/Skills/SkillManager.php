<?php

declare(strict_types=1);

namespace SignalWire\Skills;

use SignalWire\Agent\AgentInterface;

/**
 * Loads skills onto one agent and owns their lifecycle.
 *
 * Instances are keyed by {@see SkillBase::getInstanceKey()} (the skill name,
 * suffixed with a `tool_name` param when given) rather than the bare skill name,
 * so a skill that supports multiple instances can be loaded more than once
 * under distinct tool names. Class lookup falls back to the process-wide
 * {@see SkillRegistry} singleton.
 */
class SkillManager
{
    protected AgentInterface $agent;
    /** @var array<string, SkillBase> */
    protected array $loadedSkills = [];
    protected SkillRegistry $registry;

    /**
     * @param AgentInterface $agent the agent every loaded skill registers its
     *   tools, hints, global data, and prompt sections onto.
     */
    public function __construct(AgentInterface $agent)
    {
        $this->agent = $agent;
        $this->registry = SkillRegistry::instance();
    }

    /**
     * The agent this manager loads skills onto. The reference exposes it as a
     * plain ``self.agent`` attribute; php held it ``protected`` with no public
     * reader.
     */
    public function getAgent(): AgentInterface
    {
        return $this->agent;
    }

    /**
     * Parameter ORDER mirrors the reference (core/skill_manager.py:26):
     * (skill_name, skill_class, params) — `skill_class` is param 1.
     *
     * @param class-string<SkillBase>|null $skillClass
     * @param array<string,mixed>|null $params
     * @return array{bool, string}
     */
    public function loadSkill(string $skillName, ?string $skillClass = null, ?array $params = null): array
    {
        if ($skillClass === null) {
            $skillClass = $this->registry->getFactory($skillName);

            if ($skillClass === null) {
                return [false, "Skill '{$skillName}' not found in registry"];
            }
        }

        /** @var SkillBase $instance */
        $instance = new $skillClass($this->agent, $params ?? []);
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

    /**
     * Unload a skill instance: run its `cleanup()` hook and drop it from the
     * loaded set.
     *
     * Note this does NOT undo the skill's registrations — the tools, hints,
     * global data, and prompt sections {@see SkillManager::loadSkill()} pushed
     * onto the agent stay there.
     *
     * @param string $key the INSTANCE key (see {@see SkillBase::getInstanceKey()}),
     *   not necessarily the bare skill name.
     * @return bool false when no such instance is loaded; true when one was
     *   cleaned up and removed.
     */
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
