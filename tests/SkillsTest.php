<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Skills\SkillRegistry;
use SignalWire\Skills\SkillManager;
use SignalWire\Skills\SkillName;
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
        return new AgentBase(
            name: $opts['name'] ?? 'test-agent',
            route: $opts['route'] ?? '/',
            basicAuthUser: $opts['basic_auth_user'] ?? 'testuser',
            basicAuthPassword: $opts['basic_auth_password'] ?? 'testpass'
        );
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

    public function testAddSkillAcceptsSkillNameEnumOrString(): void
    {
        // The backed enum's value is the canonical wire string.
        $this->assertSame('datetime', SkillName::Datetime->value);

        // addSkill() via the typed enum loads the identical skill as the bare
        // string; hasSkill()/removeSkill() accept the enum too.
        $agent = $this->makeAgent();
        $agent->addSkill(SkillName::Datetime, ['skip_prompt' => true]);
        $this->assertTrue($agent->hasSkill('datetime'));           // string lookup
        $this->assertTrue($agent->hasSkill(SkillName::Datetime));  // enum lookup — same skill

        $agent->removeSkill(SkillName::Datetime);
        $this->assertFalse($agent->hasSkill('datetime'));

        // Parity: the bare string still works identically (Python uses str).
        $stringAgent = $this->makeAgent();
        $stringAgent->addSkill('datetime', ['skip_prompt' => true]);
        $this->assertTrue($stringAgent->hasSkill(SkillName::Datetime));
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
    //  WebSearch response_prefix / response_postfix wrapping (3)
    //
    //  Mirrors Python signalwire/skills/web_search/skill.py commit 8aad242.
    //  Wraps the successful search response (only) with the configured
    //  prefix / postfix; error and no-results paths stay unwrapped.
    //
    //  Tests use a real PHP `-S` fixture server bound to an ephemeral
    //  port and pointed at via WEB_SEARCH_BASE_URL — no transport
    //  mocking, no Guzzle MockHandler. The fixture returns a fixed
    //  Google CSE-shaped JSON body.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Boot a php -S fixture that always returns a fixed JSON body for
     * any request. Returns [proc, ephemeral_port, tmp_script_path].
     *
     * @return array{0: resource, 1: int, 2: string}
     */
    private function bootWebSearchFixture(string $jsonBody): array
    {
        $escaped = var_export($jsonBody, true);
        $script = <<<PHP
        <?php
        header('Content-Type: application/json');
        echo {$escaped};
        PHP;
        $tmp = \tempnam(\sys_get_temp_dir(), 'sw_web_search_fx_') . '.php';
        \file_put_contents($tmp, $script);

        // Bind an ephemeral port.
        $sock = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        \socket_bind($sock, '127.0.0.1', 0);
        \socket_getsockname($sock, $addr, $port);
        \socket_close($sock);

        $cmd = \escapeshellcmd(PHP_BINARY)
            . ' -S 127.0.0.1:' . (int) $port
            . ' ' . \escapeshellarg($tmp);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = \proc_open($cmd, $descriptors, $pipes);
        $this->assertIsResource($proc, 'Failed to spawn php -S fixture');
        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        // Wait for bind.
        $ok = false;
        $deadline = \microtime(true) + 5.0;
        while (\microtime(true) < $deadline) {
            $err = 0;
            $errStr = '';
            $conn = @\fsockopen('127.0.0.1', $port, $err, $errStr, 0.2);
            if ($conn !== false) {
                \fclose($conn);
                $ok = true;
                break;
            }
            \usleep(50_000);
        }
        $this->assertTrue($ok, "php -S fixture did not bind to 127.0.0.1:{$port}");

        return [$proc, (int) $port, $tmp];
    }

    private function tearDownWebSearchFixture(mixed $proc, string $tmp): void
    {
        // Defensively cast: proc_terminate signature only accepts resource.
        if (\is_resource($proc)) {
            @\proc_terminate($proc, SIGTERM);
            @\proc_close($proc);
        }
        @\unlink($tmp);
    }

    private function googleCseJsonBody(): string
    {
        return (string) \json_encode([
            'items' => [
                [
                    'title'   => 'Result One',
                    'link'    => 'https://example.com/one',
                    'snippet' => 'First snippet content.',
                ],
            ],
        ]);
    }

    /**
     * Construct + register a WebSearch skill directly (bypassing
     * SkillManager so we don't drag in unrelated AgentBase merge
     * methods). Returns the agent so the caller can dispatch.
     *
     * @param array<string,mixed> $params
     */
    private function registerWebSearchSkill(array $params): AgentBase
    {
        $agent = $this->makeAgent();
        $skill = new \SignalWire\Skills\Builtin\WebSearch($agent, $params);
        $this->assertTrue($skill->setup(), 'WebSearch setup() should succeed');
        $skill->registerTools();
        return $agent;
    }

    public function testWebSearchResponsePrefixWrapsSuccessfulResponse(): void
    {
        [$proc, $port, $tmp] = $this->bootWebSearchFixture($this->googleCseJsonBody());
        try {
            \putenv("WEB_SEARCH_BASE_URL=http://127.0.0.1:{$port}");

            // snippets_only keeps the wrapping test focused on prefix /
            // postfix behavior (and fast/deterministic) — the default path
            // now scrapes each result page, which is exercised separately
            // below. The snippet formatter is still a "successful, non-empty
            // result", so the wrapping rules apply identically.
            $agent = $this->registerWebSearchSkill([
                'api_key'           => 'fake-key',
                'search_engine_id'  => 'fake-cx',
                'response_prefix'   => 'PREFIX_HEADER:',
                'snippets_only'     => true,
            ]);

            $result = $agent->onFunctionCall(
                'web_search',
                ['query' => 'hello'],
                [],
            );

            $this->assertNotNull($result);
            $body = $result->toArray()['response'];
            $this->assertStringStartsWith("PREFIX_HEADER:\n\n", $body);
            $this->assertStringContainsString("Snippet-only results for 'hello'", $body);
            $this->assertStringContainsString('Title: Result One', $body);
        } finally {
            \putenv('WEB_SEARCH_BASE_URL');
            $this->tearDownWebSearchFixture($proc, $tmp);
        }
    }

    public function testWebSearchResponsePostfixWrapsSuccessfulResponse(): void
    {
        [$proc, $port, $tmp] = $this->bootWebSearchFixture($this->googleCseJsonBody());
        try {
            \putenv("WEB_SEARCH_BASE_URL=http://127.0.0.1:{$port}");

            $agent = $this->registerWebSearchSkill([
                'api_key'           => 'fake-key',
                'search_engine_id'  => 'fake-cx',
                'response_prefix'   => 'PREFIX_HEADER:',
                'response_postfix'  => 'POSTFIX_FOOTER.',
                'snippets_only'     => true,
            ]);

            $result = $agent->onFunctionCall(
                'web_search',
                ['query' => 'hello'],
                [],
            );

            $this->assertNotNull($result);
            $body = $result->toArray()['response'];
            $this->assertStringStartsWith("PREFIX_HEADER:\n\n", $body);
            $this->assertStringEndsWith("\n\nPOSTFIX_FOOTER.", $body);
            $this->assertStringContainsString('Title: Result One', $body);
        } finally {
            \putenv('WEB_SEARCH_BASE_URL');
            $this->tearDownWebSearchFixture($proc, $tmp);
        }
    }

    public function testWebSearchEmptyPrefixPostfixLeavesResponseUnwrapped(): void
    {
        [$proc, $port, $tmp] = $this->bootWebSearchFixture($this->googleCseJsonBody());
        try {
            \putenv("WEB_SEARCH_BASE_URL=http://127.0.0.1:{$port}");

            $agent = $this->registerWebSearchSkill([
                'api_key'           => 'fake-key',
                'search_engine_id'  => 'fake-cx',
                'snippets_only'     => true,
            ]);

            $result = $agent->onFunctionCall(
                'web_search',
                ['query' => 'hello'],
                [],
            );

            $this->assertNotNull($result);
            $body = $result->toArray()['response'];
            // Bare response — no prefix / postfix markers anywhere.
            $this->assertStringStartsWith("Snippet-only results for 'hello'", $body);
            $this->assertStringContainsString('Title: Result One', $body);
            // The body ends with a trailing newline (the per-result block
            // pushes an empty line after each result). No postfix appended.
            $this->assertStringEndsWith("\n", $body);
        } finally {
            \putenv('WEB_SEARCH_BASE_URL');
            $this->tearDownWebSearchFixture($proc, $tmp);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  WebSearch latency control: per_page_timeout / overall_deadline /
    //  parallel_scrape / snippets_only (7)
    //
    //  Ports Python signalwire/skills/web_search/skill.py commits
    //  51101da (params + behavior) + 295745b (schema). The PHP skill runs
    //  scrapes SEQUENTIALLY (single-threaded; parallel_scrape accepted for
    //  parity only), but enforces the overall_deadline + per_page_timeout
    //  budget — the contracted guarantee that a slow site can't blow past
    //  the kernel webhook timeout.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Boot a php -S fixture that ROUTES by request path and logs every
     * path it sees to a sidecar file:
     *   - path contains "customsearch" -> the Google CSE JSON body (fast).
     *   - any other path (a page scrape) -> $pageHtml, optionally after
     *     sleeping $pageSleepSeconds (to exercise per_page_timeout).
     *
     * Returns [proc, ephemeral_port, tmp_script_path, request_log_path].
     *
     * @return array{0: resource, 1: int, 2: string, 3: string}
     */
    private function bootWebSearchRoutingFixture(
        string $cseJsonBody,
        string $pageHtml,
        float $pageSleepSeconds = 0.0,
    ): array {
        $logPath = \tempnam(\sys_get_temp_dir(), 'sw_web_search_log_');
        $cseExport = \var_export($cseJsonBody, true);
        $htmlExport = \var_export($pageHtml, true);
        $logExport = \var_export($logPath, true);
        $sleepMicros = (int) \round($pageSleepSeconds * 1_000_000);

        // NB: this heredoc is double-quoted-style, so backslashes that
        // form a recognised escape (\f, \u, \n, ...) are interpolated. The
        // generated script runs at global scope, so call built-ins WITHOUT
        // a leading backslash to avoid e.g. "\file_put_contents" -> form
        // feed + "ile_put_contents". Only $ and {} are escaped below.
        $script = <<<PHP
        <?php
        \$path = \$_SERVER['REQUEST_URI'] ?? '/';
        @file_put_contents({$logExport}, \$path . "\\n", FILE_APPEND);
        if (strpos(\$path, 'customsearch') !== false) {
            header('Content-Type: application/json');
            echo {$cseExport};
            exit;
        }
        // Page scrape: optionally stall to trip per_page_timeout.
        if ({$sleepMicros} > 0) {
            usleep({$sleepMicros});
        }
        header('Content-Type: text/html');
        echo {$htmlExport};
        PHP;
        $tmp = \tempnam(\sys_get_temp_dir(), 'sw_web_search_rt_') . '.php';
        \file_put_contents($tmp, $script);

        $sock = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        \socket_bind($sock, '127.0.0.1', 0);
        \socket_getsockname($sock, $addr, $port);
        \socket_close($sock);

        $cmd = \escapeshellcmd(PHP_BINARY)
            . ' -S 127.0.0.1:' . (int) $port
            . ' ' . \escapeshellarg($tmp);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = \proc_open($cmd, $descriptors, $pipes);
        $this->assertIsResource($proc, 'Failed to spawn php -S routing fixture');
        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $ok = false;
        $deadline = \microtime(true) + 5.0;
        while (\microtime(true) < $deadline) {
            $err = 0;
            $errStr = '';
            $conn = @\fsockopen('127.0.0.1', $port, $err, $errStr, 0.2);
            if ($conn !== false) {
                \fclose($conn);
                $ok = true;
                break;
            }
            \usleep(50_000);
        }
        $this->assertTrue($ok, "php -S routing fixture did not bind to 127.0.0.1:{$port}");

        return [$proc, (int) $port, $tmp, $logPath];
    }

    /** @return list<string> Recorded request paths, in order. */
    private function readRequestLog(string $logPath): array
    {
        $raw = \is_file($logPath) ? (string) \file_get_contents($logPath) : '';
        $lines = \array_values(\array_filter(
            \explode("\n", $raw),
            static fn(string $l): bool => $l !== '',
        ));
        return $lines;
    }

    /**
     * A multi-result CSE body so deadline / parallel tests have several
     * candidate pages to (not) scrape.
     */
    private function googleCseMultiJsonBody(): string
    {
        return (string) \json_encode([
            'items' => [
                ['title' => 'Alpha', 'link' => 'https://example.com/a', 'snippet' => 'Alpha snippet text.'],
                ['title' => 'Beta',  'link' => 'https://example.com/b', 'snippet' => 'Beta snippet text.'],
                ['title' => 'Gamma', 'link' => 'https://example.com/c', 'snippet' => 'Gamma snippet text.'],
            ],
        ]);
    }

    public function testWebSearchSnippetsOnlySkipsPageFetch(): void
    {
        // snippets_only must short-circuit BEFORE any page scrape. Only the
        // CSE call should hit the fixture; no scrape request at all.
        [$proc, $port, $tmp, $log] = $this->bootWebSearchRoutingFixture(
            $this->googleCseMultiJsonBody(),
            '<html><body>SHOULD NOT BE FETCHED</body></html>',
        );
        try {
            \putenv("WEB_SEARCH_BASE_URL=http://127.0.0.1:{$port}");
            $agent = $this->registerWebSearchSkill([
                'api_key'          => 'fake-key',
                'search_engine_id' => 'fake-cx',
                'snippets_only'    => true,
            ]);

            $result = $agent->onFunctionCall('web_search', ['query' => 'alpha'], []);
            $this->assertNotNull($result);
            $body = $result->toArray()['response'];

            // Snippet formatter output, carrying the CSE titles + snippets.
            $this->assertStringContainsString("Snippet-only results for 'alpha'", $body);
            $this->assertStringContainsString('Title: Alpha', $body);
            $this->assertStringContainsString('Snippet: Alpha snippet text.', $body);
            $this->assertStringNotContainsString('SHOULD NOT BE FETCHED', $body);

            // Exactly one HTTP request, and it was the CSE call.
            $paths = $this->readRequestLog($log);
            $this->assertCount(1, $paths, 'snippets_only must issue ONLY the CSE call');
            $this->assertStringContainsString('customsearch', $paths[0]);
        } finally {
            \putenv('WEB_SEARCH_BASE_URL');
            $this->tearDownWebSearchFixture($proc, $tmp);
            @\unlink($log);
        }
    }

    public function testWebSearchOverallDeadlineTruncatesToSnippetFallback(): void
    {
        // Deterministic deadline enforcement: overall_deadline = 0.0 means
        // the budget is already spent by the time the scrape loop starts, so
        // EVERY page is abandoned and the handler falls back to formatting
        // the CSE snippets — a non-empty response, NOT the empty no-results
        // message. No sleeping / real slowness required.
        [$proc, $port, $tmp, $log] = $this->bootWebSearchRoutingFixture(
            $this->googleCseMultiJsonBody(),
            '<html><body>Page body that would otherwise score well for alpha beta gamma.</body></html>',
        );
        try {
            \putenv("WEB_SEARCH_BASE_URL=http://127.0.0.1:{$port}");
            $agent = $this->registerWebSearchSkill([
                'api_key'          => 'fake-key',
                'search_engine_id' => 'fake-cx',
                'overall_deadline' => 0.0,
                'no_results_message' => 'NO_RESULTS_SENTINEL for {query}',
            ]);

            $result = $agent->onFunctionCall('web_search', ['query' => 'alpha'], []);
            $this->assertNotNull($result);
            $body = $result->toArray()['response'];

            // Snippet fallback fired: non-empty, carries titles + snippets.
            $this->assertStringContainsString("Snippet-only results for 'alpha'", $body);
            $this->assertStringContainsString('Title: Alpha', $body);
            $this->assertStringContainsString('Snippet: Alpha snippet text.', $body);
            // It is NOT the empty no-results message and NOT a full scrape.
            $this->assertStringNotContainsString('NO_RESULTS_SENTINEL', $body);
            $this->assertStringNotContainsString('Web search results for', $body);

            // Deadline abandoned all scrapes: only the CSE call was issued,
            // no page was fetched.
            $paths = $this->readRequestLog($log);
            $this->assertCount(1, $paths, 'overall_deadline=0 must abandon every scrape');
            $this->assertStringContainsString('customsearch', $paths[0]);
        } finally {
            \putenv('WEB_SEARCH_BASE_URL');
            $this->tearDownWebSearchFixture($proc, $tmp);
            @\unlink($log);
        }
    }

    public function testWebSearchPerPageTimeoutAbandonsSlowPage(): void
    {
        // The page scrape sleeps ~1.5s; per_page_timeout floors to 1s of
        // cURL CURLOPT_TIMEOUT, so the fetch is cut off and returns null.
        // With no scraped result the handler falls back to snippets. The
        // assertion that matters: the call RETURNS (doesn't hang) with the
        // non-empty snippet fallback. Also bound the whole call's wall time
        // well under any kernel-style limit.
        [$proc, $port, $tmp, $log] = $this->bootWebSearchRoutingFixture(
            $this->googleCseMultiJsonBody(),
            '<html><body>Slow page body for alpha beta gamma that arrives too late.</body></html>',
            pageSleepSeconds: 1.5,
        );
        try {
            \putenv("WEB_SEARCH_BASE_URL=http://127.0.0.1:{$port}");
            $agent = $this->registerWebSearchSkill([
                'api_key'          => 'fake-key',
                'search_engine_id' => 'fake-cx',
                'per_page_timeout' => 0.2,   // floors to 1s cURL timeout
                'overall_deadline' => 8.0,
            ]);

            $started = \microtime(true);
            $result = $agent->onFunctionCall('web_search', ['query' => 'alpha'], []);
            $elapsed = \microtime(true) - $started;

            $this->assertNotNull($result);
            $body = $result->toArray()['response'];

            // Every page timed out -> snippet fallback (non-empty).
            $this->assertStringContainsString("Snippet-only results for 'alpha'", $body);
            $this->assertStringContainsString('Title: Alpha', $body);

            // At least one page WAS attempted (per_page_timeout governs the
            // fetch, the page is not skipped outright like the deadline case).
            $paths = $this->readRequestLog($log);
            $this->assertGreaterThanOrEqual(2, count($paths), 'a page fetch should have been attempted');
            $this->assertStringContainsString('customsearch', $paths[0]);

            // Whole call stayed bounded well under a kernel-style timeout
            // even though three 1.5s-sleeping pages were in the candidate set
            // (the per-page cURL timeout caps each at ~1s).
            $this->assertLessThan(7.0, $elapsed, 'per_page_timeout must bound total latency');
        } finally {
            \putenv('WEB_SEARCH_BASE_URL');
            $this->tearDownWebSearchFixture($proc, $tmp);
            @\unlink($log);
        }
    }

    public function testWebSearchScrapesAndFormatsQualityPage(): void
    {
        // Happy path: the page scrape succeeds and the extracted text is
        // on-topic, so it clears the quality threshold and the handler
        // returns the FULL "Web search results" block (proving the scrape
        // phase is real, not dead code). The CSE body's single result links
        // to a page rich in the query terms.
        $cse = (string) \json_encode([
            'items' => [[
                'title'   => 'Quality Doc',
                'link'    => 'https://example.com/quality',
                'snippet' => 'short snippet',
            ]],
        ]);
        // Long, on-topic HTML so length + relevance both score high.
        $topic = \str_repeat('alpha beta gamma delta epsilon content. ', 80);
        $html = "<html><body><p>{$topic}</p></body></html>";

        [$proc, $port, $tmp, $log] = $this->bootWebSearchRoutingFixture($cse, $html);
        try {
            \putenv("WEB_SEARCH_BASE_URL=http://127.0.0.1:{$port}");
            $agent = $this->registerWebSearchSkill([
                'api_key'          => 'fake-key',
                'search_engine_id' => 'fake-cx',
                'overall_deadline' => 8.0,
                'per_page_timeout' => 3.0,
            ]);

            $result = $agent->onFunctionCall('web_search', ['query' => 'alpha beta gamma'], []);
            $this->assertNotNull($result);
            $body = $result->toArray()['response'];

            // Full scraped-result format, with extracted page content.
            $this->assertStringContainsString('Web search results for "alpha beta gamma"', $body);
            $this->assertStringContainsString('Title: Quality Doc', $body);
            $this->assertStringContainsString('Content: ', $body);
            $this->assertStringContainsString('alpha beta gamma delta epsilon content', $body);
            // It scraped a page, so >1 request hit the fixture.
            $paths = $this->readRequestLog($log);
            $this->assertGreaterThanOrEqual(2, count($paths));
        } finally {
            \putenv('WEB_SEARCH_BASE_URL');
            $this->tearDownWebSearchFixture($proc, $tmp);
            @\unlink($log);
        }
    }

    public function testWebSearchParameterSchemaAdvertisesAllLatencyParams(): void
    {
        $agent = $this->makeAgent();
        $skill = new \SignalWire\Skills\Builtin\WebSearch($agent, [
            'api_key'          => 'fake-key',
            'search_engine_id' => 'fake-cx',
        ]);
        $props = $skill->getParameterSchema()['properties'];

        // Every latency / response param the skill reads must be advertised
        // (guards the recurring "read a param but forgot the schema entry"
        // drift; mirrors Python test_every_setup_param_is_advertised).
        foreach (
            ['response_prefix', 'response_postfix', 'per_page_timeout',
             'overall_deadline', 'parallel_scrape', 'snippets_only']
            as $key
        ) {
            $this->assertArrayHasKey($key, $props, "schema omits {$key}");
        }

        // Defaults match the Python reference exactly.
        $this->assertSame(2.0, $props['per_page_timeout']['default']);
        $this->assertSame(10.0, $props['overall_deadline']['default']);
        $this->assertSame('number', $props['per_page_timeout']['type']);
        $this->assertSame('number', $props['overall_deadline']['type']);
        $this->assertTrue($props['parallel_scrape']['default']);
        $this->assertFalse($props['snippets_only']['default']);
        $this->assertSame('boolean', $props['parallel_scrape']['type']);
        $this->assertSame('boolean', $props['snippets_only']['type']);
        $this->assertSame('', $props['response_prefix']['default']);
        $this->assertSame('', $props['response_postfix']['default']);
    }

    public function testWebSearchDefaultLatencyParamsAreApplied(): void
    {
        // With no latency params supplied, the skill uses the Python
        // defaults: snippets_only=false (so it scrapes) and a generous
        // 10s deadline (so a single fast page is not abandoned). The
        // single quality page is therefore scraped and fully formatted.
        $cse = (string) \json_encode([
            'items' => [[
                'title'   => 'Default Doc',
                'link'    => 'https://example.com/default',
                'snippet' => 'snippet',
            ]],
        ]);
        $topic = \str_repeat('weather forecast today details. ', 80);
        $html = "<html><body>{$topic}</body></html>";

        [$proc, $port, $tmp, $log] = $this->bootWebSearchRoutingFixture($cse, $html);
        try {
            \putenv("WEB_SEARCH_BASE_URL=http://127.0.0.1:{$port}");
            $agent = $this->registerWebSearchSkill([
                'api_key'          => 'fake-key',
                'search_engine_id' => 'fake-cx',
            ]);

            $result = $agent->onFunctionCall('web_search', ['query' => 'weather forecast'], []);
            $this->assertNotNull($result);
            $body = $result->toArray()['response'];

            // Default path scraped (not snippets_only) and the page cleared
            // the default quality threshold.
            $this->assertStringContainsString('Web search results for "weather forecast"', $body);
            $this->assertStringContainsString('weather forecast today details', $body);
            $paths = $this->readRequestLog($log);
            $this->assertGreaterThanOrEqual(2, count($paths), 'default path must scrape');
        } finally {
            \putenv('WEB_SEARCH_BASE_URL');
            $this->tearDownWebSearchFixture($proc, $tmp);
            @\unlink($log);
        }
    }

    public function testWebSearchParallelScrapeFlagIsAcceptedRunsSequential(): void
    {
        // parallel_scrape is accepted for API/schema parity. PHP runs
        // sequentially; the flag must not change correctness — the deadline
        // is still enforced. Set parallel_scrape=true AND overall_deadline=0
        // and confirm the same snippet fallback as the sequential deadline
        // case (i.e. the flag did not bypass the deadline).
        [$proc, $port, $tmp, $log] = $this->bootWebSearchRoutingFixture(
            $this->googleCseMultiJsonBody(),
            '<html><body>body alpha beta gamma</body></html>',
        );
        try {
            \putenv("WEB_SEARCH_BASE_URL=http://127.0.0.1:{$port}");
            $agent = $this->registerWebSearchSkill([
                'api_key'          => 'fake-key',
                'search_engine_id' => 'fake-cx',
                'parallel_scrape'  => true,
                'overall_deadline' => 0.0,
            ]);

            $result = $agent->onFunctionCall('web_search', ['query' => 'alpha'], []);
            $this->assertNotNull($result);
            $body = $result->toArray()['response'];

            $this->assertStringContainsString("Snippet-only results for 'alpha'", $body);
            $paths = $this->readRequestLog($log);
            // Deadline honored regardless of the parallel flag: no page fetch.
            $this->assertCount(1, $paths);
            $this->assertStringContainsString('customsearch', $paths[0]);
        } finally {
            \putenv('WEB_SEARCH_BASE_URL');
            $this->tearDownWebSearchFixture($proc, $tmp);
            @\unlink($log);
        }
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
