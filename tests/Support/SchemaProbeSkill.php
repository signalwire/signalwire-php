<?php

declare(strict_types=1);

namespace SignalWire\Tests\Support;

use SignalWire\Skills\SkillBase;

/**
 * A directly-registered (non-built-in) skill used to prove
 * {@see \SignalWire\Skills\SkillRegistry::getAllSkillsSchema()} reads a skill's
 * metadata WITHOUT constructing it against a live agent, and tags a registered
 * skill's provenance as `registered` rather than `built-in`.
 *
 * It deliberately declares non-default metadata (a custom version, a required
 * env var) so an entry that silently fell back to the SkillBase defaults would
 * be visibly wrong rather than accidentally correct.
 */
final class SchemaProbeSkill extends SkillBase
{
    public function setup(): bool
    {
        return true;
    }

    public function registerTools(): void
    {
    }

    public function getName(): string
    {
        return 'registered_probe';
    }

    public function getDescription(): string
    {
        return 'probe description';
    }

    public function getVersion(): string
    {
        return '9.9.9';
    }

    /**
     * @return list<string>
     */
    public function getRequiredEnvVars(): array
    {
        return ['PROBE_KEY'];
    }
}
