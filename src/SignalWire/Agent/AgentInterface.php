<?php

declare(strict_types=1);

namespace SignalWire\Agent;

use SignalWire\Skills\SkillName;

/**
 * INTERNAL duck-type contract for the agent receiver that skills configure.
 *
 * This mirrors the TypeScript port's internal `RelayClientLike` pattern: it is
 * NOT part of the public surface and is consumed only internally (by SkillBase,
 * SkillManager, and the skill-dump harness's CapturingAgent). It declares
 * EXACTLY the methods a skill invokes on its agent during setup/registerTools,
 * with AgentBase's real signatures, so the duck-typed `$agent` receiver can be
 * typed precisely without narrowing to the full AgentBase (which would break
 * the SKILL-CONTRACT harness's lightweight CapturingAgent fake).
 *
 * Return types are declared as `static` (the late-static-bound implementing
 * class) so that AgentBase (whose tool methods return `static` via Service) and
 * CapturingAgent (returning itself) are both valid, PHP-compatible
 * implementations — a `self`-typed interface return would reject Service's
 * `static`-returning defineTool().
 *
 * @internal
 */
interface AgentInterface
{
    /**
     * @param array<string, mixed> $params
     */
    public function addSkill(SkillName|string $name, array $params = []): static;

    /**
     * @param list<string> $hints
     */
    public function addHints(array $hints): static;

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $extraFields Top-level SWAIG function-definition fields
     *   (e.g. meta_data_token) — siblings of `argument`. PHP's positional form of Python's
     *   `**swaig_fields`.
     */
    public function defineTool(
        string $name,
        string $description,
        array $parameters,
        callable $handler,
        bool $secure = true,
        array $extraFields = [],
    ): static;

    /**
     * @param list<string> $bullets
     */
    public function promptAddSection(string $title, string $body, array $bullets = []): static;

    /**
     * @param array<string, mixed> $funcDef
     */
    public function registerSwaigFunction(array $funcDef): static;

    /**
     * @param array<string, mixed> $data
     */
    public function updateGlobalData(array $data): static;
}
