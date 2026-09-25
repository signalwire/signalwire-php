<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Proves examples/SkillsAuditHarness.php gets past construction.
 *
 * Why: the harness built its agent with a Python-style kwargs dict —
 *
 *     $agent = new AgentBase(['name' => 'skills-audit', 'route' => '/audit']);
 *
 * — against `AgentBase::__construct(string $name, string $route = '/', …)`.
 * That is an uncatchable TypeError on line 110, so the harness died before
 * loading a single skill, for EVERY skill, every time. porting-sdk's
 * audit_skills_dispatch.py drives this file, so that audit could never have
 * produced a real result for php.
 *
 * The harness is a standalone program that exits, so this test runs it as a
 * subprocess and asserts it reaches the skill's real HTTP call. The fixture URL
 * points at a closed port on purpose: a connection error proves the harness got
 * all the way to the network, which is strictly past the construction that used
 * to kill it.
 */
class ExampleSkillsHarnessTest extends TestCase
{
    public function testHarnessReachesTheSkillHttpCallInsteadOfDyingOnConstruction(): void
    {
        $repo = dirname(__DIR__);

        // A port nothing is listening on, so the skill's HTTP attempt fails
        // fast and deterministically without touching the network.
        $closed = 'http://127.0.0.1:9';

        $env = [
            'SKILL_NAME' => 'web_search',
            'SKILL_FIXTURE_URL' => $closed,
            'SKILL_HANDLER_ARGS' => '{"query":"anything"}',
            'GOOGLE_API_KEY' => 'test-key',
            'GOOGLE_CSE_ID' => 'test-cse',
            'WEB_SEARCH_BASE_URL' => $closed,
            'PATH' => getenv('PATH') !== false ? (string) getenv('PATH') : '/usr/bin:/bin',
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, $repo . '/examples/SkillsAuditHarness.php'],
            $descriptors,
            $pipes,
            $repo,
            $env,
        );
        self::assertIsResource($proc);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        // The failure this test exists for.
        self::assertStringNotContainsString(
            'must be of type string, array given',
            $stderr,
            'the harness died constructing AgentBase with a kwargs array',
        );
        self::assertStringNotContainsString('Uncaught TypeError', $stderr);

        // Got as far as the skill actually running: the harness prints the
        // handler's FunctionResult as JSON.
        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded, "harness produced no JSON result; stderr: {$stderr}");
        self::assertArrayHasKey('response', $decoded);
    }
}
