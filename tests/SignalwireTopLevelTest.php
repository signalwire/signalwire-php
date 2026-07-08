<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\SignalWire;
use SignalWire\Skills\SkillBase;
use SignalWire\Skills\SkillRegistry;

/**
 * Tests for the top-level convenience entry points exposed on the
 * SignalWire\SignalWire class — RestClient, register_skill,
 * add_skill_directory, list_skills_with_params. These mirror Python's
 * package-level signalwire/__init__.py factory + skill registry helpers.
 */
class SignalwireTopLevelTest extends TestCase
{
    protected function setUp(): void
    {
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        SkillRegistry::reset();
    }

    public function testRestClientFromKeywordCredentials(): void
    {
        $client = SignalWire::RestClient([], [
            'project' => 'p-123',
            'token'   => 't-456',
            'space'   => 'demo.signalwire.com',
        ]);
        // Return type is RESTClient, so instanceof is redundant; assert the
        // keyword credentials actually reached the constructed client.
        $this->assertSame('p-123', $client->getProjectId());
    }

    public function testRestClientFromPositionalCredentials(): void
    {
        $client = SignalWire::RestClient(['proj', 'tok', 'pos.signalwire.com']);
        // Return type is RESTClient; assert the positional credentials landed.
        $this->assertSame('proj', $client->getProjectId());
    }

    public function testRestClientThrowsOnMissingCredentials(): void
    {
        $env = ['SIGNALWIRE_PROJECT_ID', 'SIGNALWIRE_API_TOKEN', 'SIGNALWIRE_SPACE'];
        $saved = [];
        foreach ($env as $k) {
            $saved[$k] = \getenv($k);
            \putenv($k);
        }
        try {
            $this->expectException(\InvalidArgumentException::class);
            SignalWire::RestClient();
        } finally {
            foreach ($env as $k) {
                if ($saved[$k] !== false) {
                    \putenv("{$k}={$saved[$k]}");
                }
            }
        }
    }

    public function testAddSkillDirectoryRecordsThePath(): void
    {
        $tmp = \sys_get_temp_dir() . '/sw-skill-dir-' . \uniqid();
        \mkdir($tmp);
        try {
            SignalWire::add_skill_directory($tmp);
            $paths = SkillRegistry::instance()->getExternalPaths();
            $this->assertContains($tmp, $paths);
        } finally {
            \rmdir($tmp);
        }
    }

    public function testAddSkillDirectoryThrowsOnMissingDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SignalWire::add_skill_directory('/no/such/path/zzz_php_top_level');
    }

    public function testListSkillsWithParamsReturnsSchemaArray(): void
    {
        $schema = SignalWire::list_skills_with_params();
        // Declared return is array<string, array<string, mixed>>, so
        // assertIsArray on $schema and each $entry is redundant.
        $this->assertNotEmpty($schema);
        foreach ($schema as $name => $entry) {
            $this->assertEquals($name, $entry['name']);
            $this->assertArrayHasKey('parameters', $entry);
        }
    }

    public function testRegisterSkillRegistersClass(): void
    {
        // SkillBase is abstract; we reuse one of the existing skill
        // classes so the registration succeeds without instantiation.
        SignalWire::register_skill(\SignalWire\Skills\Builtin\Datetime::class);
        $this->assertContains('datetime', SkillRegistry::instance()->listSkills());
    }
}
