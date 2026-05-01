<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Server\AgentServer;
use SignalWire\Agent\AgentBase;
use SignalWire\Logging\Logger;
use SignalWire\SWML\Schema;

class AgentServerTest extends TestCase
{
    protected function setUp(): void
    {
        Logger::reset();
        Schema::reset();
        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
        putenv('SWML_PROXY_URL_BASE');
        putenv('PORT');
    }

    protected function tearDown(): void
    {
        Logger::reset();
        Schema::reset();
        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
        putenv('SWML_PROXY_URL_BASE');
        putenv('PORT');
    }

    private function makeAgent(string $name, string $route = '/'): AgentBase
    {
        return new AgentBase([
            'name'                => $name,
            'route'               => $route,
            'basic_auth_user'     => 'testuser',
            'basic_auth_password' => 'testpass',
        ]);
    }

    private function authHeader(string $user = 'testuser', string $pass = 'testpass'): array
    {
        return ['Authorization' => 'Basic ' . base64_encode("{$user}:{$pass}")];
    }

    // ==================================================================
    //  1. Server Construction
    // ==================================================================

    public function testServerConstruction(): void
    {
        $server = new AgentServer();
        $this->assertSame('0.0.0.0', $server->getHost());
        $this->assertIsInt($server->getPort());
        $this->assertSame([], $server->getAgents());
    }

    public function testServerCustomOptions(): void
    {
        $server = new AgentServer(['host' => '127.0.0.1', 'port' => 8080]);
        $this->assertSame('127.0.0.1', $server->getHost());
        $this->assertSame(8080, $server->getPort());
    }

    // ==================================================================
    //  2. Register Agent
    // ==================================================================

    public function testRegisterAgent(): void
    {
        $server = new AgentServer();
        $agent  = $this->makeAgent('support', '/support');

        $server->register($agent);

        $this->assertContains('/support', $server->getAgents());
        $this->assertSame($agent, $server->getAgent('/support'));
    }

    public function testRegisterWithRouteOverride(): void
    {
        $server = new AgentServer();
        $agent  = $this->makeAgent('sales', '/original');

        $server->register($agent, '/sales');
        $this->assertContains('/sales', $server->getAgents());
        $this->assertNull($server->getAgent('/original'));
    }

    public function testRegisterDuplicateRouteThrows(): void
    {
        $server = new AgentServer();
        $agent1 = $this->makeAgent('a1', '/test');
        $agent2 = $this->makeAgent('a2', '/test');

        $server->register($agent1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already registered');
        $server->register($agent2);
    }

    // ==================================================================
    //  3. Unregister Agent
    // ==================================================================

    public function testUnregisterAgent(): void
    {
        $server = new AgentServer();
        $agent  = $this->makeAgent('temp', '/temp');

        $server->register($agent);
        $this->assertContains('/temp', $server->getAgents());

        $server->unregister('/temp');
        $this->assertNotContains('/temp', $server->getAgents());
    }

    // ==================================================================
    //  4. getAgents
    // ==================================================================

    public function testGetAgentsReturnsSortedRoutes(): void
    {
        $server = new AgentServer();
        $server->register($this->makeAgent('b', '/b'));
        $server->register($this->makeAgent('a', '/a'));

        $list = $server->getAgents();
        $this->assertCount(2, $list);
        $this->assertSame('/a', $list[0]);
        $this->assertSame('/b', $list[1]);
    }

    // ==================================================================
    //  5. getAgent
    // ==================================================================

    public function testGetAgentFound(): void
    {
        $server = new AgentServer();
        $agent  = $this->makeAgent('x', '/x');
        $server->register($agent);

        $found = $server->getAgent('/x');
        $this->assertSame($agent, $found);
    }

    public function testGetAgentNotFound(): void
    {
        $server = new AgentServer();
        $this->assertNull($server->getAgent('/missing'));
    }

    // ==================================================================
    //  6. Route Normalization
    // ==================================================================

    public function testRouteNormalization(): void
    {
        $server = new AgentServer();
        $agent  = $this->makeAgent('norm');

        $server->register($agent, 'no_slash');
        $this->assertContains('/no_slash', $server->getAgents());
    }

    // ==================================================================
    //  7. Method Chaining
    // ==================================================================

    public function testRegisterReturnsServerForChaining(): void
    {
        $server = new AgentServer();
        $ret = $server->register($this->makeAgent('chain', '/chain'));
        $this->assertSame($server, $ret);
    }

    public function testUnregisterReturnsServerForChaining(): void
    {
        $server = new AgentServer();
        $server->register($this->makeAgent('x', '/x'));
        $ret = $server->unregister('/x');
        $this->assertSame($server, $ret);
    }

    // ==================================================================
    //  8. Health Endpoint
    // ==================================================================

    public function testHealthEndpoint(): void
    {
        $server = new AgentServer();
        $server->register($this->makeAgent('agent1', '/agent1'));

        [$status, $headers, $body] = $server->handleRequest('GET', '/health');

        $this->assertSame(200, $status);
        $this->assertSame('application/json', $headers['Content-Type']);

        $data = json_decode($body, true);
        $this->assertSame('healthy', $data['status']);
        $this->assertArrayHasKey('agents', $data);
        $this->assertContains('agent1', $data['agents']);
    }

    // ==================================================================
    //  9. Ready Endpoint
    // ==================================================================

    public function testReadyEndpoint(): void
    {
        $server = new AgentServer();

        [$status, , $body] = $server->handleRequest('GET', '/ready');

        $this->assertSame(200, $status);
        $data = json_decode($body, true);
        $this->assertSame('ready', $data['status']);
    }

    // ==================================================================
    // 10. Health/Ready No Auth Required
    // ==================================================================

    public function testHealthNoAuthRequired(): void
    {
        $server = new AgentServer();
        [$status, ,] = $server->handleRequest('GET', '/health', []);
        $this->assertSame(200, $status);
    }

    public function testReadyNoAuthRequired(): void
    {
        $server = new AgentServer();
        [$status, ,] = $server->handleRequest('GET', '/ready', []);
        $this->assertSame(200, $status);
    }

    // ==================================================================
    // 11. Root Index
    // ==================================================================

    public function testRootIndexListsAgents(): void
    {
        $server = new AgentServer();
        $server->register($this->makeAgent('alpha', '/alpha'));
        $server->register($this->makeAgent('beta',  '/beta'));

        [$status, , $body] = $server->handleRequest('GET', '/');

        $this->assertSame(200, $status);
        $data = json_decode($body, true);
        $this->assertArrayHasKey('agents', $data);
        $this->assertCount(2, $data['agents']);

        $names = array_column($data['agents'], 'name');
        $this->assertContains('alpha', $names);
        $this->assertContains('beta', $names);
    }

    // ==================================================================
    // 12. Route Dispatch with Auth
    // ==================================================================

    public function testRouteDispatchWithAuth(): void
    {
        $server = new AgentServer();
        $agent  = $this->makeAgent('routed', '/routed');
        $server->register($agent);

        $headers = $this->authHeader();
        [$status, , $body] = $server->handleRequest('GET', '/routed', $headers);

        $this->assertSame(200, $status);
        $data = json_decode($body, true);
        $this->assertSame('1.0.0', $data['version']);
    }

    public function testRouteDispatchWithoutAuthReturns401(): void
    {
        $server = new AgentServer();
        $agent  = $this->makeAgent('secured', '/secured');
        $server->register($agent);

        [$status, ,] = $server->handleRequest('GET', '/secured', []);
        $this->assertSame(401, $status);
    }

    public function testRouteDispatchWithWrongAuthReturns401(): void
    {
        $server = new AgentServer();
        $agent  = $this->makeAgent('secured', '/secured');
        $server->register($agent);

        $headers = $this->authHeader('wrong', 'credentials');
        [$status, ,] = $server->handleRequest('GET', '/secured', $headers);
        $this->assertSame(401, $status);
    }

    // ==================================================================
    // 13. 404 for Unknown Route
    // ==================================================================

    public function testUnknownRouteReturns404(): void
    {
        $server = new AgentServer();

        [$status, , $body] = $server->handleRequest('GET', '/unknown');

        $this->assertSame(404, $status);
        $data = json_decode($body, true);
        $this->assertArrayHasKey('error', $data);
    }

    // ==================================================================
    // 14. Security Headers
    // ==================================================================

    public function testSecurityHeadersOnHealthResponse(): void
    {
        $server = new AgentServer();
        [$status, $headers,] = $server->handleRequest('GET', '/health');

        $this->assertSame(200, $status);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $headers['X-Frame-Options']);
        $this->assertSame('no-store', $headers['Cache-Control']);
    }

    public function testSecurityHeadersOn404(): void
    {
        $server = new AgentServer();
        [, $headers,] = $server->handleRequest('GET', '/nope');

        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $headers['X-Frame-Options']);
        $this->assertSame('no-store', $headers['Cache-Control']);
    }

    // ==================================================================
    // 15. Multiple Agents Routing
    // ==================================================================

    public function testMultipleAgentsRouting(): void
    {
        $server = new AgentServer();
        $server->register($this->makeAgent('alpha', '/alpha'));
        $server->register($this->makeAgent('beta',  '/beta'));

        $headers = $this->authHeader();

        [$statusA, , $bodyA] = $server->handleRequest('GET', '/alpha', $headers);
        [$statusB, , $bodyB] = $server->handleRequest('GET', '/beta',  $headers);

        $this->assertSame(200, $statusA);
        $this->assertSame(200, $statusB);

        $dataA = json_decode($bodyA, true);
        $dataB = json_decode($bodyB, true);

        $this->assertSame('1.0.0', $dataA['version']);
        $this->assertSame('1.0.0', $dataB['version']);
    }

    // ==================================================================
    // 16. SIP Routing
    // ==================================================================

    public function testSipRoutingSetup(): void
    {
        $server = new AgentServer();

        $this->assertFalse($server->isSipRoutingEnabled());

        $ret = $server->setupSipRouting();
        $this->assertSame($server, $ret);
        $this->assertTrue($server->isSipRoutingEnabled());
        // Default route per Python is "/sip"; auto_map defaults to true.
        $this->assertSame('/sip', $server->getSipRoute());
        $this->assertTrue($server->getSipAutoMap());
    }

    public function testSipRoutingSetupCustomRoute(): void
    {
        $server = new AgentServer();
        // Python signature: setup_sip_routing(route="/sip", auto_map=True)
        // Pass through both — verify they round-trip and the route gets
        // normalised (trailing slash stripped, leading slash added).
        $server->setupSipRouting(route: '/voip/inbound', auto_map: false);

        $this->assertTrue($server->isSipRoutingEnabled());
        $this->assertSame('/voip/inbound', $server->getSipRoute());
        $this->assertFalse($server->getSipAutoMap());
    }

    public function testSipRoutingSetupNormalisesRoute(): void
    {
        $server = new AgentServer();
        // Missing leading slash + trailing slash: Python adds the leading
        // slash and rstrips trailing slash before storing.
        $server->setupSipRouting('voip/');

        $this->assertSame('/voip', $server->getSipRoute());
    }

    public function testSipRoutingSetupAutoMapPopulatesUsernames(): void
    {
        $server = new AgentServer();
        $server->register($this->makeAgent('alice', '/alice'));
        $server->register($this->makeAgent('bob', '/sales'));

        $server->setupSipRouting(auto_map: true);

        $mapping = $server->getSipUsernameMapping();
        // Auto-map derives lowercased username from each agent's route
        // (mirrors Python's _auto_map_agent_sip_usernames).
        $this->assertArrayHasKey('alice', $mapping);
        $this->assertSame('/alice', $mapping['alice']);
        $this->assertArrayHasKey('sales', $mapping);
        $this->assertSame('/sales', $mapping['sales']);
    }

    public function testSipRoutingSetupAutoMapFalseDoesNotPopulate(): void
    {
        $server = new AgentServer();
        $server->register($this->makeAgent('alice', '/alice'));

        $server->setupSipRouting(auto_map: false);

        $this->assertSame([], $server->getSipUsernameMapping());
    }

    public function testSipRoutingSetupRepeatedCallIsNoop(): void
    {
        $server = new AgentServer();
        $server->setupSipRouting(route: '/sip');
        // Python: if already enabled, log warning + return without
        // overwriting. Verify same behavior here.
        $server->setupSipRouting(route: '/different');
        $this->assertSame('/sip', $server->getSipRoute());
    }

    public function testRegisterSipUsername(): void
    {
        $server = new AgentServer();
        $server->setupSipRouting();
        $server->registerSipUsername('alice', '/support');

        $mapping = $server->getSipUsernameMapping();
        $this->assertArrayHasKey('alice', $mapping);
        $this->assertSame('/support', $mapping['alice']);
    }

    public function testSipUsernameNormalizesRoute(): void
    {
        $server = new AgentServer();
        $server->registerSipUsername('bob', 'sales');

        $mapping = $server->getSipUsernameMapping();
        $this->assertSame('/sales', $mapping['bob']);
    }

    // ==================================================================
    // 17. Static File Serving
    // ==================================================================

    public function testServeStaticSuccess(): void
    {
        // Create a temp directory with a test file
        $tmpDir = sys_get_temp_dir() . '/sw_static_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir . '/test.txt', 'Hello World');

        try {
            $server = new AgentServer();
            $server->serveStatic($tmpDir, '/static');

            [$status, $headers, $body] = $server->handleRequest('GET', '/static/test.txt');

            $this->assertSame(200, $status);
            $this->assertSame('text/plain', $headers['Content-Type']);
            $this->assertSame('Hello World', $body);
            $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        } finally {
            unlink($tmpDir . '/test.txt');
            rmdir($tmpDir);
        }
    }

    public function testServeStaticHtmlFile(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sw_static_html_' . uniqid();
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir . '/index.html', '<h1>Hello</h1>');

        try {
            $server = new AgentServer();
            $server->serveStatic($tmpDir, '/web');

            [$status, $headers, $body] = $server->handleRequest('GET', '/web/index.html');

            $this->assertSame(200, $status);
            $this->assertSame('text/html', $headers['Content-Type']);
            $this->assertSame('<h1>Hello</h1>', $body);
        } finally {
            unlink($tmpDir . '/index.html');
            rmdir($tmpDir);
        }
    }

    public function testServeStaticPathTraversalBlocked(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sw_static_trav_' . uniqid();
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir . '/safe.txt', 'safe');

        try {
            $server = new AgentServer();
            $server->serveStatic($tmpDir, '/static');

            [$status, ,] = $server->handleRequest('GET', '/static/../../../etc/passwd');
            $this->assertSame(403, $status);
        } finally {
            unlink($tmpDir . '/safe.txt');
            rmdir($tmpDir);
        }
    }

    public function testServeStaticSecurityHeaders(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sw_static_sec_' . uniqid();
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir . '/data.json', '{}');

        try {
            $server = new AgentServer();
            $server->serveStatic($tmpDir, '/files');

            [$status, $headers,] = $server->handleRequest('GET', '/files/data.json');

            $this->assertSame(200, $status);
            $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
            $this->assertSame('DENY', $headers['X-Frame-Options']);
            $this->assertSame('no-store', $headers['Cache-Control']);
        } finally {
            unlink($tmpDir . '/data.json');
            rmdir($tmpDir);
        }
    }

    public function testServeStaticNonexistentDirectoryThrows(): void
    {
        $server = new AgentServer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');
        $server->serveStatic('/nonexistent/dir/totally/fake', '/static');
    }

    public function testServeStaticFileNotFound(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sw_static_nf_' . uniqid();
        mkdir($tmpDir, 0755, true);

        try {
            $server = new AgentServer();
            $server->serveStatic($tmpDir, '/static');

            // Requesting a file that doesn't exist should fall through to 404
            [$status, ,] = $server->handleRequest('GET', '/static/missing.txt');
            $this->assertSame(404, $status);
        } finally {
            rmdir($tmpDir);
        }
    }

    public function testServeStaticMethodChaining(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sw_static_chain_' . uniqid();
        mkdir($tmpDir, 0755, true);

        try {
            $server = new AgentServer();
            $ret = $server->serveStatic($tmpDir, '/static');
            $this->assertSame($server, $ret);
        } finally {
            rmdir($tmpDir);
        }
    }

    // ==================================================================
    // 18. Sub-path dispatch to agent
    // ==================================================================

    public function testAgentSubPathSwaigDispatch(): void
    {
        $server = new AgentServer();
        $agent  = $this->makeAgent('sub', '/sub');

        // Add a tool so swaig dispatch has something to call
        $agent->defineTool(
            name: 'test_func',
            description: 'A test function',
            parameters: [],
            handler: function (array $args, array $rawData): \SignalWire\SWAIG\FunctionResult {
                return new \SignalWire\SWAIG\FunctionResult('test response');
            },
        );

        $server->register($agent);

        $headers = $this->authHeader();
        $body = json_encode([
            'function' => 'test_func',
            'argument' => ['parsed' => [['key' => 'value']]],
        ]);

        [$status, , $responseBody] = $server->handleRequest('POST', '/sub/swaig', $headers, $body);

        $this->assertSame(200, $status);
        $data = json_decode($responseBody, true);
        $this->assertStringContainsString('test response', $data['response']);
    }
}
