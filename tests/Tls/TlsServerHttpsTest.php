<?php

declare(strict_types=1);

namespace SignalWire\Tests\Tls;

use PHPUnit\Framework\TestCase;

/**
 * TLS capability test (quadrant 3 of 3): the SDK's own AgentServer serves a
 * *real* verified HTTPS endpoint.
 *
 * AgentServer::serve() runs a Workerman SSL worker when SWML_SSL_ENABLED is
 * set with valid SWML_SSL_CERT_PATH / SWML_SSL_KEY_PATH (mirroring Python's
 * contract), terminating TLS with the shared porting-sdk leaf cert (SAN
 * localhost / 127.0.0.1). Workerman::runAll() blocks and owns process-global
 * signals/argv, so the server runs in a spawned child; this test then reaches
 * its unauthenticated /health route from an in-test cURL client that trusts
 * the test CA over https://. A real "status: healthy" body can only return
 * over a completed, CA-verified TLS session. Peer verification stays ON
 * (CURLOPT_SSL_VERIFYPEER=true); no verify=false, no transport mock.
 *
 * A negative test reaches the same endpoint with NO trusted CA and asserts the
 * handshake is rejected, proving the server's cert is genuinely verified.
 *
 * @group tls
 */
final class TlsServerHttpsTest extends TestCase
{
    private static ?string $certs = null;
    private static int $port = 0;
    /** @var resource|null */
    private static $proc = null;
    private static int $pgid = 0;
    private static string $bootstrap = '';

    public static function setUpBeforeClass(): void
    {
        $certs = TlsSupport::certsDir();
        if ($certs === null) {
            self::markTestSkipped('porting-sdk/test_harness/tls not adjacent to repo');
        }
        self::$certs = $certs;
        self::$port = TlsSupport::freeTcpPort();

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $cert = TlsSupport::serverCert($certs);
        $key  = TlsSupport::serverKey($certs);

        // Child bootstrap: build an AgentServer with one agent and serve() it
        // over HTTPS (the env triggers the Workerman SSL path). Workerman reads
        // $argv for its start/stop command, so pass "start".
        self::$bootstrap = (string) tempnam(sys_get_temp_dir(), 'tls_server_') . '.php';
        $php = '<?php' . "\n"
            . 'require ' . var_export($autoload, true) . ";\n"
            . '$argv = [$argv[0], "start"]; $_SERVER["argv"] = $argv;' . "\n"
            . 'putenv("SWML_SSL_ENABLED=true");' . "\n"
            . 'putenv("SWML_SSL_CERT_PATH=" . ' . var_export($cert, true) . ");\n"
            . 'putenv("SWML_SSL_KEY_PATH=" . ' . var_export($key, true) . ");\n"
            . '$server = new \SignalWire\Server\AgentServer("127.0.0.1", ' . self::$port . ");\n"
            . '$agent = new \SignalWire\Agent\AgentBase('
                . 'name: "tls-cap-test", route: "/", '
                . 'basicAuthUser: "u", basicAuthPassword: "p");' . "\n"
            . '$server->register($agent);' . "\n"
            . '$server->serve();' . "\n";
        file_put_contents(self::$bootstrap, $php);

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        // Workerman's master forks a worker child that becomes the actual
        // listener; killing only the proc_open parent would orphan the worker.
        // Run the whole tree in a fresh session via setsid so it shares one
        // process group, then kill the GROUP on teardown.
        $proc = proc_open(
            ['setsid', PHP_BINARY, self::$bootstrap],
            $descriptors,
            $pipes
        );
        if (!is_resource($proc)) {
            self::markTestSkipped('could not spawn AgentServer child');
        }
        self::$proc = $proc;
        $status = proc_get_status($proc);
        // With setsid the spawned process is its own session/group leader, so
        // its PID is also the process-group id.
        self::$pgid = (int) ($status['pid'] ?? 0);
    }

    public static function tearDownAfterClass(): void
    {
        // Kill the entire process group (master + Workerman worker).
        if (self::$pgid > 0 && function_exists('posix_kill')) {
            @posix_kill(-self::$pgid, defined('SIGTERM') ? SIGTERM : 15);
            usleep(300_000); // let Workerman stop gracefully
            @posix_kill(-self::$pgid, defined('SIGKILL') ? SIGKILL : 9);
            self::$pgid = 0;
        }
        if (is_resource(self::$proc)) {
            @proc_terminate(self::$proc, defined('SIGKILL') ? SIGKILL : 9);
            @proc_close(self::$proc);
            self::$proc = null;
        }
        if (self::$bootstrap !== '' && is_file(self::$bootstrap)) {
            @unlink(self::$bootstrap);
        }
    }

    public function testHealthOverHttpsWithTrustedCa(): void
    {
        $caCert = TlsSupport::caCert((string) self::$certs);
        $url = 'https://127.0.0.1:' . self::$port . '/health';

        // Poll until the Workerman TLS listener is up.
        $body = null;
        $code = 0;
        $deadline = microtime(true) + 15.0;
        while (microtime(true) < $deadline) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_CAINFO => $caCert,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);
            if (is_string($body) && $code === 200) {
                break;
            }
            // A cert-verification errno here would mean the CA was rejected —
            // a real failure, not a not-yet-ready condition. errno 60 is
            // CURLE_SSL_CACERT (== CURLE_SSL_PEER_CERTIFICATE); 51 is the
            // generic peer-verification failure on some builds.
            if (in_array($errno, [CURLE_SSL_CACERT, 51], true)) {
                $this->fail("TLS verification failed against trusted CA (errno {$errno})");
            }
            usleep(200_000);
        }

        $this->assertSame(200, $code, 'server /health never returned 200 over https');
        $this->assertIsString($body);
        $payload = json_decode((string) $body, true);
        $this->assertIsArray($payload, 'health body is not JSON');
        $this->assertSame('healthy', $payload['status'] ?? null, 'health status not "healthy"');
    }

    /**
     * @depends testHealthOverHttpsWithTrustedCa
     */
    public function testUntrustedClientIsRejected(): void
    {
        $url = 'https://127.0.0.1:' . self::$port . '/health';
        // No CAINFO, default trust store: the private-CA server cert must fail.
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        $this->assertFalse(is_string($body) && $body !== '', 'untrusted HTTPS GET unexpectedly returned a body');
        // errno 60 = CURLE_SSL_CACERT (== CURLE_SSL_PEER_CERTIFICATE); 51 is
        // the generic peer-verification failure on some cURL builds.
        $this->assertContains(
            $errno,
            [CURLE_SSL_CACERT, 51],
            "expected a TLS verification failure, got cURL errno {$errno}"
        );
    }
}
