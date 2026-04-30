<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Skills\SkillRegistry;
use SignalWire\Skills\SkillManager;
use SignalWire\Agent\AgentBase;
use SignalWire\Logging\Logger;
use SignalWire\SWML\Schema;

class SkillsTest extends TestCase
{
    protected function setUp(): void
    {
        Logger::reset();
        Schema::reset();
        SkillRegistry::reset();

        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
        putenv('SWML_PROXY_URL_BASE');
        putenv('PORT');
        putenv('SIGNALWIRE_LOG_LEVEL');
        putenv('SIGNALWIRE_LOG_MODE');
    }

    protected function tearDown(): void
    {
        Logger::reset();
        Schema::reset();
        SkillRegistry::reset();

        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
        putenv('SWML_PROXY_URL_BASE');
        putenv('PORT');
        putenv('SIGNALWIRE_LOG_LEVEL');
        putenv('SIGNALWIRE_LOG_MODE');
    }

    private function makeAgent(array $opts = []): AgentBase
    {
        return new AgentBase(array_merge([
            'name' => 'test-agent',
            'basic_auth_user' => 'testuser',
            'basic_auth_password' => 'testpass',
        ], $opts));
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Registry tests (8)
    // ══════════════════════════════════════════════════════════════════════

    public function testRegistryListSkillsReturns18Skills(): void
    {
        $registry = SkillRegistry::instance();
        $skills = $registry->listSkills();

        $this->assertCount(18, $skills);
    }

    public function testRegistryGetFactoryForKnownSkillReturnsClassName(): void
    {
        $registry = SkillRegistry::instance();
        $factory = $registry->getFactory('datetime');

        $this->assertIsString($factory);
        $this->assertSame('SignalWire\\Skills\\Builtin\\Datetime', $factory);
    }

    public function testRegistryGetFactoryForUnknownSkillReturnsNull(): void
    {
        $registry = SkillRegistry::instance();
        $factory = $registry->getFactory('nonexistent_skill_xyz');

        $this->assertNull($factory);
    }

    public function testRegistryRegisterSkillAddsCustomSkill(): void
    {
        $registry = SkillRegistry::instance();
        $registry->registerSkill('my_custom', 'App\\Skills\\MyCustomSkill');

        $factory = $registry->getFactory('my_custom');
        $this->assertSame('App\\Skills\\MyCustomSkill', $factory);

        $skills = $registry->listSkills();
        $this->assertContains('my_custom', $skills);
    }

    public function testRegistryAll18BuiltinNamesPresentInListSkills(): void
    {
        $expected = [
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

        $registry = SkillRegistry::instance();
        $skills = $registry->listSkills();

        foreach ($expected as $name) {
            $this->assertContains($name, $skills, "Builtin skill '{$name}' missing from listSkills");
        }
    }

    public function testRegistrySingletonBehavior(): void
    {
        $a = SkillRegistry::instance();
        $b = SkillRegistry::instance();

        $this->assertSame($a, $b);
    }

    public function testRegistryResetClearsState(): void
    {
        $registry = SkillRegistry::instance();
        $registry->registerSkill('temp_skill', 'Temp\\Skill');
        $this->assertSame('Temp\\Skill', $registry->getFactory('temp_skill'));

        SkillRegistry::reset();

        $fresh = SkillRegistry::instance();
        // After reset the custom skill should be gone; getFactory falls back
        // to class_exists for builtin pattern, which will fail for 'temp_skill'.
        $this->assertNull($fresh->getFactory('temp_skill'));
    }

    // ── add_skill_directory parity with Python ───────────────────────────
    // Mirrors test_registry.py::TestDirectoryScanning::test_add_skill_directory_*

    public function testAddSkillDirectoryValidPath(): void
    {
        $registry = SkillRegistry::instance();
        $tmpDir = sys_get_temp_dir() . '/swphp_skill_dir_' . uniqid();
        mkdir($tmpDir);
        try {
            $registry->addSkillDirectory($tmpDir);
            $paths = $registry->getExternalPaths();
            $this->assertContains($tmpDir, $paths);
        } finally {
            rmdir($tmpDir);
        }
    }

    public function testAddSkillDirectoryNotExistsRaises(): void
    {
        $registry = SkillRegistry::instance();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist/');
        $registry->addSkillDirectory('/no/such/path/swphp_abc123_xyz');
    }

    public function testAddSkillDirectoryNotADirRaises(): void
    {
        $registry = SkillRegistry::instance();
        $tmp = tempnam(sys_get_temp_dir(), 'swphp_skill_file_');
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/not a directory/');
            $registry->addSkillDirectory($tmp);
        } finally {
            unlink($tmp);
        }
    }

    public function testAddSkillDirectoryDeduplicates(): void
    {
        $registry = SkillRegistry::instance();
        $tmpDir = sys_get_temp_dir() . '/swphp_skill_dir_dedup_' . uniqid();
        mkdir($tmpDir);
        try {
            $registry->addSkillDirectory($tmpDir);
            $registry->addSkillDirectory($tmpDir);
            $paths = $registry->getExternalPaths();
            $count = 0;
            foreach ($paths as $p) {
                if ($p === $tmpDir) $count++;
            }
            $this->assertSame(1, $count);
        } finally {
            rmdir($tmpDir);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SkillBase tests (6)
    // ══════════════════════════════════════════════════════════════════════

    public function testDatetimeSkillInstantiationWithMockAgent(): void
    {
        $agent = $this->makeAgent();
        $skill = new \SignalWire\Skills\Builtin\Datetime($agent);

        $this->assertInstanceOf(\SignalWire\Skills\SkillBase::class, $skill);
    }

    public function testSkillBaseGetNameGetDescriptionGetVersion(): void
    {
        $agent = $this->makeAgent();
        $skill = new \SignalWire\Skills\Builtin\Datetime($agent);

        $this->assertSame('datetime', $skill->getName());
        $this->assertSame('Get current date, time, and timezone information', $skill->getDescription());
        $this->assertSame('1.0.0', $skill->getVersion());
    }

    public function testSkillBaseGetRequiredEnvVarsReturnsArray(): void
    {
        $agent = $this->makeAgent();
        $skill = new \SignalWire\Skills\Builtin\Datetime($agent);

        $envVars = $skill->getRequiredEnvVars();
        $this->assertIsArray($envVars);
        // Datetime has no required env vars
        $this->assertEmpty($envVars);
    }

    public function testSkillBaseSupportsMultipleInstancesDefaultFalse(): void
    {
        $agent = $this->makeAgent();
        $skill = new \SignalWire\Skills\Builtin\Datetime($agent);

        $this->assertFalse($skill->supportsMultipleInstances());
    }

    public function testSkillBaseGetInstanceKeyReturnsSkillName(): void
    {
        $agent = $this->makeAgent();

        $skill = new \SignalWire\Skills\Builtin\Datetime($agent);
        $this->assertSame('datetime', $skill->getInstanceKey());

        // With tool_name param, key should include it
        $skill2 = new \SignalWire\Skills\Builtin\Datetime($agent, ['tool_name' => 'custom']);
        $this->assertSame('datetime_custom', $skill2->getInstanceKey());
    }

    public function testSkillBaseGetParameterSchemaReturnsBaseSchema(): void
    {
        $agent = $this->makeAgent();
        $skill = new \SignalWire\Skills\Builtin\Datetime($agent);

        $schema = $skill->getParameterSchema();

        $this->assertIsArray($schema);
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('swaig_fields', $schema['properties']);
        $this->assertArrayHasKey('skip_prompt', $schema['properties']);
        $this->assertArrayHasKey('tool_name', $schema['properties']);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SkillManager tests (8)
    // ══════════════════════════════════════════════════════════════════════

    public function testSkillManagerLoadDatetimeSucceeds(): void
    {
        $agent = $this->makeAgent();
        $manager = new SkillManager($agent);

        // Datetime has no required env vars and setup returns true.
        // Pass skip_prompt to avoid calling mergePromptSections.
        [$ok, $msg] = $manager->loadSkill('datetime', ['skip_prompt' => true]);

        $this->assertTrue($ok);
    }

    public function testSkillManagerLoadSkillReturnsTrueEmptyStringOnSuccess(): void
    {
        $agent = $this->makeAgent();
        $manager = new SkillManager($agent);

        [$ok, $msg] = $manager->loadSkill('datetime', ['skip_prompt' => true]);

        $this->assertTrue($ok);
        $this->assertSame('', $msg);
    }

    public function testSkillManagerDuplicateLoadPrevented(): void
    {
        $agent = $this->makeAgent();
        $manager = new SkillManager($agent);

        $manager->loadSkill('datetime', ['skip_prompt' => true]);
        [$ok, $msg] = $manager->loadSkill('datetime', ['skip_prompt' => true]);

        $this->assertFalse($ok);
        $this->assertStringContainsString('already loaded', $msg);
    }

    public function testSkillManagerUnloadSkillWorks(): void
    {
        $agent = $this->makeAgent();
        $manager = new SkillManager($agent);

        $manager->loadSkill('datetime', ['skip_prompt' => true]);
        $this->assertTrue($manager->hasSkill('datetime'));

        $removed = $manager->unloadSkill('datetime');
        $this->assertTrue($removed);
        $this->assertFalse($manager->hasSkill('datetime'));
    }

    public function testSkillManagerListSkillsReturnsLoadedSkillKeys(): void
    {
        $agent = $this->makeAgent();
        $manager = new SkillManager($agent);

        $this->assertSame([], $manager->listSkills());

        $manager->loadSkill('datetime', ['skip_prompt' => true]);
        $this->assertSame(['datetime'], $manager->listSkills());
    }

    public function testSkillManagerHasSkillTrueAndFalse(): void
    {
        $agent = $this->makeAgent();
        $manager = new SkillManager($agent);

        $this->assertFalse($manager->hasSkill('datetime'));

        $manager->loadSkill('datetime', ['skip_prompt' => true]);
        $this->assertTrue($manager->hasSkill('datetime'));
    }

    public function testSkillManagerLoadMathSkillSucceeds(): void
    {
        $agent = $this->makeAgent();
        $manager = new SkillManager($agent);

        [$ok, $msg] = $manager->loadSkill('math', ['skip_prompt' => true]);

        $this->assertTrue($ok);
        $this->assertSame('', $msg);
    }

    public function testSkillManagerLoadNonexistentSkillReturnsFalseWithError(): void
    {
        $agent = $this->makeAgent();
        $manager = new SkillManager($agent);

        [$ok, $msg] = $manager->loadSkill('totally_bogus_skill');

        $this->assertFalse($ok);
        $this->assertNotEmpty($msg);
        $this->assertStringContainsString('not found', $msg);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Agent integration tests (5)
    // ══════════════════════════════════════════════════════════════════════

    public function testAgentAddSkillDatetimeWorks(): void
    {
        $agent = $this->makeAgent();
        // Use skip_prompt to keep integration clean (avoids mergePromptSections)
        $agent->addSkill('datetime', ['skip_prompt' => true]);

        $this->assertTrue($agent->hasSkill('datetime'));
    }

    public function testAgentHasSkillReturnsTrueAfterAdd(): void
    {
        $agent = $this->makeAgent();

        $this->assertFalse($agent->hasSkill('datetime'));

        $agent->addSkill('datetime', ['skip_prompt' => true]);

        $this->assertTrue($agent->hasSkill('datetime'));
    }

    public function testAgentListSkillsReturnsLoadedSkills(): void
    {
        $agent = $this->makeAgent();

        // Before any skills loaded, listSkills returns empty
        $this->assertSame([], $agent->listSkills());

        $agent->addSkill('datetime', ['skip_prompt' => true]);
        $agent->addSkill('math', ['skip_prompt' => true]);

        $loaded = $agent->listSkills();
        $this->assertContains('datetime', $loaded);
        $this->assertContains('math', $loaded);
        $this->assertCount(2, $loaded);
    }

    public function testAgentRemoveSkillWorks(): void
    {
        $agent = $this->makeAgent();

        $agent->addSkill('datetime', ['skip_prompt' => true]);
        $this->assertTrue($agent->hasSkill('datetime'));

        $agent->removeSkill('datetime');
        $this->assertFalse($agent->hasSkill('datetime'));
    }

    public function testAgentRenderSwmlAfterAddSkillHasToolsFromSkill(): void
    {
        $agent = $this->makeAgent();
        $agent->addSkill('datetime', ['skip_prompt' => true]);

        $swml = $agent->renderSwml();

        // Datetime registers get_current_time and get_current_date tools.
        // These should appear in the SWAIG functions block.
        $aiVerb = null;
        foreach ($swml['sections']['main'] as $verb) {
            if (isset($verb['ai'])) {
                $aiVerb = $verb['ai'];
                break;
            }
        }

        $this->assertNotNull($aiVerb, 'Expected an ai verb in SWML output');
        $this->assertArrayHasKey('SWAIG', $aiVerb);
        $this->assertArrayHasKey('functions', $aiVerb['SWAIG']);

        $functionNames = array_map(
            fn(array $f) => $f['function'],
            $aiVerb['SWAIG']['functions'],
        );

        $this->assertContains('get_current_time', $functionNames);
        $this->assertContains('get_current_date', $functionNames);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Individual skill instantiation test (1)
    // ══════════════════════════════════════════════════════════════════════

    public function testAll18SkillsCanBeInstantiatedWithAgent(): void
    {
        $agent = $this->makeAgent();
        $registry = SkillRegistry::instance();
        $skillNames = $registry->listSkills();

        $this->assertCount(18, $skillNames);

        foreach ($skillNames as $name) {
            $className = $registry->getFactory($name);
            $this->assertNotNull($className, "Factory for '{$name}' should not be null");

            // All builtin classes should exist (autoloaded)
            $this->assertTrue(
                class_exists($className),
                "Class '{$className}' for skill '{$name}' should be loadable",
            );

            // Instantiate -- some may require env vars for setup(), but
            // the constructor itself must not throw.
            $instance = new $className($agent);
            $this->assertInstanceOf(
                \SignalWire\Skills\SkillBase::class,
                $instance,
                "Skill '{$name}' should extend SkillBase",
            );

            // Verify basic accessors work
            $this->assertIsString($instance->getName());
            $this->assertIsString($instance->getDescription());
            $this->assertIsString($instance->getVersion());
            $this->assertIsArray($instance->getRequiredEnvVars());
            $this->assertIsBool($instance->supportsMultipleInstances());
        }
    }
}
