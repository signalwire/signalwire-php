<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\Contexts\Context;
use SignalWire\DataMap\DataMap;
use SignalWire\Server\AgentServer;
use SignalWire\SignalWire;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\Tests\Support\Shape;

/**
 * Tests for the non-skills "tail" of item I: AgentBase get_name / get_full_url /
 * auto_map_sip_usernames, the SignalWire facade free-function hosts, the
 * Context / DataMap module-level factory hosts, and AgentServer's global
 * routing-callback registration.
 */
class ItemINonSkillsTailTest extends TestCase
{
    private function agent(string $name = 'agent', string $route = '/'): AgentBase
    {
        return new AgentBase(
            name: $name,
            route: $route,
            host: 'example.com',
            port: 8080,
            basicAuthUser: 'user',
            basicAuthPassword: 'pass',
        );
    }

    // ── AgentBase.get_name / get_full_url / auto_map_sip_usernames ────────

    #[Test]
    public function agentGetNameReturnsName(): void
    {
        $this->assertSame('support', $this->agent('support')->getName());
    }

    #[Test]
    public function agentGetFullUrlBuildsUrl(): void
    {
        $agent = $this->agent('a', '/svc');
        $url = $agent->getFullUrl();
        $this->assertStringContainsString('example.com:8080', $url);
        $this->assertStringContainsString('/svc', $url);
    }

    #[Test]
    public function agentGetFullUrlWithAuthEmbedsCredentials(): void
    {
        $agent = $this->agent('a', '/svc');
        $url = $agent->getFullUrl(true);
        $this->assertStringContainsString('user:pass@', $url);
    }

    #[Test]
    public function autoMapSipUsernamesRegistersDerivedNames(): void
    {
        // A long, punctuated name yields a cleaned username (and a vowel-stripped
        // variant); returns $this for chaining.
        $agent = $this->agent('Support Desk', '/help');
        $ret = $agent->autoMapSipUsernames();
        $this->assertSame($agent, $ret);
        // registerSipUsername stores the derived name under the 'sip_username'
        // AI param; the final registration wins. Assert it is a cleaned token.
        $params = Shape::sub($agent->buildAiVerb(), 'params');
        $this->assertArrayHasKey('sip_username', $params);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', (string) $params['sip_username']);
    }

    // ── SignalWire facade free-function hosts ────────────────────────────

    #[Test]
    public function facadeListSkillsReturnsMetadataList(): void
    {
        $skills = SignalWire::list_skills();
        $this->assertNotEmpty($skills);
        // Each entry is an associative array carrying at least a name.
        foreach ($skills as $entry) {
            $this->assertArrayHasKey('name', $entry);
        }
        $names = array_column($skills, 'name');
        $this->assertContains('math', $names);
    }

    #[Test]
    public function facadeListSkillsWithParamsIsKeyedByName(): void
    {
        $schema = SignalWire::list_skills_with_params();
        $this->assertArrayHasKey('math', $schema);
        $this->assertSame('math', $schema['math']['name']);
    }

    // ── Context.create_simple_context ────────────────────────────────────

    #[Test]
    public function createSimpleContextReturnsNamedContext(): void
    {
        $ctx = Context::createSimpleContext();
        $this->assertSame('default', $ctx->getName());

        $named = Context::createSimpleContext('onboarding');
        $this->assertSame('onboarding', $named->getName());
    }

    // ── DataMap.create_simple_api_tool / create_expression_tool ──────────

    #[Test]
    public function createSimpleApiToolReturnsConfiguredDataMap(): void
    {
        $dm = DataMap::createSimpleApiTool(
            'weather',
            'https://api.example.com/weather',
            'It is ${response.temp}',
            ['city' => ['type' => 'string', 'required' => true]],
        );
        $swaig = $dm->toSwaigFunction();
        $this->assertSame('weather', $swaig['function']);
        $this->assertSame(['city'], Shape::at($swaig, 'argument', 'required'));
        $this->assertSame('https://api.example.com/weather', Shape::at($swaig, 'data_map', 'webhooks', 0, 'url'));
    }

    #[Test]
    public function createExpressionToolReturnsConfiguredDataMap(): void
    {
        $dm = DataMap::createExpressionTool(
            'router',
            ['${args.dept}' => ['/sales/', new FunctionResult('to sales')]],
        );
        $swaig = $dm->toSwaigFunction();
        $this->assertSame('router', $swaig['function']);
        $this->assertCount(1, Shape::sub($swaig, 'data_map', 'expressions'));
        $this->assertSame('/sales/', Shape::at($swaig, 'data_map', 'expressions', 0, 'pattern'));
    }

    // ── AgentServer.register_global_routing_callback ─────────────────────

    #[Test]
    public function registerGlobalRoutingCallbackInstallsOnAllAgents(): void
    {
        $server = new AgentServer(host: '127.0.0.1', port: 3000);
        $a = $this->agent('a', '/a');
        $b = $this->agent('b', '/b');
        $server->register($a, '/a');
        $server->register($b, '/b');

        $called = 0;
        $callback = function (array $requestData, array $headers) use (&$called): ?string {
            $called++;
            return null;
        };

        // Should not throw; installs the callback on every registered agent.
        $server->registerGlobalRoutingCallback($callback, 'route');

        // Reflect the routing tables to confirm the callback landed on both.
        foreach ([$a, $b] as $agent) {
            $ref = new \ReflectionClass($agent);
            // routingCallbacks is declared on the Service base.
            $prop = $ref->getProperty('routingCallbacks');
            $prop->setAccessible(true);
            /** @var array<string,callable> $cbs */
            $cbs = $prop->getValue($agent);
            $this->assertArrayHasKey('/route', $cbs);
        }
    }
}
