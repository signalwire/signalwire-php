<?php

declare(strict_types=1);

namespace SignalWire\Tests\Tls;

use SignalWire\Tests\Rest\MockTest as RestMockTest;

/**
 * Shared, test-only helpers for the TLS capability tests (verified HTTPS +
 * WSS). One of the three cross-port "every SDK does verified HTTPS + WSS"
 * proof points; the Go template lives in signalwire-go's tls_*_test.go.
 *
 * Responsibilities:
 *   - Locate the shared throwaway test CA under
 *     porting-sdk/test_harness/tls/ and run the idempotent gen_certs.sh.
 *   - Spawn the shared mock servers in --tls mode (mock_relay -> wss://,
 *     mock_signalwire -> https://) on dedicated ports, polling the
 *     plain-HTTP control plane for readiness.
 *
 * CA trust is REAL: every TLS path here verifies the server certificate
 * against ca.crt (no allow_self_signed, no verify_peer=false, no transport
 * mocks). Each capability test also includes a negative check proving an
 * untrusted client is rejected.
 *
 * This is a plain helper (not a PHPUnit TestCase) — PSR-4 one-class-per-file.
 */
final class TlsSupport
{
    /** Dedicated TLS ports, distinct from the plaintext mock slots. */
    public const RELAY_WS_PORT   = 18779;
    public const RELAY_HTTP_PORT = 19779;
    public const REST_PORT       = 18769;

    private const STARTUP_TIMEOUT_SEC = 40; // mock_signalwire has a ~15s cold start

    /** @var array<int, resource> Spawned mock processes, terminated on shutdown. */
    private static array $procs = [];

    /**
     * Return the absolute path to porting-sdk/test_harness/tls/certs after
     * running the idempotent gen_certs.sh, or null when porting-sdk is not
     * adjacent (the caller should markTestSkipped in that case).
     */
    public static function certsDir(): ?string
    {
        $dir = __DIR__;
        while (true) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $tlsDir = $parent . DIRECTORY_SEPARATOR . 'porting-sdk'
                . DIRECTORY_SEPARATOR . 'test_harness' . DIRECTORY_SEPARATOR . 'tls';
            $gen = $tlsDir . DIRECTORY_SEPARATOR . 'gen_certs.sh';
            if (is_file($gen)) {
                // Idempotent: regenerates only when the leaf cert is missing
                // or within 30 days of expiry.
                $cmd = sprintf('bash %s 2>&1', escapeshellarg($gen));
                exec($cmd, $out, $rc);
                if ($rc !== 0) {
                    return null;
                }
                $certs = $tlsDir . DIRECTORY_SEPARATOR . 'certs';
                return is_dir($certs) ? $certs : null;
            }
            $dir = $parent;
        }
    }

    public static function caCert(string $certsDir): string
    {
        return $certsDir . DIRECTORY_SEPARATOR . 'ca.crt';
    }

    public static function serverCert(string $certsDir): string
    {
        return $certsDir . DIRECTORY_SEPARATOR . 'server.crt';
    }

    public static function serverKey(string $certsDir): string
    {
        return $certsDir . DIRECTORY_SEPARATOR . 'server.key';
    }

    /**
     * Spawn `python -m mock_relay --tls` on the dedicated WSS + HTTP ports and
     * wait for the plain-HTTP control plane to answer /__mock__/health. The
     * WS endpoint is wss://; the control plane stays HTTP (TLS wraps only the
     * WebSocket). Returns the plain-HTTP control-plane base URL.
     *
     * @return string http://127.0.0.1:<RELAY_HTTP_PORT>
     */
    public static function startTlsMockRelay(): string
    {
        $httpBase = 'http://127.0.0.1:' . self::RELAY_HTTP_PORT;
        if (self::probeHealth($httpBase, '"schemas_loaded"')) {
            return $httpBase;
        }
        $cmd = [
            self::python(), '-m', 'mock_relay',
            '--host', '127.0.0.1',
            '--ws-port', (string) self::RELAY_WS_PORT,
            '--http-port', (string) self::RELAY_HTTP_PORT,
            '--tls',
            '--log-level', 'error',
        ];
        self::spawn($cmd, 'mock_relay', ['SIGNALWIRE_MOCK_TLS' => '1']);
        self::waitHealthy($httpBase, '"schemas_loaded"', 'mock_relay --tls');
        return $httpBase;
    }

    /**
     * Spawn `python -m mock_signalwire --tls` (HTTPS) on the dedicated port
     * and wait until it answers /__mock__/health over https://. Returns the
     * https:// base URL. The mock serves a cert signed by the shared CA.
     *
     * @return string https://127.0.0.1:<REST_PORT>
     */
    public static function startTlsMockSignalwire(string $caCert): string
    {
        $httpsBase = 'https://127.0.0.1:' . self::REST_PORT;
        if (self::probeHealthTls($httpsBase, $caCert, '"specs_loaded"')) {
            return $httpsBase;
        }
        $cmd = [
            self::python(), '-m', 'mock_signalwire',
            '--host', '127.0.0.1',
            '--port', (string) self::REST_PORT,
            '--tls',
            '--log-level', 'error',
        ];
        self::spawn($cmd, 'mock_signalwire', ['SIGNALWIRE_MOCK_TLS' => '1']);
        $deadline = microtime(true) + self::STARTUP_TIMEOUT_SEC;
        while (microtime(true) < $deadline) {
            if (self::probeHealthTls($httpsBase, $caCert, '"specs_loaded"')) {
                return $httpsBase;
            }
            usleep(200_000);
        }
        throw new \RuntimeException(
            'mock_signalwire --tls did not become ready on ' . $httpsBase
            . ' within ' . self::STARTUP_TIMEOUT_SEC . 's'
        );
    }

    /**
     * Fetch the mock_relay journal over the plain-HTTP control plane and
     * return whether an inbound (recv) frame with the given JSON-RPC method
     * was recorded — proof traffic crossed the WSS link.
     */
    public static function relaySawRecvMethod(string $httpBase, string $method): bool
    {
        $ch = curl_init($httpBase . '/__mock__/journal');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($ch);
        curl_close($ch);
        if (!is_string($body)) {
            return false;
        }
        $entries = json_decode($body, true);
        if (!is_array($entries)) {
            return false;
        }
        foreach ($entries as $e) {
            if (
                is_array($e)
                && ($e['direction'] ?? null) === 'recv'
                && ($e['method'] ?? null) === $method
            ) {
                return true;
            }
        }
        return false;
    }

    /** Reset the mock_relay journal/scenarios via the plain-HTTP control plane. */
    public static function relayReset(string $httpBase): void
    {
        foreach (['/__mock__/journal/reset', '/__mock__/scenarios/reset'] as $path) {
            $ch = curl_init($httpBase . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '',
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    /**
     * Return the most recent mock_signalwire journal entry as an assoc array,
     * fetched over the HTTPS control plane (trusting the test CA), or null.
     *
     * @return array<string,mixed>|null
     */
    public static function restLastJournal(string $httpsBase, string $caCert): ?array
    {
        $ch = curl_init($httpsBase . '/__mock__/journal');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CAINFO => $caCert,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if (!is_string($body)) {
            return null;
        }
        $entries = json_decode($body, true);
        if (!is_array($entries) || $entries === []) {
            return null;
        }
        $last = $entries[count($entries) - 1];
        return is_array($last) ? $last : null;
    }

    public static function restReset(string $httpsBase, string $caCert): void
    {
        foreach (['/__mock__/journal/reset', '/__mock__/scenarios/reset'] as $path) {
            $ch = curl_init($httpsBase . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '',
                CURLOPT_CAINFO => $caCert,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    /** Ask the OS for an unused loopback TCP port. */
    public static function freeTcpPort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new \RuntimeException("freeTcpPort: {$errstr} ({$errno})");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
        if ($port <= 0) {
            throw new \RuntimeException('freeTcpPort: could not parse a port');
        }
        return $port;
    }

    // ---- internals --------------------------------------------------------

    /**
     * @param list<string>          $cmd
     * @param array<string,string>  $extraEnv
     */
    private static function spawn(array $cmd, string $pkg, array $extraEnv): void
    {
        $pkgDir = RestMockTest::discoverPortingSdkPackage($pkg);
        if ($pkgDir === null) {
            throw new \RuntimeException(
                "TLS: porting-sdk/test_harness/{$pkg} not found adjacent to repo"
            );
        }
        $env = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $env[$k] = (string) $v;
            }
        }
        $existing = $env['PYTHONPATH'] ?? '';
        $env['PYTHONPATH'] = $existing !== '' ? ($pkgDir . PATH_SEPARATOR . $existing) : $pkgDir;
        foreach ($extraEnv as $k => $v) {
            $env[$k] = $v;
        }
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env, ['bypass_shell' => false]);
        if (!is_resource($proc)) {
            throw new \RuntimeException('proc_open failed for ' . implode(' ', $cmd));
        }
        self::$procs[] = $proc;
        if (count(self::$procs) === 1) {
            register_shutdown_function([self::class, 'shutdownAll']);
        }
    }

    public static function shutdownAll(): void
    {
        foreach (self::$procs as $proc) {
            if (is_resource($proc)) {
                @proc_terminate($proc);
                @proc_close($proc);
            }
        }
        self::$procs = [];
    }

    private static function waitHealthy(string $base, string $needle, string $label): void
    {
        $deadline = microtime(true) + self::STARTUP_TIMEOUT_SEC;
        while (microtime(true) < $deadline) {
            if (self::probeHealth($base, $needle)) {
                return;
            }
            usleep(200_000);
        }
        throw new \RuntimeException(
            $label . ' did not become ready on ' . $base . ' within '
            . self::STARTUP_TIMEOUT_SEC . 's'
        );
    }

    private static function probeHealth(string $base, string $needle): bool
    {
        $ch = curl_init($base . '/__mock__/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200 && is_string($body) && str_contains($body, $needle);
    }

    private static function probeHealthTls(string $base, string $caCert, string $needle): bool
    {
        $ch = curl_init($base . '/__mock__/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_CAINFO => $caCert,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200 && is_string($body) && str_contains($body, $needle);
    }

    private static function python(): string
    {
        foreach (['MOCK_RELAY_PYTHON', 'MOCK_SIGNALWIRE_PYTHON'] as $envVar) {
            $explicit = getenv($envVar);
            if (is_string($explicit) && $explicit !== '') {
                return $explicit;
            }
        }
        foreach (['python3', 'python'] as $candidate) {
            $out = shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($candidate)));
            if (is_string($out) && trim($out) !== '') {
                return trim($out);
            }
        }
        return 'python3';
    }
}
