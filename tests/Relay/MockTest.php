<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\TestCase;
use SignalWire\Relay\Client as RelayClient;
use SignalWire\Relay\Call;
use SignalWire\Tests\Rest\MockTest as RestMockTest;

/**
 * MockTest is the PHP port of the porting-sdk mock_relay WebSocket-server
 * harness. It mirrors the REST port at tests/Rest/MockTest.php and the
 * Python conftest at tests/unit/relay/conftest.py.
 *
 * Lifecycle is per-process: the first MockTest::client() / MockTest::harness()
 * call probes http://127.0.0.1:<http_port>/__mock__/health and either
 * confirms a running mock_relay or starts one as a detached subprocess.
 *
 * Default ports are 8778 (WebSocket) and 9778 (HTTP control plane).
 * Override via the MOCK_RELAY_PORT / MOCK_RELAY_HTTP_PORT environment
 * variables.
 *
 * Hard constraints (see CLAUDE.md):
 *   - No PHPUnit MockBuilder of HTTP/WebSocket transport, no Guzzle MockHandler,
 *     no SDK-internal mocking. The mock server IS the test harness.
 *   - Real WebSocket connections via the SDK's PHP-streams transport.
 *   - Each test ends with both a behavioral assertion and a journal assertion.
 */
class MockTest extends TestCase
{
    public const DEFAULT_WS_PORT = 8778;
    public const DEFAULT_HTTP_PORT = 9778;
    private const STARTUP_TIMEOUT_SEC = 30;

    private static ?RelayHarness $sharedHarness = null;
    private static ?\Throwable $startupFailure = null;
    /** @var resource|null cached subprocess handle; null if we're reusing an external server. */
    private static $mockProcess = null;

    /**
     * Sentinel test — boots the mock server and confirms the harness is
     * usable. Exists so PHPUnit doesn't warn about an empty test class
     * when phpunit.xml has failOnWarning="true".
     */
    public function testHarnessIsReachable(): void
    {
        $h = self::harness();
        $h->reset();
        $this->assertSame(self::resolveHttpPort(), $h->httpPort());
        $this->assertStringStartsWith('ws://127.0.0.1:', $h->wsUrl());
        $this->assertStringStartsWith('http://127.0.0.1:', $h->httpUrl());
        // Hitting /__mock__/health via the harness returns at least one
        // entry of the mock's introspection — sanity-check journal/scenarios
        // are wired up.
        $this->assertSame([], $h->journal()->all());
    }

    /**
     * Boot a freshly connected RelayClient pointed at the local mock server.
     * Resets the journal + scenarios before returning. The caller is
     * responsible for calling $client->disconnect() on teardown.
     *
     * @param array<string,mixed> $extra Additional constructor options.
     */
    public static function client(array $extra = []): RelayClient
    {
        $h = self::harness();
        $h->reset();
        $opts = array_merge(
            [
                'project'  => 'test_proj',
                'token'    => 'test_tok',
                'host'     => $h->relayHost(),
                'scheme'   => 'ws',
                'contexts' => ['default'],
            ],
            $extra,
        );
        $client = new RelayClient($opts);
        $client->connect();
        return $client;
    }

    /**
     * Return the Harness (lazily booting the mock server). Tests that need
     * to inspect the journal or push scenarios should call this directly;
     * client() is a convenience wrapper that also resets state.
     */
    public static function harness(): RelayHarness
    {
        if (self::$sharedHarness !== null) {
            return self::$sharedHarness;
        }
        if (self::$startupFailure !== null) {
            throw new \RuntimeException(
                'MockTest: previous startup failed: ' . self::$startupFailure->getMessage(),
                0,
                self::$startupFailure
            );
        }
        $wsPort = self::resolveWsPort();
        $httpPort = self::resolveHttpPort();
        $base = 'http://127.0.0.1:' . $httpPort;

        if (self::probeHealth($base)) {
            self::$sharedHarness = new RelayHarness($base, $wsPort, $httpPort);
            return self::$sharedHarness;
        }

        try {
            self::$mockProcess = self::spawnMockServer($wsPort, $httpPort);
        } catch (\Throwable $e) {
            self::$startupFailure = $e;
            throw new \RuntimeException(
                'MockTest: failed to spawn `python -m mock_relay`: ' . $e->getMessage()
                . ' (set MOCK_RELAY_PORT / MOCK_RELAY_HTTP_PORT to use a pre-running instance)',
                0,
                $e
            );
        }

        $deadline = microtime(true) + self::STARTUP_TIMEOUT_SEC;
        while (microtime(true) < $deadline) {
            if (self::probeHealth($base)) {
                self::$sharedHarness = new RelayHarness($base, $wsPort, $httpPort);
                $proc = self::$mockProcess;
                register_shutdown_function(static function () use ($proc): void {
                    if (is_resource($proc)) {
                        @proc_terminate($proc);
                        @proc_close($proc);
                    }
                });
                return self::$sharedHarness;
            }
            usleep(150_000);
        }
        if (is_resource(self::$mockProcess)) {
            @proc_terminate(self::$mockProcess);
            @proc_close(self::$mockProcess);
            self::$mockProcess = null;
        }
        $err = new \RuntimeException(
            'MockTest: `python -m mock_relay` did not become ready within '
            . self::STARTUP_TIMEOUT_SEC . 's on http port ' . $httpPort
            . ' (clone porting-sdk next to signalwire-php so tests can find '
            . 'porting-sdk/test_harness/mock_relay/, or pip install the mock_relay package)'
        );
        self::$startupFailure = $err;
        throw $err;
    }

    /**
     * Convenience wrapper: push an inbound-call frame to the mock so the
     * SDK's on_call handler fires. Mirrors mock_relay's
     * /__mock__/inbound_call helper.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>  The decoded JSON response from the mock.
     */
    public static function inboundCall(array $opts = []): array
    {
        return self::harness()->inboundCall($opts);
    }

    /**
     * Convenience wrapper: synchronously pump the SDK's read loop until
     * either the predicate returns true or the deadline elapses. Matches
     * the Python tests' `await asyncio.wait_for(event.wait(), timeout=...)`
     * pattern within a synchronous PHP runtime.
     */
    public static function pumpUntil(RelayClient $client, callable $predicate, float $timeoutSec = 5.0): bool
    {
        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline) {
            if ($predicate()) {
                return true;
            }
            try {
                $client->readOnce();
            } catch (\RuntimeException $e) {
                // Surface the error so tests fail visibly rather than
                // silently spinning to deadline.
                throw $e;
            }
        }
        return $predicate();
    }

    /**
     * Pump the read loop for at least $millis milliseconds (drains pending
     * frames). Used when a test wants to assert something *did not* happen.
     */
    public static function pumpFor(RelayClient $client, int $millis): void
    {
        $deadline = microtime(true) + ($millis / 1000.0);
        while (microtime(true) < $deadline) {
            try {
                $client->readOnce();
            } catch (\RuntimeException) {
                return;
            }
        }
    }

    private static function resolveWsPort(): int
    {
        $raw = getenv('MOCK_RELAY_PORT');
        if ($raw !== false && trim($raw) !== '') {
            $p = (int) $raw;
            if ($p > 0) {
                return $p;
            }
        }
        return self::DEFAULT_WS_PORT;
    }

    private static function resolveHttpPort(): int
    {
        $raw = getenv('MOCK_RELAY_HTTP_PORT');
        if ($raw !== false && trim($raw) !== '') {
            $p = (int) $raw;
            if ($p > 0) {
                return $p;
            }
        }
        return self::DEFAULT_HTTP_PORT;
    }

    /**
     * Spawn `python -m mock_relay --ws-port <ws> --http-port <http> --log-level error`.
     * Stdout/stderr → /dev/null. Reuses the REST MockTest's adjacency walker
     * so we resolve porting-sdk/test_harness/mock_relay/ without prior
     * pip-install.
     */
    private static function spawnMockServer(int $wsPort, int $httpPort)
    {
        $python = self::resolvePython();
        $cmd = [
            $python,
            '-m', 'mock_relay',
            '--host', '127.0.0.1',
            '--ws-port', (string) $wsPort,
            '--http-port', (string) $httpPort,
            '--log-level', 'error',
        ];
        $pkgDir = RestMockTest::discoverPortingSdkPackage('mock_relay');
        $env = $_SERVER;
        $cleanEnv = [];
        foreach ($env as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $cleanEnv[$k] = (string) $v;
            }
        }
        if ($pkgDir !== null) {
            $sep = PATH_SEPARATOR;
            $existing = $cleanEnv['PYTHONPATH'] ?? '';
            $cleanEnv['PYTHONPATH'] = $existing !== '' ? ($pkgDir . $sep . $existing) : $pkgDir;
        }
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $cleanEnv, ['bypass_shell' => false]);
        if (!is_resource($proc)) {
            throw new \RuntimeException('proc_open failed for ' . implode(' ', $cmd));
        }
        return $proc;
    }

    /**
     * Locate the python executable. Prefers MOCK_RELAY_PYTHON, then
     * MOCK_SIGNALWIRE_PYTHON (so a single env var works across both REST
     * and Relay harnesses), then `python3`, then `python` from PATH.
     */
    private static function resolvePython(): string
    {
        foreach (['MOCK_RELAY_PYTHON', 'MOCK_SIGNALWIRE_PYTHON'] as $envVar) {
            $explicit = getenv($envVar);
            if (is_string($explicit) && $explicit !== '') {
                return $explicit;
            }
        }
        foreach (['python3', 'python'] as $candidate) {
            $cmd = sprintf('command -v %s 2>/dev/null', escapeshellarg($candidate));
            $output = shell_exec($cmd);
            if (is_string($output) && trim($output) !== '') {
                return trim($output);
            }
        }
        return 'python3';
    }

    /**
     * Probe /__mock__/health. Returns true on 200 + "schemas_loaded" in body.
     */
    private static function probeHealth(string $base): bool
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
        if ($code !== 200 || !is_string($body)) {
            return false;
        }
        return str_contains($body, '"schemas_loaded"');
    }
}

/**
 * Lightweight wrapper around the running mock-relay server. Exposes journal
 * accessors, scenario management, and the reset hook tests rely on.
 */
final class RelayHarness
{
    private string $httpUrl;
    private int $wsPort;
    private int $httpPort;
    private RelayJournal $journal;
    private RelayScenarios $scenarios;

    public function __construct(string $httpUrl, int $wsPort, int $httpPort)
    {
        $this->httpUrl = $httpUrl;
        $this->wsPort = $wsPort;
        $this->httpPort = $httpPort;
        $this->journal = new RelayJournal($httpUrl);
        $this->scenarios = new RelayScenarios($httpUrl);
    }

    public function httpUrl(): string
    {
        return $this->httpUrl;
    }

    public function wsUrl(): string
    {
        return 'ws://127.0.0.1:' . $this->wsPort;
    }

    public function relayHost(): string
    {
        return '127.0.0.1:' . $this->wsPort;
    }

    public function wsPort(): int
    {
        return $this->wsPort;
    }

    public function httpPort(): int
    {
        return $this->httpPort;
    }

    public function journal(): RelayJournal
    {
        return $this->journal;
    }

    public function scenarios(): RelayScenarios
    {
        return $this->scenarios;
    }

    /**
     * Push a JSON-RPC frame to one or every connected session.
     *
     * @param array<string,mixed> $frame  Full JSON-RPC frame.
     * @return array<string,mixed>  Decoded {sent_to:[...], count:N}.
     */
    public function push(array $frame, ?string $sessionId = null): array
    {
        $url = $this->httpUrl . '/__mock__/push';
        if ($sessionId !== null && $sessionId !== '') {
            $url .= '?session_id=' . rawurlencode($sessionId);
        }
        $payload = json_encode(['frame' => $frame], JSON_THROW_ON_ERROR);
        $body = RelayMockHttp::postJson($url, $payload);
        return self::decode($body);
    }

    /**
     * Inject an inbound-call announcement. Mirrors
     * `mock_relay /__mock__/inbound_call`.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public function inboundCall(array $opts = []): array
    {
        $payload = json_encode($opts, JSON_THROW_ON_ERROR);
        $body = RelayMockHttp::postJson($this->httpUrl . '/__mock__/inbound_call', $payload);
        return self::decode($body);
    }

    /**
     * Run a scripted timeline of pushes/sleeps/expect_recv on the server.
     *
     * @param list<array<string,mixed>> $ops
     * @return array<string,mixed>
     */
    public function scenarioPlay(array $ops): array
    {
        $payload = json_encode($ops, JSON_THROW_ON_ERROR);
        $body = RelayMockHttp::postJson(
            $this->httpUrl . '/__mock__/scenario_play',
            $payload,
            timeoutSec: 30,
        );
        return self::decode($body);
    }

    /**
     * Return active session metadata.
     *
     * @return list<array<string,mixed>>
     */
    public function sessions(): array
    {
        $body = RelayMockHttp::get($this->httpUrl . '/__mock__/sessions');
        $decoded = self::decode($body);
        $sessions = $decoded['sessions'] ?? [];
        return is_array($sessions) ? $sessions : [];
    }

    /**
     * Clear journal + scenario queues. Called between tests automatically.
     */
    public function reset(): void
    {
        $this->journal->reset();
        $this->scenarios->reset();
    }

    /**
     * @return array<string,mixed>
     */
    private static function decode(string $body): array
    {
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('mock_relay: HTTP body is not a JSON object');
        }
        return $decoded;
    }
}

/**
 * Read-only view of the mock-relay server's journal (every WebSocket frame,
 * since the last reset).
 */
final class RelayJournal
{
    private string $base;

    public function __construct(string $base)
    {
        $this->base = $base;
    }

    /**
     * Return every frame the mock recorded since the last reset, in
     * arrival order (server PoV: ``recv`` = frame from the SDK,
     * ``send`` = frame from the server back to the SDK).
     *
     * @return list<RelayJournalEntry>
     */
    public function all(): array
    {
        $body = RelayMockHttp::get($this->base . '/__mock__/journal');
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('mock_relay: journal body is not a JSON array');
        }
        $entries = [];
        foreach ($decoded as $row) {
            $entries[] = RelayJournalEntry::fromArray($row);
        }
        return $entries;
    }

    /**
     * Return the most recent journal entry (regardless of direction).
     * Throws when the journal is empty — every test that touches the SDK
     * should produce at least one journal entry (signalwire.connect).
     */
    public function last(): RelayJournalEntry
    {
        $entries = $this->all();
        if ($entries === []) {
            throw new \AssertionError(
                'mock_relay: journal is empty — the SDK did not reach the mock server'
            );
        }
        return $entries[count($entries) - 1];
    }

    /**
     * Return inbound (SDK→server) frames, optionally filtered by method.
     *
     * @return list<RelayJournalEntry>
     */
    public function recv(?string $method = null): array
    {
        $out = [];
        foreach ($this->all() as $e) {
            if ($e->direction !== 'recv') {
                continue;
            }
            if ($method !== null && $e->method !== $method) {
                continue;
            }
            $out[] = $e;
        }
        return $out;
    }

    /**
     * Return server→SDK frames, optionally filtered by inner event_type
     * (signalwire.event frames only).
     *
     * @return list<RelayJournalEntry>
     */
    public function send(?string $eventType = null): array
    {
        $out = [];
        foreach ($this->all() as $e) {
            if ($e->direction !== 'send') {
                continue;
            }
            if ($eventType === null) {
                $out[] = $e;
                continue;
            }
            $params = $e->frame['params'] ?? null;
            if (!is_array($params)) {
                continue;
            }
            if (
                ($e->frame['method'] ?? null) === 'signalwire.event'
                && ($params['event_type'] ?? null) === $eventType
            ) {
                $out[] = $e;
            }
        }
        return $out;
    }

    public function reset(): void
    {
        RelayMockHttp::post($this->base . '/__mock__/journal/reset');
    }
}

/**
 * Lightweight view of a single mock_relay journal frame.
 */
final class RelayJournalEntry
{
    public string $direction;
    public string $method;
    public string $requestId;
    /** @var array<string,mixed> */
    public array $frame;
    public string $connectionId;
    public string $sessionId;
    public float $timestamp;

    private function __construct() {}

    /**
     * @param array<string,mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $e = new self();
        $e->direction = (string) ($raw['direction'] ?? '');
        $e->method = (string) ($raw['method'] ?? '');
        $e->requestId = (string) ($raw['request_id'] ?? '');
        $frame = $raw['frame'] ?? [];
        $e->frame = is_array($frame) ? $frame : [];
        $e->connectionId = (string) ($raw['connection_id'] ?? '');
        $e->sessionId = (string) ($raw['session_id'] ?? '');
        $e->timestamp = isset($raw['timestamp']) ? (float) $raw['timestamp'] : 0.0;
        return $e;
    }

    /**
     * Returns the inner ``params`` dict from a JSON-RPC frame, if present.
     *
     * @return array<string,mixed>|null
     */
    public function params(): ?array
    {
        $p = $this->frame['params'] ?? null;
        return is_array($p) ? $p : null;
    }
}

/**
 * Push scenario overrides onto the mock_relay server.
 */
final class RelayScenarios
{
    private string $base;

    public function __construct(string $base)
    {
        $this->base = $base;
    }

    /**
     * Queue scripted post-RPC events for the named method (FIFO consume-once).
     *
     * @param list<array<string,mixed>> $events
     */
    public function arm(string $method, array $events): void
    {
        $payload = json_encode($events, JSON_THROW_ON_ERROR);
        RelayMockHttp::postJson(
            $this->base . '/__mock__/scenarios/' . rawurlencode($method),
            $payload,
        );
    }

    /**
     * Queue a dial-dance scenario (winner state events + per-loser state
     * events + final dial event).
     *
     * @param array<string,mixed> $body
     */
    public function armDial(array $body): void
    {
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        RelayMockHttp::postJson(
            $this->base . '/__mock__/scenarios/dial',
            $payload,
        );
    }

    public function reset(): void
    {
        RelayMockHttp::post($this->base . '/__mock__/scenarios/reset');
    }
}

/**
 * Detached PHP-CLI worker, used by tests that need a side process to
 * push frames back at the SDK while the main thread is blocked in
 * something synchronous (e.g. ``Client::dial``).
 *
 * Usage:
 *
 *   $w = AsyncWorker::launch($body_php_string, [$arg1, $arg2]);
 *   ... main thread does its thing ...
 *   $w->terminate();
 *
 * The worker source is wrapped with ``<?php`` automatically. Args are
 * appended to ``$argv`` in the child. Stdout/stderr are redirected to
 * /dev/null. The harness manages the proc_open handle so the subprocess
 * doesn't outlive the test.
 */
final class AsyncWorker
{
    /** @var resource|null */
    private $proc;
    private string $scriptPath;

    /**
     * @param list<string> $args
     */
    public static function launch(string $body, array $args = []): self
    {
        $tmp = tempnam(sys_get_temp_dir(), 'relay_worker_');
        if (!is_string($tmp)) {
            throw new \RuntimeException('AsyncWorker: tempnam failed');
        }
        $tmp .= '.php';
        file_put_contents($tmp, "<?php\n" . $body);

        $cmd = array_merge([PHP_BINARY, $tmp], $args);
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            @unlink($tmp);
            throw new \RuntimeException('AsyncWorker: proc_open failed');
        }
        $w = new self();
        $w->proc = $proc;
        $w->scriptPath = $tmp;
        return $w;
    }

    public function terminate(): void
    {
        if (is_resource($this->proc)) {
            @proc_terminate($this->proc);
            @proc_close($this->proc);
            $this->proc = null;
        }
        if ($this->scriptPath !== '' && file_exists($this->scriptPath)) {
            @unlink($this->scriptPath);
            $this->scriptPath = '';
        }
    }

    public function __destruct()
    {
        $this->terminate();
    }
}

/**
 * Tiny cURL helper for talking to the mock-relay control endpoints.
 *
 * @internal
 */
final class RelayMockHttp
{
    public static function get(string $url, int $timeoutSec = 5): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) {
            throw new \RuntimeException("mock_relay: GET $url failed: $err");
        }
        if ($code !== 200) {
            throw new \RuntimeException("mock_relay: GET $url returned $code (body=$body)");
        }
        return $body;
    }

    public static function post(string $url, int $timeoutSec = 5): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) {
            throw new \RuntimeException("mock_relay: POST $url failed: $err");
        }
        if ((int) ($code / 100) !== 2) {
            throw new \RuntimeException("mock_relay: POST $url returned $code (body=$body)");
        }
    }

    public static function postJson(string $url, string $payload, int $timeoutSec = 5): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) {
            throw new \RuntimeException("mock_relay: POST $url failed: $err");
        }
        if ((int) ($code / 100) !== 2) {
            throw new \RuntimeException("mock_relay: POST $url returned $code (body=$body)");
        }
        return $body;
    }
}
