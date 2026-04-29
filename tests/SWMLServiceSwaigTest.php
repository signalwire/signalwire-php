<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\SWML\Schema;
use SignalWire\SWML\Service;
use SignalWire\Logging\Logger;

/**
 * Tests proving SWMLService can host SWAIG functions and serve a non-agent
 * SWML doc (e.g. ai_sidecar) without subclassing AgentBase. This is the
 * contract that lets sidecar / non-agent verbs reuse the SWAIG dispatch
 * surface that previously lived only on AgentBase.
 */
class SWMLServiceSwaigTest extends TestCase
{
    protected function setUp(): void
    {
        Schema::reset();
        Logger::reset();
        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
    }

    protected function tearDown(): void
    {
        Schema::reset();
        Logger::reset();
    }

    private function svc(array $opts = []): Service
    {
        return new Service(array_merge([
            'name' => 'svc',
            'basic_auth_user' => 'u',
            'basic_auth_password' => 'p',
        ], $opts));
    }

    private function auth(): array
    {
        return ['Authorization' => 'Basic ' . base64_encode('u:p')];
    }

    // ------------------------------------------------------------------
    // SWMLService gains SWAIG-hosting capability
    // ------------------------------------------------------------------

    public function testServiceHasSwaigMethods(): void
    {
        $svc = $this->svc();
        $this->assertTrue(method_exists($svc, 'defineTool'));
        $this->assertTrue(method_exists($svc, 'registerSwaigFunction'));
        $this->assertTrue(method_exists($svc, 'defineTools'));
        $this->assertTrue(method_exists($svc, 'onFunctionCall'));
    }

    public function testDefineToolRegistersFunction(): void
    {
        $svc = $this->svc();
        $svc->defineTool('lookup', 'Look it up', [], fn() => new FunctionResult('ok'));
        $result = $svc->onFunctionCall('lookup', [], []);
        $this->assertNotNull($result);
        $this->assertSame('ok', $result->toArray()['response']);
    }

    public function testOnFunctionCallReturnsNullForUnknown(): void
    {
        $svc = $this->svc();
        $this->assertNull($svc->onFunctionCall('no_such_fn', [], []));
    }

    // ------------------------------------------------------------------
    // /swaig endpoint behavior on plain Service
    // ------------------------------------------------------------------

    public function testSwaigGetReturnsSwml(): void
    {
        $svc = $this->svc();
        $svc->hangup();
        [$status, , $body] = $svc->handleRequest('GET', '/swaig', $this->auth());
        $this->assertSame(200, $status);
        $decoded = json_decode($body, true);
        $this->assertArrayHasKey('sections', $decoded);
        $this->assertCount(1, $decoded['sections']['main']);
    }

    public function testSwaigPostDispatchesRegisteredHandler(): void
    {
        $svc = $this->svc();
        $captured = [];
        $svc->defineTool(
            'lookup_competitor',
            'Look up competitor pricing.',
            ['competitor' => ['type' => 'string']],
            function (array $args, array $raw) use (&$captured): FunctionResult {
                $captured['args'] = $args;
                return new FunctionResult("{$args['competitor']} is \$99/seat; we're \$79.");
            },
        );
        $payload = json_encode([
            'function' => 'lookup_competitor',
            'argument' => ['parsed' => [['competitor' => 'ACME']]],
            'call_id' => 'c-1',
        ]);
        [$status, , $body] = $svc->handleRequest('POST', '/swaig', $this->auth(), $payload);
        $this->assertSame(200, $status);
        $this->assertSame(['competitor' => 'ACME'], $captured['args']);
        $this->assertStringContainsString('ACME', $body);
        $this->assertStringContainsString('$79', $body);
    }

    public function testSwaigPostMissingFunctionReturns400(): void
    {
        $svc = $this->svc();
        [$status,,] = $svc->handleRequest('POST', '/swaig', $this->auth(), '{}');
        $this->assertSame(400, $status);
    }

    public function testSwaigPostInvalidFunctionNameReturns400(): void
    {
        $svc = $this->svc();
        [$status,,] = $svc->handleRequest(
            'POST',
            '/swaig',
            $this->auth(),
            json_encode(['function' => '../etc/passwd']),
        );
        $this->assertSame(400, $status);
    }

    public function testSwaigPostUnknownFunctionReturns404(): void
    {
        $svc = $this->svc();
        [$status,,] = $svc->handleRequest(
            'POST',
            '/swaig',
            $this->auth(),
            json_encode(['function' => 'nope', 'argument' => ['parsed' => [[]]]]),
        );
        $this->assertSame(404, $status);
    }

    public function testSwaigUnauthorizedReturns401(): void
    {
        $svc = $this->svc();
        [$status,,] = $svc->handleRequest('POST', '/swaig', [], '{}');
        $this->assertSame(401, $status);
    }

    // ------------------------------------------------------------------
    // Sidecar usage pattern: non-agent SWML + tool + event sink
    // ------------------------------------------------------------------

    public function testSidecarPatternEmitsVerbRegistersToolAndHandlesEvents(): void
    {
        $svc = $this->svc();

        // 1. Build the SWML — an `answer` then an `ai_sidecar` verb config.
        $svc->answer();
        // ai_sidecar isn't in the live schema yet; bypass via the document.
        // Once schema lands, callers will use the auto-vivified verb method.
        $svc->getDocument()->addVerbToSection('main', 'ai_sidecar', [
            'prompt' => 'real-time copilot',
            'lang' => 'en-US',
            'direction' => ['remote-caller', 'local-caller'],
        ]);
        $rendered = json_decode($svc->render(), true);
        $verbs = array_map(fn($v) => array_key_first($v), $rendered['sections']['main']);
        $this->assertContains('answer', $verbs);
        $this->assertContains('ai_sidecar', $verbs);

        // 2. Register a SWAIG tool the sidecar's LLM can call.
        $svc->defineTool(
            'lookup_competitor',
            'Look up competitor pricing.',
            ['competitor' => ['type' => 'string']],
            fn(array $args) => new FunctionResult("Pricing for {$args['competitor']}: \$99"),
        );

        // 3. Register an event-sink endpoint via routing callback.
        $eventsSeen = [];
        $svc->registerRoutingCallback('/events', function (?array $body) use (&$eventsSeen): array {
            $eventsSeen[] = $body['type'] ?? 'unknown';
            return ['ok' => true];
        });

        // Verify the SWAIG dispatch works end-to-end.
        [$status, , $body] = $svc->handleRequest('POST', '/swaig', $this->auth(), json_encode([
            'function' => 'lookup_competitor',
            'argument' => ['parsed' => [['competitor' => 'ACME']]],
        ]));
        $this->assertSame(200, $status);
        $this->assertStringContainsString('ACME', $body);

        // Verify the event sink works end-to-end.
        [$status,,] = $svc->handleRequest('POST', '/events', $this->auth(), json_encode([
            'type' => 'insight',
            'tick_id' => 7,
        ]));
        $this->assertSame(200, $status);
        $this->assertSame(['insight'], $eventsSeen);
    }
}
