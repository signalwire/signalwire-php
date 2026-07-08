<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use SignalWire\REST\RestClient;

/**
 * MockTest is the PHP port of the porting-sdk mock_signalwire HTTP-server
 * harness. It mirrors the Go pilot at
 * signalwire-go/pkg/rest/internal/mocktest/mocktest.go and the Java port at
 * signalwire-java/src/test/java/com/signalwire/sdk/rest/MockTest.java.
 *
 * Lifecycle is per-process: the first MockTest::client() / MockTest::harness()
 * call probes http://127.0.0.1:<port>/__mock__/health and either confirms a
 * running server or starts one as a detached subprocess. Each test should
 * call MockTest::harness()->reset() at the top of each test (or use the
 * MockTestCase base class which handles it automatically).
 *
 * Default port is 8768 (PHP's slot in the parallel rollout); override via
 * the MOCK_SIGNALWIRE_PORT environment variable.
 *
 * Hard constraints:
 *   - No Guzzle MockHandler, no PHPUnit MockBuilder of the HTTP transport,
 *     no SDK-internal mocking. The mock server IS the test harness.
 *   - Each test ends with both a response assertion and a journal assertion.
 */
/**
 * Note: MockTest extends ``TestCase`` only so PHPUnit doesn't emit a runner
 * warning when the class is loaded (the file lives in ``tests/Rest/`` and
 * its name ends in ``Test.php``, which triggers PHPUnit's auto-discovery).
 * The class is otherwise a static helper — it carries one trivial sentinel
 * test (``testHarnessIsReachable``) so phpunit's failOnWarning="true" config
 * is satisfied.
 */
class MockTest extends TestCase
{
    public const DEFAULT_PORT = 8768;
    private const STARTUP_TIMEOUT_SEC = 30;

    /** Process-cached dynamically picked port (null until first resolved). */
    private static ?int $dynamicPort = null;

    private static ?Harness $sharedHarness = null;
    private static ?\Throwable $startupFailure = null;
    /** @var resource|null cached subprocess handle; null if we're reusing an external server. */
    private static $mockProcess = null;

    /**
     * Sentinel test — boots the mock server and asserts the harness is
     * usable. Exists so PHPUnit doesn't warn about an empty test class.
     */
    public function testHarnessIsReachable(): void
    {
        // Use a SCOPED harness so this sentinel is parallel-safe: it neither
        // wipes the shared journal (a global reset would race a concurrent
        // test) nor asserts on the global journal (other workers' requests
        // are in it). A brand-new scope starts empty by construction.
        [$client, $mock, $project] = self::scopedClient();
        $this->assertSame(self::resolvePort(), $mock->port());
        $this->assertStringStartsWith('http://127.0.0.1:', $mock->url());
        $this->assertStringStartsWith('test_proj_', $project);
        // This client made no request yet, so its auth-scoped journal view is
        // empty — proving journal scoping is wired without touching shared state.
        $this->assertSame([], $mock->journal()->all());
        // A scoped reset is a no-op on the shared journal (verified: it returns
        // without HTTP), so calling it can't disturb a concurrent test.
        $mock->reset();
        $this->assertSame([], $mock->journal()->all());
    }

    /**
     * Return a freshly reset RestClient pointed at the local mock server.
     * Resets the journal + scenarios before returning.
     *
     * Legacy/unscoped helper — kept for callers that drive the shared mock
     * serially. Parallel-safe tests should use {@link scopedClient()} instead.
     */
    public static function client(): RestClient
    {
        $h = self::harness();
        $h->reset();
        return new RestClient(
            'test_proj',
            'test_tok',
            $h->url()
        );
    }

    /**
     * Build a RestClient plus a per-test {@link Harness} scoped to THIS
     * client's requests, so the test reads only its own journal entries and
     * consumes only its own scenario overrides — making the shared mock safe
     * under file/process parallelism with no SDK change and no mock change.
     *
     * Isolation key: each client gets a unique RANDOM project
     * (``test_proj_<12 hex>``), so its
     * ``Authorization: Basic base64(project:token)`` header is unique. The
     * random suffix (not a counter) keeps it collision-free across paratest
     * workers AND separate processes hitting one shared mock. The harness
     * filters the shared global journal by that header (client-side) and the
     * mock_signalwire scenario store scopes overrides by it (server-side).
     *
     * Tests that assert on the AccountSid in a LAML path must read it from
     * ``$mock->project()`` rather than hard-coding ``test_proj``.
     *
     * @return array{0: RestClient, 1: Harness, 2: string}  [client, mock, project]
     */
    public static function scopedClient(): array
    {
        $h = self::harness();
        // Unique per-test project => unique Basic-Auth header => journal
        // filterable per client. Random (not a counter) so concurrent
        // workers/processes can't collide on the same project name.
        $project = 'test_proj_' . bin2hex(random_bytes(6));
        $token = 'test_tok';
        $client = new RestClient($project, $token, $h->url());

        // Per-call harness view scoped to this client's auth header. No reset
        // is needed: this client starts with zero entries in the (auth-filtered)
        // view, and its scenario overrides live under its own header.
        $authHeader = 'Basic ' . base64_encode($project . ':' . $token);
        $mock = new Harness($h->url(), $h->port());
        $mock->scopeTo($authHeader, $project);

        return [$client, $mock, $project];
    }

    /**
     * Return the Harness (lazily booting the mock server). Tests that need
     * to inspect the journal or push scenarios should call this directly;
     * client() is a convenience wrapper that also resets state.
     */
    public static function harness(): Harness
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
        $port = self::resolvePort();
        $base = 'http://127.0.0.1:' . $port;

        // Probe with retries BEFORE deciding to spawn. Under heavy parallel
        // load (paratest with many workers) the single-threaded mock can be
        // slow to answer a health probe; a single short probe would falsely
        // conclude "not running" and spawn a REDUNDANT server on the same
        // port, leaving requests hitting inconsistent instances. Retrying over
        // a few seconds reuses a busy-but-alive server so exactly one server is
        // ever used under parallelism.
        if (self::probeHealthWithRetries($base)) {
            self::$sharedHarness = new Harness($base, $port);
            return self::$sharedHarness;
        }

        // Serialize spawning across parallel paratest workers with a
        // cross-process file lock so AT MOST ONE worker ever spawns a server on
        // this port. After acquiring the lock we re-probe: another worker may
        // have spawned it while we waited. We deliberately do NOT register a
        // per-worker shutdown hook to kill the server — under parallelism the
        // worker that spawned it usually exits first, and killing the shared
        // server would yank it out from under still-running workers. The mock
        // is reused across runs (probe-and-reuse) and torn down by the
        // run-ci.sh lifecycle trap; a leaked idle server between local runs is
        // harmless (the next run reuses it).
        $lock = @fopen(sys_get_temp_dir() . '/sw_mock_signalwire_' . $port . '.lock', 'c');
        if ($lock !== false) {
            @flock($lock, LOCK_EX);
        }
        try {
            // Re-probe under the lock — another worker may have spawned it.
            if (self::probeHealth($base)) {
                self::$sharedHarness = new Harness($base, $port);
                return self::$sharedHarness;
            }
            // Spawn a subprocess. Detach stdout/stderr to /dev/null so PHPUnit
            // doesn't hang waiting on the child's pipes.
            try {
                self::$mockProcess = self::spawnMockServer($port);
            } catch (\Throwable $e) {
                self::$startupFailure = $e;
                throw new \RuntimeException(
                    'MockTest: failed to spawn `python -m mock_signalwire`: ' . $e->getMessage()
                    . ' (set MOCK_SIGNALWIRE_PORT to use a pre-running instance)',
                    0,
                    $e
                );
            }

            $deadline = microtime(true) + self::STARTUP_TIMEOUT_SEC;
            while (microtime(true) < $deadline) {
                if (self::probeHealth($base)) {
                    self::$sharedHarness = new Harness($base, $port);
                    return self::$sharedHarness;
                }
                usleep(150_000);
            }
            // Timed out — kill the child and give up.
            if (is_resource(self::$mockProcess)) {
                @proc_terminate(self::$mockProcess);
                @proc_close(self::$mockProcess);
                self::$mockProcess = null;
            }
            $err = new \RuntimeException(
                'MockTest: `python -m mock_signalwire` did not become ready within '
                . self::STARTUP_TIMEOUT_SEC . 's on port ' . $port
                . ' (clone porting-sdk next to signalwire-php so tests can find '
                . 'porting-sdk/test_harness/mock_signalwire/, or pip install the mock_signalwire package)'
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

    private static function resolvePort(): int
    {
        $raw = getenv('MOCK_SIGNALWIRE_PORT');
        if ($raw !== false && trim($raw) !== '') {
            $p = (int) $raw;
            if ($p > 0) {
                return $p;
            }
        }
        // No env override: pick a free port dynamically so concurrent runs
        // never collide on a fixed default. Cached for the life of the process
        // so every harness() call in this worker agrees on one port (and the
        // per-port lock file stays consistent across the probe/spawn dance).
        if (self::$dynamicPort === null) {
            self::$dynamicPort = self::pickFreePort(self::DEFAULT_PORT);
        }
        return self::$dynamicPort;
    }

    /**
     * Ask the OS for a free TCP port by binding to :0 on loopback, reading
     * back the assigned port, then closing the socket. Falls back to the
     * supplied default if the bind fails for any reason (the subsequent
     * spawn + health-poll then fails loud rather than hanging silently).
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
     * Spawn `python -m mock_signalwire --host 127.0.0.1 --port <port> --log-level error`.
     * Returns the proc_open resource. Stdout/stderr are routed to /dev/null
     * to keep the child detached. Injects PYTHONPATH so `python -m
     * mock_signalwire` resolves without a prior pip-install: the only
     * contract is that porting-sdk lives next to signalwire-php in ~/src/.
     *
     * @return resource The proc_open process handle.
     */
    private static function spawnMockServer(int $port)
    {
        $python = self::resolvePython();
        $cmd = [
            $python,
            '-m', 'mock_signalwire',
            '--host', '127.0.0.1',
            '--port', (string) $port,
            '--log-level', 'error',
        ];
        // Try to inject porting-sdk/test_harness/mock_signalwire/ into
        // PYTHONPATH so `python -m mock_signalwire` resolves without a
        // prior `pip install -e ...`. Adjacency contract: porting-sdk next
        // to signalwire-php in ~/src/. When the walk fails (e.g. porting-sdk
        // is not adjacent), we still spawn — the child falls back to
        // whatever is on the system Python's sys.path, and the readiness
        // probe surfaces a clear timeout error if neither mode is available.
        $pkgDir = self::discoverPortingSdkPackage('mock_signalwire');
        $env = $_SERVER;
        // Filter to scalar values so proc_open's env doesn't choke on arrays.
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
     * Walk this file's directory upward looking for an adjacent
     * porting-sdk/test_harness/<name>/<name>/__init__.py.
     *
     * Returns the absolute path to the directory containing the Python
     * package (the value to put on PYTHONPATH so that `python -m <name>`
     * resolves), or null when no adjacent porting-sdk is reachable.
     */
    public static function discoverPortingSdkPackage(string $name): ?string
    {
        $dir = __DIR__;
        while (true) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $candidate = $parent . DIRECTORY_SEPARATOR . 'porting-sdk'
                . DIRECTORY_SEPARATOR . 'test_harness'
                . DIRECTORY_SEPARATOR . $name;
            $init = $candidate . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . '__init__.py';
            if (is_file($init)) {
                return $candidate;
            }
            $dir = $parent;
        }
    }

    /**
     * Locate the python executable. Prefers MOCK_SIGNALWIRE_PYTHON, then
     * `python3`, then `python` from PATH.
     */
    private static function resolvePython(): string
    {
        $explicit = getenv('MOCK_SIGNALWIRE_PYTHON');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
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
     * Probe /__mock__/health. Returns true on 200 + "specs_loaded" in body.
     *
     * @phpstan-impure Performs a live HTTP request; result varies per call as
     *   the spawned mock server transitions from not-ready to ready.
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
        return str_contains($body, '"specs_loaded"');
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
 * Lightweight wrapper around the running mock server. Exposes journal
 * accessors, scenario management, and the reset hook tests rely on.
 */
final class Harness
{
    private string $url;
    private int $port;
    private Journal $journal;
    private Scenarios $scenarios;

    /**
     * The unique random project this harness's client authenticates with
     * (``test_proj_<hex>``). Tests that assert on the AccountSid embedded in
     * a LAML path read it from here instead of hard-coding ``test_proj``.
     * Empty on an unscoped/raw harness.
     */
    private string $project = '';

    public function __construct(string $url, int $port)
    {
        $this->url = $url;
        $this->port = $port;
        $this->journal = new Journal($url);
        $this->scenarios = new Scenarios($url);
    }

    /**
     * Scope this harness to one client, identified by its ``Authorization``
     * header. After scoping: journal()/last() return only that client's
     * requests, reset() becomes a no-op (the shared journal is never wiped —
     * a global wipe would race a concurrent test), and scenario overrides are
     * tagged with the header so a concurrent test can't consume them. REST is
     * pure request/response with no handshake, so the auth header is the
     * session key.
     */
    public function scopeTo(string $authHeader, string $project): void
    {
        $this->project = $project;
        $this->journal->scopeTo($authHeader);
        $this->scenarios->scopeTo($authHeader);
    }

    /** The unique random project for a scoped harness ('' when unscoped). */
    public function project(): string
    {
        return $this->project;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function journal(): Journal
    {
        return $this->journal;
    }

    public function scenarios(): Scenarios
    {
        return $this->scenarios;
    }

    /**
     * Clear journal + scenarios on the mock server. A scoped harness leaves
     * the shared journal alone (it only ever reads its own entries, identified
     * by auth header, so there is nothing to clear and a global wipe would
     * race a concurrent test). Unscoped harnesses do the legacy global reset.
     */
    public function reset(): void
    {
        $this->journal->reset();
        $this->scenarios->reset();
    }
}

/**
 * Read-only view of the mock server's journal (every recorded request,
 * since the last reset).
 */
final class Journal
{
    private string $base;

    /**
     * When set, all()/last() return only the requests THIS test's client made,
     * identified by its ``Authorization`` header (Basic project:token, with a
     * per-test random project). Empty => unscoped (legacy view; returns every
     * entry — only correct under serial execution).
     */
    private string $authHeader = '';

    public function __construct(string $base)
    {
        $this->base = $base;
    }

    /** Scope journal reads to one client's Authorization header. */
    public function scopeTo(string $authHeader): void
    {
        $this->authHeader = $authHeader;
    }

    /**
     * Return every entry recorded since the last reset, in arrival order.
     * Scoped to this harness's auth header when set (so a parallel test never
     * sees another test's requests); unscoped harnesses see the whole journal.
     *
     * @return list<JournalEntry>
     */
    public function all(): array
    {
        $body = MockHttp::get($this->base . '/__mock__/journal');
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('MockTest: journal body is not a JSON array');
        }
        $entries = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            /** @var array<string,mixed> $row */
            $entry = JournalEntry::fromArray($row);
            if ($this->authHeader !== ''
                && ($entry->headers['authorization'] ?? null) !== $this->authHeader) {
                continue;
            }
            $entries[] = $entry;
        }
        return $entries;
    }

    /**
     * Return the most recent journal entry for THIS client. Throws when the
     * (scoped) journal is empty — every test that exercises the SDK should
     * produce at least one entry.
     */
    public function last(): JournalEntry
    {
        $entries = $this->all();
        if ($entries === []) {
            throw new \AssertionError(
                'MockTest: journal is empty — the SDK call did not reach the mock server'
            );
        }
        return $entries[count($entries) - 1];
    }

    /**
     * Clear the journal on the mock server. A scoped journal leaves the shared
     * journal alone (it only ever reads its own entries, identified by auth
     * header, so there is nothing to clear and a global wipe would race a
     * concurrent test). Unscoped journals do the legacy global reset.
     */
    public function reset(): void
    {
        if ($this->authHeader !== '') {
            return;
        }
        MockHttp::post($this->base . '/__mock__/journal/reset');
    }
}

/**
 * Lightweight view of a request the mock server recorded. Mirrors the
 * dataclass in mock_signalwire.journal.JournalEntry.
 */
final class JournalEntry
{
    public string $method;
    public string $path;
    /** @var array<string, list<string>> */
    public array $queryParams;
    /** @var array<string, string> */
    public array $headers;
    /** @var array<string, mixed>|string|null */
    public $body;
    public ?string $matchedRoute;
    public ?int $responseStatus;
    public float $timestamp;

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $e = new self();
        $method = $raw['method'] ?? '';
        $e->method = is_scalar($method) ? (string) $method : '';
        $path = $raw['path'] ?? '';
        $e->path = is_scalar($path) ? (string) $path : '';

        $qp = $raw['query_params'] ?? [];
        $queryParams = [];
        if (is_array($qp)) {
            foreach ($qp as $k => $v) {
                if (!is_string($k) || !is_array($v)) {
                    continue;
                }
                $values = [];
                foreach ($v as $item) {
                    $values[] = is_scalar($item) ? (string) $item : '';
                }
                $queryParams[$k] = $values;
            }
        }
        $e->queryParams = $queryParams;

        $hdr = $raw['headers'] ?? [];
        $headers = [];
        if (is_array($hdr)) {
            foreach ($hdr as $k => $v) {
                if (is_string($k) && is_scalar($v)) {
                    $headers[$k] = (string) $v;
                }
            }
        }
        $e->headers = $headers;

        $body = $raw['body'] ?? null;
        if (is_array($body)) {
            /** @var array<string,mixed> $body */
            $e->body = $body;
        } elseif (is_string($body)) {
            $e->body = $body;
        } else {
            $e->body = null;
        }

        $mr = $raw['matched_route'] ?? null;
        $e->matchedRoute = is_scalar($mr) ? (string) $mr : null;
        $rs = $raw['response_status'] ?? null;
        $e->responseStatus = is_numeric($rs) ? (int) $rs : null;
        $ts = $raw['timestamp'] ?? null;
        $e->timestamp = is_numeric($ts) ? (float) $ts : 0.0;
        return $e;
    }

    /**
     * Returns the request body coerced to an associative array, or null when
     * the body is not a JSON object (e.g. empty or non-JSON content).
     *
     * @return array<string,mixed>|null
     */
    public function bodyMap(): ?array
    {
        if (is_array($this->body) && (count($this->body) === 0 || self::isAssoc($this->body))) {
            /** @var array<string,mixed> */
            return $this->body;
        }
        return null;
    }

    /**
     * @param array<int|string,mixed> $arr
     */
    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}

/**
 * Push one-shot response overrides onto the mock server.
 */
final class Scenarios
{
    private string $base;

    /**
     * When set, scenario overrides are scoped to THIS client's auth header
     * (REST's session key), so a concurrent test can't consume them and a
     * stale one can't bleed across tests. Empty => shared/unscoped bucket.
     */
    private string $authHeader = '';

    public function __construct(string $base)
    {
        $this->base = $base;
    }

    /** Scope scenario overrides to one client's Authorization header. */
    public function scopeTo(string $authHeader): void
    {
        $this->authHeader = $authHeader;
    }

    /**
     * Stage a response override for the named operation. The status + body
     * returned here will be served the next time the route is hit;
     * subsequent hits fall back to spec synthesis. Scoped to this harness's
     * auth header (server-side, via ``?session_id=``) when set.
     *
     * @param array<string,mixed> $body
     */
    public function set(string $operationId, int $status, array $body): void
    {
        $payload = json_encode(
            ['status' => $status, 'response' => $body],
            JSON_THROW_ON_ERROR
        );
        $q = $this->authHeader !== ''
            ? '?session_id=' . rawurlencode($this->authHeader)
            : '';
        MockHttp::postJson(
            $this->base . '/__mock__/scenarios/' . rawurlencode($operationId) . $q,
            $payload
        );
    }

    /**
     * Clear scenario overrides. A scoped harness clears only its own bucket
     * (server-side, via ``?session_id=``) so it never disturbs a concurrent
     * test; unscoped harnesses clear everything (legacy global reset).
     */
    public function reset(): void
    {
        if ($this->authHeader !== '') {
            MockHttp::post(
                $this->base . '/__mock__/scenarios/reset?session_id='
                . rawurlencode($this->authHeader)
            );
            return;
        }
        MockHttp::post($this->base . '/__mock__/scenarios/reset');
    }
}

/**
 * Tiny cURL helper for talking to the mock control endpoints. Kept private
 * to this namespace so test files don't accidentally bypass the SDK and
 * call the mock directly for the request-under-test.
 *
 * @internal
 */
final class MockHttp
{
    public static function get(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) {
            throw new \RuntimeException("MockTest: GET $url failed: $err");
        }
        if ($code !== 200) {
            throw new \RuntimeException("MockTest: GET $url returned $code (body=$body)");
        }
        return $body;
    }

    public static function post(string $url): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) {
            throw new \RuntimeException("MockTest: POST $url failed: $err");
        }
        if ((int) ($code / 100) !== 2) {
            throw new \RuntimeException("MockTest: POST $url returned $code (body=$body)");
        }
    }

    public static function postJson(string $url, string $payload): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) {
            throw new \RuntimeException("MockTest: POST $url failed: $err");
        }
        if ((int) ($code / 100) !== 2) {
            throw new \RuntimeException("MockTest: POST $url returned $code (body=$body)");
        }
    }
}
