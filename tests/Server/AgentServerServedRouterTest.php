<?php

declare(strict_types=1);

namespace SignalWire\Tests\Server;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use SignalWire\Tests\Tls\TlsSupport;

/**
 * Served-path regression lock for the AgentServer PLAINTEXT (`php -S`) router.
 *
 * Contract 6 / #61 pattern: the served endpoint must actually route to the
 * registered agents. The previous plaintext router was a generated EMPTY
 * script that delegated to nothing — every request fell through to an empty
 * 200 body, so a SIP route (or even /health) over the socket did NOT reach
 * handleRequest(). This test spawns the real server over `php -S` and asserts
 * the router delegates: /health returns the agent list, and a POST to the
 * served /sip path routes to the SIP agent (200 SWML for a registered
 * username, exercising the routing-callback wiring end to end over HTTP).
 */
class AgentServerServedRouterTest extends TestCase
{
    /** @var resource|null */
    private static $proc = null;
    private static string $bootstrap = '';
    private static int $port = 0;
    private static int $pgid = 0;

    public static function setUpBeforeClass(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open unavailable');
        }
        self::$port = TlsSupport::freeTcpPort();
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        // Router entry: build the AgentServer with one SIP-routing agent and
        // run() it. We spawn `php -S <addr> <entry>` DIRECTLY (below), so this
        // script runs under the cli-server SAPI on every request; run() ->
        // serve() takes the cli-server branch and dispatches through
        // handleRequest(). This is the exact router serve() would itself spawn
        // (its passthru path), but spawning it directly means a single php
        // process we can terminate cleanly without a process-group dance.
        self::$bootstrap = (string) \tempnam(\sys_get_temp_dir(), 'sw_served_router_') . '.php';
        $php = '<?php' . "\n"
            . 'require ' . \var_export($autoload, true) . ";\n"
            . '$server = new \SignalWire\Server\AgentServer("127.0.0.1", ' . self::$port . ");\n"
            . '$agent = new \SignalWire\Agent\AgentBase('
                . 'name: "support", route: "/support", '
                . 'basicAuthUser: "u", basicAuthPassword: "p");' . "\n"
            . '$agent->enableSipRouting();' . "\n"
            . '$agent->registerSipUsername("support");' . "\n"
            . '$server->register($agent);' . "\n"
            . '$server->run();' . "\n";
        \file_put_contents(self::$bootstrap, $php);

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        // Spawn the built-in server directly. Single php process → terminate by
        // the proc handle on teardown (no orphaned grandchild).
        $proc = \proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, self::$bootstrap],
            $descriptors,
            $pipes,
        );
        if (!\is_resource($proc)) {
            self::markTestSkipped('could not spawn php -S server');
        }
        self::$proc = $proc;
        self::$pgid = 0;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pgid > 0 && \function_exists('posix_kill')) {
            @\posix_kill(-self::$pgid, \defined('SIGTERM') ? SIGTERM : 15);
            \usleep(200_000);
            @\posix_kill(-self::$pgid, \defined('SIGKILL') ? SIGKILL : 9);
            self::$pgid = 0;
        }
        if (\is_resource(self::$proc)) {
            @\proc_terminate(self::$proc, \defined('SIGKILL') ? SIGKILL : 9);
            @\proc_close(self::$proc);
            self::$proc = null;
        }
        if (self::$bootstrap !== '' && \is_file(self::$bootstrap)) {
            @\unlink(self::$bootstrap);
        }
    }

    /**
     * @param non-empty-string $method
     * @return array{0:int,1:string} [http_code, body]
     */
    private function request(string $method, string $path, ?string $body = null, bool $auth = false): array
    {
        $url = 'http://127.0.0.1:' . self::$port . $path;
        $ch = \curl_init($url);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if ($auth) {
            \curl_setopt($ch, CURLOPT_USERPWD, 'u:p');
        }
        if ($body !== null) {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        $out = \curl_exec($ch);
        $code = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);
        return [$code, \is_string($out) ? $out : ''];
    }

    public function testServedHealthReachesHandleRequest(): void
    {
        // Poll until the `php -S` listener is up.
        $code = 0;
        $body = '';
        $deadline = \microtime(true) + 15.0;
        while (\microtime(true) < $deadline) {
            [$code, $body] = $this->request('GET', '/health');
            if ($code === 200 && $body !== '') {
                break;
            }
            \usleep(200_000);
        }
        $this->assertSame(200, $code, 'served /health never returned 200 (router did not delegate)');
        $payload = \json_decode($body, true);
        $this->assertIsArray($payload, 'served /health body is not JSON — empty router stub');
        $this->assertSame('healthy', $payload['status'] ?? null);
        $this->assertContains('support', (array) ($payload['agents'] ?? []), 'agent not listed — server did not rebuild in router');
    }

    #[Depends('testServedHealthReachesHandleRequest')]
    public function testServedSipPathRoutesToAgent(): void
    {
        // POST a SIP-shaped body to the served /support/sip path. The SIP
        // routing callback must fire, extract "support" from the body, match
        // this agent's registered username, and (declining to redirect) serve
        // the agent's SWML document — a 200 over the socket. A stored-but-
        // unconsulted mapping or an empty router would not produce this.
        $sipBody = (string) \json_encode([
            'call' => ['call_id' => 'c1', 'to' => 'support@example.com'],
        ]);
        [$code, $body] = $this->request('POST', '/support/sip', $sipBody, auth: true);

        $this->assertSame(200, $code, 'served /sip did not route to the agent');
        $swml = \json_decode($body, true);
        $this->assertIsArray($swml, 'served /sip body is not a rendered SWML document');
        $this->assertSame('1.0.0', $swml['version'] ?? null);
    }
}
