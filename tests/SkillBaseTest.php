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
        // SkillBase::defineTool merges the skill's swaig_fields into the tool's
        // parameters (PHP's Service::defineTool carries them under argument.properties).
        $this->assertSame('abc', $fn['argument']['properties']['meta_data_token'] ?? null);
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
}
