<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\TestCase;
use SignalWire\Relay\Call;
use SignalWire\Relay\Client as RelayClient;
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

    /** Process-cached dynamically picked ws/http ports (null until resolved). */
    private static ?int $dynamicWsPort = null;
    private static ?int $dynamicHttpPort = null;

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
        // Use a SCOPED client so this sentinel is parallel-safe: it neither
        // wipes the shared journal (a global reset would race a concurrent
        // test) nor asserts on the global journal (other sessions' frames are
        // in it). A fresh session's scoped journal contains only its own
        // connect frame.
        [$client, $mock] = self::scopedClient();
        try {
            $this->assertSame(self::resolveHttpPort(), $mock->httpPort());
            $this->assertStringStartsWith('ws://127.0.0.1:', $mock->wsUrl());
            $this->assertStringStartsWith('http://127.0.0.1:', $mock->httpUrl());
            $this->assertNotSame('', $mock->sessionId(), 'client captured a session id');
            // Scoped journal sees this session's own signalwire.connect.
            $this->assertNotEmpty($mock->journal()->recv('signalwire.connect'));
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Boot a freshly connected RelayClient pointed at the local mock server.
     * Resets the journal + scenarios before returning. The caller is
     * responsible for calling $client->disconnect() on teardown.
     *
     * Legacy/unscoped helper — kept for callers that drive the shared mock
     * serially (and resets the GLOBAL journal/scenarios). Parallel-safe tests
     * should use {@link scopedClient()} instead.
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
     * Boot a freshly connected RelayClient plus a per-test {@link RelayHarness}
     * scoped to THIS client's RELAY session, so the test's journal reads/resets
     * see only its own frames and scenario arming/pushes target only its own
     * session — making the shared mock safe under file/process parallelism.
     *
     * Isolation key: the server-assigned ``sessionid`` from the connect
     * handshake (RELAY's session key), captured by the SDK into
     * ``$client->sessionId``. The mock journals this connection's frames under
     * that id and scopes scenarios by it, so a scoped harness threads
     * ``?session_id=<id>`` onto every control-plane call.
     *
     * No global reset is needed: a brand-new session starts with an empty
     * (scoped) journal. The caller MUST call ``$client->disconnect()`` on
     * teardown.
     *
     * @param array<string,mixed> $extra Additional constructor options.
     * @return array{0: RelayClient, 1: RelayHarness}  [client, scopedHarness]
     */
    public static function scopedClient(array $extra = []): array
    {
        $shared = self::harness();
        $opts = array_merge(
            [
                'project'  => 'test_proj',
                'token'    => 'test_tok',
                'host'     => $shared->relayHost(),
                'scheme'   => 'ws',
                'contexts' => ['default'],
            ],
            $extra,
        );
        $client = new RelayClient($opts);
        $client->connect();

        // Per-call harness view scoped to THIS client's session id, so the
        // test only ever sees/disturbs its own frames + scenarios.
        $mock = new RelayHarness($shared->httpUrl(), $shared->wsPort(), $shared->httpPort());
        $mock->scopeTo((string) $client->sessionId);

        return [$client, $mock];
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

        // Probe with retries BEFORE deciding to spawn. Under heavy parallel
        // load (e.g. paratest with many workers), the single-threaded mock can
        // be slow to answer a health probe; a single short probe would falsely
        // conclude "not running" and spawn a REDUNDANT server on the same port
        // — which then can't bind and leaves WS/HTTP hitting inconsistent
        // instances. Retrying over a few seconds detects a busy-but-alive
        // server and reuses it, so under parallelism exactly one server is ever
        // used. (The run-ci.sh lifecycle pre-spawns one; this is the safety net
        // for any worker that probes while the server is saturated.)
        if (self::probeHealthWithRetries($base)) {
            self::$sharedHarness = new RelayHarness($base, $wsPort, $httpPort);
            return self::$sharedHarness;
        }

        // Serialize spawning across parallel paratest workers with a
        // cross-process file lock so AT MOST ONE worker ever spawns a server on
        // this port. After acquiring the lock we re-probe: another worker may
        // have just spawned it while we waited. We deliberately do NOT register
        // a per-worker shutdown hook to kill the server — under parallelism the
        // worker that spawned it usually exits first, and killing the shared
        // server would yank it out from under still-running workers. The mock
        // is reused across runs (probe-and-reuse) and torn down by the
        // run-ci.sh lifecycle trap; a leaked idle server between local runs is
        // harmless (the next run reuses it).
        $lock = @fopen(sys_get_temp_dir() . '/sw_mock_relay_' . $httpPort . '.lock', 'c');
        if ($lock !== false) {
            @flock($lock, LOCK_EX);
        }
        try {
            // Re-probe under the lock — another worker may have spawned it.
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
        } finally {
            if ($lock !== false) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
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
        // No env override: pick a free port dynamically. Cached for the life of
        // the process so every harness() call agrees on one ws port (and the
        // per-port lock file, keyed on the http port, stays consistent).
        if (self::$dynamicWsPort === null) {
            self::$dynamicWsPort = self::pickFreePort(self::DEFAULT_WS_PORT);
        }
        return self::$dynamicWsPort;
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
        if (self::$dynamicHttpPort === null) {
            self::$dynamicHttpPort = self::pickFreePort(self::DEFAULT_HTTP_PORT);
        }
        return self::$dynamicHttpPort;
    }

    /**
     * Ask the OS for a free TCP port by binding to :0 on loopback, reading
     * back the assigned port, then closing the socket. Falls back to the
     * supplied default if the bind fails (the subsequent spawn + health-poll
     * then fails loud rather than hanging silently).
     */
    private static function pickFreePort(int $fallback): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            return $fallback;
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        if (!is_string($name)) {
            return $fallback;
        }
        $pos = strrpos($name, ':');
        if ($pos === false) {
            return $fallback;
        }
        $port = (int) substr($name, $pos + 1);
        return $port > 0 ? $port : $fallback;
    }

    /**
     * Spawn `python -m mock_relay --ws-port <ws> --http-port <http> --log-level error`.
     * Stdout/stderr → /dev/null. Reuses the REST MockTest's adjacency walker
     * so we resolve porting-sdk/test_harness/mock_relay/ without prior
     * pip-install.
     */
    /** @return resource */
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
     * A generous timeout so a busy (but alive) single-threaded mock under
     * parallel load isn't mistaken for "down".
     *
     * @phpstan-impure network probe — result changes as the mock server comes up
     */
    private static function probeHealth(string $base): bool
    {
        $ch = curl_init($base . '/__mock__/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($body)) {
            return false;
        }
        return str_contains($body, '"schemas_loaded"');
    }

    /**
     * Probe repeatedly for up to ~10s before concluding the server is down.
     * Reuses a busy-but-alive server under parallel load so no worker ever
     * spawns a redundant second server on an occupied port.
     */
    private static function probeHealthWithRetries(string $base): bool
    {
        $deadline = microtime(true) + 10.0;
        do {
            if (self::probeHealth($base)) {
                return true;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);
        return false;
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

    /**
     * When set, journal reads/resets, scenario arming/reset, and the default
     * target of push()/inboundCall()/scenarioPlay() are scoped to this session
     * id (the server-assigned ``sessionid`` from the connect handshake), so a
     * test only ever sees/disturbs its own frames. Empty => global (legacy,
     * single-threaded). {@link MockTest::scopedClient()} sets this.
     */
    private string $sessionId = '';

    public function __construct(string $httpUrl, int $wsPort, int $httpPort)
    {
        $this->httpUrl = $httpUrl;
        $this->wsPort = $wsPort;
        $this->httpPort = $httpPort;
        $this->journal = new RelayJournal($httpUrl);
        $this->scenarios = new RelayScenarios($httpUrl);
    }

    /**
     * Scope this harness to one RELAY session (the connect-handshake
     * ``sessionid``). Threads ``?session_id=<id>`` onto every control-plane
     * call so the harness is safe to use concurrently with other tests against
     * the one shared mock.
     */
    public function scopeTo(string $sessionId): void
    {
        $this->sessionId = $sessionId;
        $this->journal->scopeTo($sessionId);
        $this->scenarios->scopeTo($sessionId);
    }

    /** The session id this harness is scoped to ('' when unscoped). */
    public function sessionId(): string
    {
        return $this->sessionId;
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
        // Default to this harness's session so a parallel test's client never
        // receives the push; an explicit arg overrides; an unscoped harness
        // with no arg broadcasts (legacy single-threaded behavior).
        $target = $sessionId ?? ($this->sessionId !== '' ? $this->sessionId : null);
        $url = $this->httpUrl . '/__mock__/push';
        if ($target !== null && $target !== '') {
            $url .= '?session_id=' . rawurlencode($target);
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
        // Target this harness's session by default so the inbound-call sequence
        // is delivered only to this test's client (an unscoped harness
        // broadcasts, as before). An explicit opts['session_id'] overrides.
        if ($this->sessionId !== '' && !array_key_exists('session_id', $opts)) {
            $opts['session_id'] = $this->sessionId;
        }
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
        // When scoped, stamp every push/expect_recv op with this session id
        // (unless the op already carries one), so the timeline targets only
        // this test's client and expect_recv matches only this session's
        // frames — making it parallel-safe.
        if ($this->sessionId !== '') {
            $ops = array_map(fn (array $op): array => $this->scopeOp($op), $ops);
        }
        $payload = json_encode($ops, JSON_THROW_ON_ERROR);
        $body = RelayMockHttp::postJson(
            $this->httpUrl . '/__mock__/scenario_play',
            $payload,
            timeoutSec: 30,
        );
        return self::decode($body);
    }

    /**
     * Inject this harness's session id into a timeline op's push/expect_recv
     * spec when the op doesn't already specify a session_id. Leaves sleep ops
     * untouched.
     *
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private function scopeOp(array $op): array
    {
        foreach (['push', 'expect_recv'] as $key) {
            $spec = $op[$key] ?? null;
            if (is_array($spec) && !array_key_exists('session_id', $spec)) {
                $spec['session_id'] = $this->sessionId;
                $op[$key] = $spec;
            }
        }
        return $op;
    }

    /**
     * Return active session metadata.
     *
     * @return array<array-key,mixed>
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
     * @return array<array-key,mixed>
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

    /**
     * When set, journal reads/resets are scoped to this session id (the
     * server-assigned ``sessionid``), so a test only ever sees its own frames.
     * Empty => global (legacy, single-threaded).
     */
    private string $sessionId = '';

    public function __construct(string $base)
    {
        $this->base = $base;
    }

    /** Scope journal reads/resets to one session id. */
    public function scopeTo(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /** ``?session_id=<id>`` suffix when scoped, else ''. */
    private function sessionQuery(): string
    {
        return $this->sessionId !== ''
            ? '?session_id=' . rawurlencode($this->sessionId)
            : '';
    }

    /**
     * Return every frame the mock recorded since the last reset, in
     * arrival order (server PoV: ``recv`` = frame from the SDK,
     * ``send`` = frame from the server back to the SDK). Scoped to this
     * session when set.
     *
     * @return list<RelayJournalEntry>
     */
    public function all(): array
    {
        $body = RelayMockHttp::get($this->base . '/__mock__/journal' . $this->sessionQuery());
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('mock_relay: journal body is not a JSON array');
        }
        $entries = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('mock_relay: journal row is not a JSON object');
            }
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
        RelayMockHttp::post($this->base . '/__mock__/journal/reset' . $this->sessionQuery());
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
    /** @var array<array-key,mixed> */
    public array $frame;
    public string $connectionId;
    public string $sessionId;
    public float $timestamp;

    private function __construct()
    {
    }

    /**
     * @param array<array-key,mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $e = new self();
        $e->direction = self::coerceString($raw['direction'] ?? '');
        $e->method = self::coerceString($raw['method'] ?? '');
        $e->requestId = self::coerceString($raw['request_id'] ?? '');
        $frame = $raw['frame'] ?? [];
        $e->frame = is_array($frame) ? $frame : [];
        $e->connectionId = self::coerceString($raw['connection_id'] ?? '');
        $e->sessionId = self::coerceString($raw['session_id'] ?? '');
        $e->timestamp = is_numeric($raw['timestamp'] ?? null) ? (float) $raw['timestamp'] : 0.0;
        return $e;
    }

    /** Coerce a scalar JSON value to string; non-scalars (arrays/objects) become ''. */
    private static function coerceString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Returns the inner ``params`` dict from a JSON-RPC frame, if present.
     *
     * @return array<array-key,mixed>|null
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

    /**
     * When set, scenarios are armed/reset under this session id (scenarios are
     * session-scoped on the mock), so a scoped harness arms only its own queue
     * and clears only its own — safe under parallel execution. Empty => global.
     */
    private string $sessionId = '';

    public function __construct(string $base)
    {
        $this->base = $base;
    }

    /** Scope scenario arming/reset to one session id. */
    public function scopeTo(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /** ``?session_id=<id>`` suffix when scoped, else ''. */
    private function sessionQuery(): string
    {
        return $this->sessionId !== ''
            ? '?session_id=' . rawurlencode($this->sessionId)
            : '';
    }

    /**
     * Queue scripted post-RPC events for the named method (FIFO consume-once).
     * Scoped to this harness's session when set.
     *
     * @param list<array<string,mixed>> $events
     */
    public function arm(string $method, array $events): void
    {
        $payload = json_encode($events, JSON_THROW_ON_ERROR);
        RelayMockHttp::postJson(
            $this->base . '/__mock__/scenarios/' . rawurlencode($method) . $this->sessionQuery(),
            $payload,
        );
    }

    /**
     * Queue a dial-dance scenario (winner state events + per-loser state
     * events + final dial event). Scoped to this harness's session when set.
     *
     * @param array<string,mixed> $body
     */
    public function armDial(array $body): void
    {
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        RelayMockHttp::postJson(
            $this->base . '/__mock__/scenarios/dial' . $this->sessionQuery(),
            $payload,
        );
    }

    public function reset(): void
    {
        RelayMockHttp::post($this->base . '/__mock__/scenarios/reset' . $this->sessionQuery());
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
