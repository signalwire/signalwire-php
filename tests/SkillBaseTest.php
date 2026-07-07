<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\Tests\Support\Shape;

/**
 * Behavioral parity tests for the SkillBase.define_tool / SkillBase.validate_packages
 * capabilities newly surfaced on PHP's SkillBase. Mirrors the Python reference
 * (core/skill_base.py) and TS oracle (skills/SkillBase.ts).
 */
class SkillBaseTest extends TestCase
{
    private function agent(): AgentBase
    {
        return new AgentBase(name: 't', basicAuthUser: 'u', basicAuthPassword: 'p');
    }

    public function testDefineToolRegistersOnAgent(): void
    {
        $agent = $this->agent();
        $skill = new class ($agent) extends SkillBase {
            public function setup(): bool
            {
                return true;
            }

            public function registerTools(): void
            {
                $this->defineTool('greet', 'Say hi', ['type' => 'object', 'properties' => []], fn () => null);
            }

            public function getName(): string
            {
                return 'greeter';
            }

            public function getDescription(): string
            {
                return 'Greets';
            }
        };

        $skill->registerTools();
        $this->assertTrue($agent->hasFunction('greet'), 'define_tool must register the tool on the agent');
    }

    public function testDefineToolMergesSwaigFields(): void
    {
        $agent = $this->agent();
        $skill = new class ($agent, ['swaig_fields' => ['meta_data_token' => 'abc']]) extends SkillBase {
            public function setup(): bool
            {
                return true;
            }

            public function registerTools(): void
            {
                $this->defineTool('do_thing', 'desc', ['type' => 'object', 'properties' => []], fn () => null);
            }

            public function getName(): string
            {
                return 'thinger';
            }

            public function getDescription(): string
            {
                return 'Thinks';
            }
        };

        $skill->registerTools();
        $fn = $agent->getFunction('do_thing');
        $this->assertNotNull($fn);
        // SkillBase::defineTool forwards the skill's swaig_fields as TOP-LEVEL SWAIG
        // function-definition fields (siblings of `argument`), matching Python's
        // extra_swaig_fields merge — NOT nested inside the parameters schema.
        $this->assertSame('abc', Shape::at($fn, 'meta_data_token'));
        // And the parameters schema is passed through flat (not double-wrapped, not polluted).
        $this->assertSame(['type' => 'object', 'properties' => []], Shape::sub($fn, 'argument'));
    }

    public function testValidatePackagesTrueWhenNoneRequired(): void
    {
        $agent = $this->agent();
        $skill = new class ($agent) extends SkillBase {
            public function setup(): bool
            {
                return true;
            }

            public function registerTools(): void
            {
            }

            public function getName(): string
            {
                return 's';
            }

            public function getDescription(): string
            {
                return 'd';
            }
        };
        $this->assertTrue($skill->validatePackages());
    }

    public function testValidatePackagesDetectsPresentAndMissing(): void
    {
        $agent = $this->agent();

        $present = new class ($agent) extends SkillBase {
            public function setup(): bool
            {
                return true;
            }

            public function registerTools(): void
            {
            }

            public function getName(): string
            {
                return 'present';
            }

            public function getDescription(): string
            {
                return 'd';
            }

            protected function getRequiredPackages(): array
            {
                // json is always loaded; the AuthHandler class is autoloadable.
                return ['json', \SignalWire\Security\AuthHandler::class];
            }
        };
        $this->assertTrue($present->validatePackages());

        $missing = new class ($agent) extends SkillBase {
            public function setup(): bool
            {
                return true;
            }

            public function registerTools(): void
            {
            }

            public function getName(): string
            {
                return 'missing';
            }

            public function getDescription(): string
            {
                return 'd';
            }

            protected function getRequiredPackages(): array
            {
                return ['this_extension_does_not_exist_12345'];
            }
        };
        $this->assertFalse($missing->validatePackages());
    }

    // ------------------------------------------------------------------
    // get_skill_data / update_skill_data (item I) — namespaced global_data
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $params
     */
    private function dataSkill(AgentBase $agent, array $params = []): SkillBase
    {
        return new class ($agent, $params) extends SkillBase {
            public function setup(): bool
            {
                return true;
            }

            public function registerTools(): void
            {
            }

            public function getName(): string
            {
                return 'stateful';
            }

            public function getDescription(): string
            {
                return 'Stateful skill';
            }
        };
    }

    public function testGetSkillDataReadsNamespacedState(): void
    {
        $skill = $this->dataSkill($this->agent(), ['prefix' => 'ns1']);

        $rawData = ['global_data' => [
            'skill:ns1' => ['count' => 3, 'last' => 'x'],
            'other'     => ['ignored' => true],
        ]];

        $data = $skill->getSkillData($rawData);
        $this->assertSame(['count' => 3, 'last' => 'x'], $data);
    }

    public function testGetSkillDataReturnsEmptyWhenAbsent(): void
    {
        $skill = $this->dataSkill($this->agent(), ['prefix' => 'ns2']);
        $this->assertSame([], $skill->getSkillData(['global_data' => []]));
        $this->assertSame([], $skill->getSkillData([]));
    }

    public function testUpdateSkillDataWrapsUnderNamespace(): void
    {
        $skill = $this->dataSkill($this->agent(), ['prefix' => 'ns3']);
        $result = new FunctionResult('ok');

        $returned = $skill->updateSkillData($result, ['count' => 5]);
        $this->assertSame($result, $returned);

        $arr = $result->toArray();
        $update = null;
        foreach (Shape::sub($arr, 'action') as $action) {
            $action = Shape::arr($action);
            if (isset($action['set_global_data'])) {
                $update = $action['set_global_data'];
            }
        }
        $this->assertNotNull($update);
        $this->assertSame(['skill:ns3' => ['count' => 5]], $update);
    }

    public function testSkillNamespaceFallsBackToInstanceKey(): void
    {
        // No prefix -> namespace derives from the instance key (skill name).
        $skill = $this->dataSkill($this->agent());
        $result = new FunctionResult('ok');
        $skill->updateSkillData($result, ['a' => 1]);

        $arr = $result->toArray();
        $update = null;
        foreach (Shape::sub($arr, 'action') as $action) {
            $action = Shape::arr($action);
            if (isset($action['set_global_data'])) {
                $update = $action['set_global_data'];
            }
        }
        $this->assertNotNull($update);
        $this->assertArrayHasKey('skill:stateful', Shape::arr($update));
    }
}
