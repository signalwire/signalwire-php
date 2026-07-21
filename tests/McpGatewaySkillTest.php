<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\Logging\Logger;
use SignalWire\Skills\Builtin\McpGateway;
use SignalWire\Skills\SkillRegistry;
use SignalWire\SWML\Schema;

/**
 * Tests for the MCP Gateway CLIENT skill (McpGateway).
 *
 * Mirrors Python's mcp_gateway skill test: the skill connects to a running
 * MCP Gateway over HTTP, lists its tools, and registers each as a SWAIG
 * function; calling a registered function proxies back through the gateway.
 * `verify_ssl` defaults to true (secure) and, when flipped, really controls
 * TLS certificate verification on the outbound cURL call (not a stored no-op).
 *
 * The HTTP gateway is a real `php -S` fixture bound to an ephemeral port; the
 * verify_ssl flip is proven against a self-signed HTTPS fixture whose cert is
 * NOT in the trust store — verify ON must reject it, verify OFF must accept it.
 */
class McpGatewaySkillTest extends TestCase
{
    protected function setUp(): void
    {
        Logger::reset();
        Schema::reset();
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        Logger::reset();
        Schema::reset();
        SkillRegistry::reset();
    }

    private function makeAgent(): AgentBase
    {
        return new AgentBase(
            name: 'test-agent',
            route: '/',
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass'
        );
    }

    // ── surface / parity ────────────────────────────────────────────────────

    public function testRegisteredAsBuiltinSkill(): void
    {
        $registry = SkillRegistry::instance();
        $factory = $registry->getFactory('mcp_gateway');
        $this->assertSame(McpGateway::class, $factory);
        $this->assertContains('mcp_gateway', $registry->listSkills());
    }

    public function testParameterSchemaExposesVerifySslSecureByDefault(): void
    {
        $skill = new McpGateway($this->makeAgent(), []);
        $schema = $skill->getParameterSchema();
        $props = $schema['properties'];
        $this->assertIsArray($props);

        $this->assertArrayHasKey('verify_ssl', $props);
        $this->assertSame('boolean', $props['verify_ssl']['type']);
        // Secure by default: verification ON.
        $this->assertTrue($props['verify_ssl']['default']);

        // Gateway connection + auth surface present.
        $this->assertArrayHasKey('gateway_url', $props);
        $this->assertTrue($props['gateway_url']['required']);
        $this->assertArrayHasKey('auth_token', $props);
        $this->assertArrayHasKey('auth_user', $props);
        $this->assertArrayHasKey('auth_password', $props);
    }

    public function testHintsBaseOnlyBeforeSetupReadsServices(): void
    {
        // The base MCP/gateway hints are always present; the per-service hints
        // are only added once setup() reads the configured services (covered by
        // the fixture test below). Before setup() only the base hints appear.
        $skill = new McpGateway($this->makeAgent(), [
            'gateway_url' => 'http://127.0.0.1:1',
            'auth_token'  => 'tok',
            'services'    => [['name' => 'weather'], ['name' => 'files']],
        ]);
        $this->assertSame(['MCP', 'gateway'], $skill->getHints());
    }

    // ── HTTP gateway: tool registration + call proxying ──────────────────────

    public function testRegistersGatewayToolsAsSwaigFunctionsAndProxiesCall(): void
    {
        [$proc, $port, $tmp] = $this->bootGatewayFixture();
        try {
            $agent = $this->makeAgent();
            $skill = new McpGateway($agent, [
                'gateway_url' => "http://127.0.0.1:{$port}",
                'auth_token'  => 'secret-token',
                'services'    => [['name' => 'weather']],
                'tool_prefix' => 'mcp_',
            ]);

            $this->assertTrue($skill->setup(), 'setup() should connect to the /health fixture');
            $skill->registerTools();

            // The gateway advertised a 'get_forecast' tool on the 'weather'
            // service → registered as mcp_weather_get_forecast.
            $names = $this->registeredFunctionNames($agent);
            $this->assertContains('mcp_weather_get_forecast', $names);
            // The internal hangup hook is registered too.
            $this->assertContains('_mcp_gateway_hangup', $names);

            // Calling the registered SWAIG function proxies through the gateway
            // and returns its result payload.
            $result = $agent->onFunctionCall(
                'mcp_weather_get_forecast',
                ['city' => 'Denver'],
                ['call_id' => 'call-123'],
            );
            $this->assertNotNull($result);
            $this->assertSame('Sunny in Denver', $result->toArray()['response']);
        } finally {
            $this->tearDownFixture($proc, $tmp);
        }
    }

    public function testHintsAndPromptSectionsAfterSetup(): void
    {
        [$proc, $port, $tmp] = $this->bootGatewayFixture();
        try {
            $agent = $this->makeAgent();
            $skill = new McpGateway($agent, [
                'gateway_url' => "http://127.0.0.1:{$port}",
                'auth_token'  => 'secret-token',
                'services'    => [['name' => 'weather']],
            ]);
            $this->assertTrue($skill->setup());

            $hints = $skill->getHints();
            $this->assertContains('MCP', $hints);
            $this->assertContains('weather', $hints);

            $sections = $skill->getPromptSections();
            $this->assertNotEmpty($sections);
            $this->assertSame('MCP Gateway Integration', $sections[0]['title']);

            $global = $skill->getGlobalData();
            $this->assertSame("http://127.0.0.1:{$port}", $global['mcp_gateway_url']);
            $this->assertSame(['weather'], $global['mcp_services']);
        } finally {
            $this->tearDownFixture($proc, $tmp);
        }
    }

    public function testBasicAuthRequiresUserAndPassword(): void
    {
        // No token and missing basic-auth creds → setup fails.
        $skill = new McpGateway($this->makeAgent(), [
            'gateway_url' => 'http://127.0.0.1:1',
        ]);
        $this->assertFalse($skill->setup());
    }

    // ── verify_ssl really controls TLS verification ──────────────────────────

    public function testVerifySslDefaultTrueRejectsSelfSignedGateway(): void
    {
        [$proc, $port, $cert] = $this->bootTlsGatewayFixture();
        try {
            $skill = new McpGateway($this->makeAgent(), [
                'gateway_url' => "https://127.0.0.1:{$port}",
                'auth_token'  => 'tok',
                // verify_ssl omitted → secure default true.
            ]);
            // The self-signed cert is untrusted; with verification ON the
            // /health probe fails, so setup() returns false.
            $this->assertFalse(
                $skill->setup(),
                'verify_ssl default true must reject the self-signed gateway cert'
            );
        } finally {
            $this->tearDownTlsFixture($proc, $cert);
        }
    }

    public function testVerifySslFalseAcceptsSelfSignedGateway(): void
    {
        [$proc, $port, $cert] = $this->bootTlsGatewayFixture();
        try {
            $skill = new McpGateway($this->makeAgent(), [
                'gateway_url' => "https://127.0.0.1:{$port}",
                'auth_token'  => 'tok',
                'verify_ssl'  => false,
            ]);
            // Explicit opt-out → the same self-signed cert is now accepted and
            // the /health probe succeeds.
            $ok = $skill->setup();
            $diag = '';
            if (!$ok) {
                // TEMP DIAGNOSTIC: capture curl backend + a direct probe, embedded
                // into the assertion message so it surfaces in phpunit output.
                $v = \curl_version();
                $ch = \curl_init();
                \curl_setopt_array($ch, [
                    CURLOPT_URL => "https://127.0.0.1:{$port}/health",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                ]);
                $b = \curl_exec($ch);
                $en = \curl_errno($ch);
                $er = \curl_error($ch);
                $st = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
                \curl_close($ch);
                $diag = " DIAG curl={$v['version']} ssl={$v['ssl_version']}"
                    . " errno={$en} err=[{$er}] status={$st}"
                    . ' body=[' . ($b === false ? 'FALSE' : $b) . ']';
            }
            $this->assertTrue(
                $ok,
                'verify_ssl=false must accept the self-signed gateway cert' . $diag
            );
        } finally {
            $this->tearDownTlsFixture($proc, $cert);
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * Boot a `php -S` router fixture speaking the MCP Gateway HTTP contract:
     *   GET  /health                          -> 200 {"status":"ok"}
     *   GET  /services/weather/tools          -> tool list
     *   POST /services/weather/call           -> {"result":"Sunny in Denver"}
     *
     * @return array{0: resource, 1: int, 2: string}
     */
    private function bootGatewayFixture(): array
    {
        $router = <<<'PHP'
        <?php
        header('Content-Type: application/json');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($path === '/health') {
            echo json_encode(['status' => 'ok']);
            return;
        }
        if ($path === '/services/weather/tools' && $method === 'GET') {
            echo json_encode(['tools' => [[
                'name' => 'get_forecast',
                'description' => 'Get the weather forecast',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string', 'description' => 'City name']],
                    'required' => ['city'],
                ],
            ]]]);
            return;
        }
        if ($path === '/services/weather/call' && $method === 'POST') {
            echo json_encode(['result' => 'Sunny in Denver']);
            return;
        }
        http_response_code(404);
        echo json_encode(['error' => 'not found']);
        PHP;

        return $this->bootPhpServer($router, 'sw_mcp_fx_');
    }

    /**
     * Boot a self-signed HTTPS fixture answering /health. Returns
     * [proc, port, certPath]. The cert is NOT in the trust store, so cURL with
     * verification ON must reject it.
     *
     * @return array{0: resource, 1: int, 2: string}
     */
    private function bootTlsGatewayFixture(): array
    {
        $certDir = \sys_get_temp_dir();
        $certPath = \tempnam($certDir, 'sw_mcp_tls_') . '.pem';
        $keyPath = $certPath . '.key';

        // Self-signed cert for CN=127.0.0.1.
        $genCmd = \sprintf(
            'openssl req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -days 2 '
            . '-subj "/CN=127.0.0.1" 2>/dev/null',
            \escapeshellarg($keyPath),
            \escapeshellarg($certPath),
        );
        \exec($genCmd, $out, $rc);
        if ($rc !== 0 || !\is_file($certPath) || !\is_file($keyPath)) {
            $this->markTestSkipped('openssl not available to generate a self-signed cert');
        }

        $port = $this->freePort();

        // A tiny PHP TLS server: accept one connection at a time, answer any
        // request with 200 {"status":"ok"}. Runs until terminated.
        $pemCombined = $certPath . '.combined.pem';
        \file_put_contents($pemCombined, \file_get_contents($certPath) . \file_get_contents($keyPath));

        $serverScript = <<<PHP
        <?php
        \$ctx = stream_context_create(['ssl' => [
            'local_cert' => '{$pemCombined}',
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]]);
        \$srv = stream_socket_server(
            'tls://127.0.0.1:{$port}',
            \$errno, \$errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            \$ctx
        );
        if (\$srv === false) { fwrite(STDERR, "bind failed: \$errstr\\n"); exit(1); }
        while (true) {
            \$conn = @stream_socket_accept(\$srv, 30);
            if (\$conn === false) { continue; }
            \$body = '{"status":"ok"}';
            \$resp = "HTTP/1.1 200 OK\\r\\n"
                . "Content-Type: application/json\\r\\n"
                . "Content-Length: " . strlen(\$body) . "\\r\\n"
                . "Connection: close\\r\\n\\r\\n" . \$body;
            @fwrite(\$conn, \$resp);
            @fclose(\$conn);
        }
        PHP;

        $scriptPath = \tempnam($certDir, 'sw_mcp_tls_srv_') . '.php';
        \file_put_contents($scriptPath, $serverScript);

        $cmd = \escapeshellcmd(PHP_BINARY) . ' ' . \escapeshellarg($scriptPath);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = \proc_open($cmd, $descriptors, $pipes);
        $this->assertIsResource($proc, 'Failed to spawn TLS fixture');
        \fclose($pipes[0]);

        // Wait for the TLS port to accept connections.
        $ok = false;
        $deadline = \microtime(true) + 5.0;
        while (\microtime(true) < $deadline) {
            $conn = @\fsockopen('127.0.0.1', $port, $e, $es, 0.2);
            if ($conn !== false) {
                \fclose($conn);
                $ok = true;
                break;
            }
            \usleep(50_000);
        }
        $this->assertTrue($ok, "TLS fixture did not bind to 127.0.0.1:{$port}");

        // Track ancillary files for cleanup via the cert path prefix.
        return [$proc, $port, $certPath];
    }

    /**
     * @return array{0: resource, 1: int, 2: string}
     */
    private function bootPhpServer(string $routerScript, string $prefix): array
    {
        $tmp = \tempnam(\sys_get_temp_dir(), $prefix) . '.php';
        \file_put_contents($tmp, $routerScript);

        $port = $this->freePort();
        $cmd = \escapeshellcmd(PHP_BINARY)
            . ' -S 127.0.0.1:' . $port
            . ' ' . \escapeshellarg($tmp);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = \proc_open($cmd, $descriptors, $pipes);
        $this->assertIsResource($proc, 'Failed to spawn php -S fixture');
        \fclose($pipes[0]);

        $ok = false;
        $deadline = \microtime(true) + 5.0;
        while (\microtime(true) < $deadline) {
            $conn = @\fsockopen('127.0.0.1', $port, $e, $es, 0.2);
            if ($conn !== false) {
                \fclose($conn);
                $ok = true;
                break;
            }
            \usleep(50_000);
        }
        $this->assertTrue($ok, "php -S fixture did not bind to 127.0.0.1:{$port}");

        return [$proc, $port, $tmp];
    }

    private function freePort(): int
    {
        $sock = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($sock, "freePort: {$errstr}");
        $name = \stream_socket_get_name($sock, false);
        \fclose($sock);
        $port = (int) \substr((string) $name, (int) \strrpos((string) $name, ':') + 1);
        $this->assertGreaterThan(0, $port);
        return $port;
    }

    private function tearDownFixture(mixed $proc, string $tmp): void
    {
        if (\is_resource($proc)) {
            @\proc_terminate($proc, SIGTERM);
            @\proc_close($proc);
        }
        @\unlink($tmp);
    }

    private function tearDownTlsFixture(mixed $proc, string $certPath): void
    {
        if (\is_resource($proc)) {
            @\proc_terminate($proc, SIGTERM);
            @\proc_close($proc);
        }
        @\unlink($certPath);
        @\unlink($certPath . '.key');
        @\unlink($certPath . '.combined.pem');
    }

    /**
     * Extract the registered SWAIG function names from an agent's rendered SWML.
     *
     * @return list<string>
     */
    private function registeredFunctionNames(AgentBase $agent): array
    {
        $swml = $agent->renderSwml();
        $names = [];
        $sections = $swml['sections'] ?? null;
        $main = is_array($sections) ? ($sections['main'] ?? null) : null;
        if (!is_array($main)) {
            return $names;
        }
        foreach ($main as $verb) {
            if (!is_array($verb)) {
                continue;
            }
            $ai = $verb['ai'] ?? null;
            if (!is_array($ai)) {
                continue;
            }
            $swaig = $ai['SWAIG'] ?? null;
            $functions = is_array($swaig) ? ($swaig['functions'] ?? null) : null;
            if (!is_array($functions)) {
                continue;
            }
            foreach ($functions as $fn) {
                if (is_array($fn) && isset($fn['function']) && is_string($fn['function'])) {
                    $names[] = $fn['function'];
                }
            }
        }
        return $names;
    }
}
