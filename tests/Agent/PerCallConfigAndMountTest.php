<?php

declare(strict_types=1);

namespace SignalWire\Tests\Agent;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;

/**
 * AgentBase::addPerCallConfig + AgentBase::mount. Ported from signalwire-python
 * tests/unit/core/test_agent_consolidation.py TestPerCallConfig / TestMount.
 */
class PerCallConfigAndMountTest extends TestCase
{
    private static function agent(string $route = '/myagent'): AgentBase
    {
        return new AgentBase(name: 'consolidation', route: $route, basicAuthUser: 'u', basicAuthPassword: 'p');
    }

    private static function runConfig(AgentBase $a): void
    {
        $configure = $a->getDynamicConfigCallback();
        self::assertNotNull($configure);
        $configure([], [], [], $a);
    }

    // ── per-call config ────────────────────────────────────────────────

    public function testAddedCallbacksAllRunInRegistrationOrder(): void
    {
        $seen = [];
        $a = self::agent();
        $a->addPerCallConfig(function () use (&$seen): void {
            $seen[] = 'first';
        });
        $a->addPerCallConfig(function () use (&$seen): void {
            $seen[] = 'second';
        });
        self::runConfig($a);
        $this->assertSame(['first', 'second'], $seen);
    }

    public function testSetStillReplaces(): void
    {
        $seen = [];
        $a = self::agent();
        $a->setDynamicConfigCallback(function () use (&$seen): void {
            $seen[] = 'one';
        });
        $a->setDynamicConfigCallback(function () use (&$seen): void {
            $seen[] = 'two';
        });
        self::runConfig($a);
        $this->assertSame(['two'], $seen);
    }

    public function testAddComposesWithAPreviouslySetCallback(): void
    {
        $seen = [];
        $a = self::agent();
        $a->setDynamicConfigCallback(function () use (&$seen): void {
            $seen[] = 'set';
        });
        $a->addPerCallConfig(function () use (&$seen): void {
            $seen[] = 'added';
        });
        self::runConfig($a);
        $this->assertSame(['set', 'added'], $seen);
    }

    public function testAFreshAgentHasNoCallback(): void
    {
        $this->assertNull(self::agent()->getDynamicConfigCallback());
    }

    public function testComposedCallbacksConfigureTheRenderedRequest(): void
    {
        $a = self::agent();
        $a->addPerCallConfig(fn (array $q, ?array $b, array $h, AgentBase $agent) => $agent->setParam('temperature', 0.3));
        $a->addPerCallConfig(fn (array $q, ?array $b, array $h, AgentBase $agent) => $agent->setParam('top_p', 0.9));
        [$status, , $body] = $a->handleRequest('POST', '/myagent', ['Authorization' => 'Basic ' . base64_encode('u:p')], '{}');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('"temperature":0.3', $body);
        $this->assertStringContainsString('"top_p":0.9', $body);
    }

    // ── mount ──────────────────────────────────────────────────────────

    /** @return callable(string, string, array<string,string>, ?string): array{int, array<string,string>, string} */
    private static function router(string $path): callable
    {
        return static function (string $method, string $p, array $headers, ?string $body) use ($path): array {
            if ($method === 'POST' && $p === $path) {
                return [200, ['Content-Type' => 'application/json'], '{"ok":true}'];
            }
            return [404, [], ''];
        };
    }

    public function testMountedRouteIsReachable(): void
    {
        $a = self::agent();
        $a->mount(self::router('/handoff'), prefix: '/myagent/chat');
        [$status, , $body] = $a->handleRequest('POST', '/myagent/chat/handoff', []);
        $this->assertSame(200, $status);
        $this->assertSame('{"ok":true}', $body);
    }

    public function testBareAgentRouteIsNotSwallowed(): void
    {
        // 401 means the SWML endpoint is alive and merely demanding auth.
        $a = self::agent();
        $a->mount(self::router('/x'), prefix: '/myagent/chat');
        [$status] = $a->handleRequest('POST', '/myagent', []);
        $this->assertSame(401, $status);
    }

    public function testSeveralMountsAllStayReachable(): void
    {
        $a = self::agent();
        $a->mount(self::router('/one'), prefix: '/myagent/a');
        $a->mount(self::router('/two'), prefix: '/myagent/b');
        $this->assertSame(200, $a->handleRequest('POST', '/myagent/a/one', [])[0]);
        $this->assertSame(200, $a->handleRequest('POST', '/myagent/b/two', [])[0]);
    }

    public function testHealthEndpointsSurvive(): void
    {
        $a = self::agent();
        $a->mount(self::router('/x'), prefix: '/myagent/chat');
        $this->assertSame(200, $a->handleRequest('GET', '/health', [])[0]);
    }

    public function testMountReturnsSelfForChaining(): void
    {
        $a = self::agent();
        $this->assertSame($a, $a->mount(self::router('/x'), prefix: '/myagent/c'));
    }

    public function testAServiceCanBeMounted(): void
    {
        $inner = self::agent('/');
        $a = self::agent();
        $a->mount($inner, prefix: '/myagent/inner');
        $this->assertSame(200, $a->handleRequest('GET', '/myagent/inner/health', [])[0]);
    }
}
