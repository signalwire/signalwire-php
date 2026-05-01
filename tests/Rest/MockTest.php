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
    private const HTTP_TIMEOUT_SEC = 5;

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
        $h = self::harness();
        $h->reset();
        $this->assertSame(self::resolvePort(), $h->port());
        $this->assertStringStartsWith('http://127.0.0.1:', $h->url());
        // Hitting /__mock__/health via the harness returns at least one
        // entry of the mock's introspection — sanity-check journal/scenarios
        // are wired up.
        $this->assertSame([], $h->journal()->all());
    }

    /**
     * Return a freshly reset RestClient pointed at the local mock server.
     * Resets the journal + scenarios before returning.
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
                // Register a shutdown hook so the child is cleaned up when
                // PHPUnit exits.
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
        // Timed out — kill the child and give up.
        if (is_resource(self::$mockProcess)) {
            @proc_terminate(self::$mockProcess);
            @proc_close(self::$mockProcess);
            self::$mockProcess = null;
        }
        $err = new \RuntimeException(
            'MockTest: `python -m mock_signalwire` did not become ready within '
            . self::STARTUP_TIMEOUT_SEC . 's on port ' . $port
        );
        self::$startupFailure = $err;
        throw $err;
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
        return self::DEFAULT_PORT;
    }

    /**
     * Spawn `python -m mock_signalwire --host 127.0.0.1 --port <port> --log-level error`.
     * Returns the proc_open resource. Stdout/stderr are routed to /dev/null
     * to keep the child detached.
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
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => false]);
        if (!is_resource($proc)) {
            throw new \RuntimeException('proc_open failed for ' . implode(' ', $cmd));
        }
        return $proc;
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
        return str_contains($body, '"specs_loaded"');
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

    public function __construct(string $url, int $port)
    {
        $this->url = $url;
        $this->port = $port;
        $this->journal = new Journal($url);
        $this->scenarios = new Scenarios($url);
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
     * Clear journal + scenarios on the mock server.
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

    public function __construct(string $base)
    {
        $this->base = $base;
    }

    /**
     * Return every entry recorded since the last reset, in arrival order.
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
            $entries[] = JournalEntry::fromArray($row);
        }
        return $entries;
    }

    /**
     * Return the most recent journal entry. Throws when the journal is empty —
     * every test that exercises the SDK should produce at least one entry.
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

    public function reset(): void
    {
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

    private function __construct() {}

    /**
     * @param array<string,mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $e = new self();
        $e->method = (string) ($raw['method'] ?? '');
        $e->path = (string) ($raw['path'] ?? '');
        $qp = $raw['query_params'] ?? [];
        $e->queryParams = is_array($qp) ? $qp : [];
        $hdr = $raw['headers'] ?? [];
        $e->headers = is_array($hdr) ? $hdr : [];
        $e->body = $raw['body'] ?? null;
        $mr = $raw['matched_route'] ?? null;
        $e->matchedRoute = $mr === null ? null : (string) $mr;
        $rs = $raw['response_status'] ?? null;
        $e->responseStatus = $rs === null ? null : (int) $rs;
        $e->timestamp = isset($raw['timestamp']) ? (float) $raw['timestamp'] : 0.0;
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

    public function __construct(string $base)
    {
        $this->base = $base;
    }

    /**
     * Stage a response override for the named operation. The status + body
     * returned here will be served the next time the route is hit;
     * subsequent hits fall back to spec synthesis.
     *
     * @param array<string,mixed> $body
     */
    public function set(string $operationId, int $status, array $body): void
    {
        $payload = json_encode(
            ['status' => $status, 'response' => $body],
            JSON_THROW_ON_ERROR
        );
        MockHttp::postJson($this->base . '/__mock__/scenarios/' . rawurlencode($operationId), $payload);
    }

    public function reset(): void
    {
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
