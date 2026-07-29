<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Logging\Logger;
use SignalWire\Logging\LogLevel;

class LoggerTest extends TestCase
{
    protected function setUp(): void
    {
        Logger::reset();
        putenv('SIGNALWIRE_LOG_LEVEL');
        putenv('SIGNALWIRE_LOG_MODE');
    }

    protected function tearDown(): void
    {
        Logger::reset();
        putenv('SIGNALWIRE_LOG_LEVEL');
        putenv('SIGNALWIRE_LOG_MODE');
    }

    public function testGetLoggerReturnsInstance(): void
    {
        $logger = Logger::getLogger('test');
        // getLogger is a per-name singleton: the same name returns the same instance.
        $this->assertSame($logger, Logger::getLogger('test'));
    }

    public function testLoggerName(): void
    {
        $logger = Logger::getLogger('myapp');
        $this->assertSame('myapp', $logger->getName());
    }

    public function testDefaultName(): void
    {
        $logger = Logger::getLogger();
        $this->assertSame('signalwire', $logger->getName());
    }

    public function testSingletonBehavior(): void
    {
        $a = Logger::getLogger('test');
        $b = Logger::getLogger('test');
        $this->assertSame($a, $b);
    }

    public function testDifferentNamesDifferentInstances(): void
    {
        $a = Logger::getLogger('one');
        $b = Logger::getLogger('two');
        $this->assertNotSame($a, $b);
    }

    public function testDefaultLevel(): void
    {
        $logger = Logger::getLogger('test');
        $this->assertSame('info', $logger->getLevel());
    }

    public function testEnvLevel(): void
    {
        putenv('SIGNALWIRE_LOG_LEVEL=debug');
        Logger::reset();
        $logger = Logger::getLogger('test');
        $this->assertSame('debug', $logger->getLevel());
    }

    public function testEnvLevelCaseInsensitive(): void
    {
        putenv('SIGNALWIRE_LOG_LEVEL=WARN');
        Logger::reset();
        $logger = Logger::getLogger('test');
        $this->assertSame('warn', $logger->getLevel());
    }

    public function testInvalidEnvLevelFallsBackToInfo(): void
    {
        putenv('SIGNALWIRE_LOG_LEVEL=bogus');
        Logger::reset();
        $logger = Logger::getLogger('test');
        $this->assertSame('info', $logger->getLevel());
    }

    public function testSetLevel(): void
    {
        $logger = Logger::getLogger('test');
        $logger->setLevel('error');
        $this->assertSame('error', $logger->getLevel());
    }

    public function testSetLevelIgnoresInvalid(): void
    {
        $logger = Logger::getLogger('test');
        $logger->setLevel('error');
        $logger->setLevel('invalid');
        $this->assertSame('error', $logger->getLevel());
    }

    public function testNotSuppressedByDefault(): void
    {
        $logger = Logger::getLogger('test');
        $this->assertFalse($logger->isSuppressed());
    }

    public function testEnvSuppression(): void
    {
        putenv('SIGNALWIRE_LOG_MODE=off');
        Logger::reset();
        $logger = Logger::getLogger('test');
        $this->assertTrue($logger->isSuppressed());
    }

    public function testEnvSuppressionCaseInsensitive(): void
    {
        putenv('SIGNALWIRE_LOG_MODE=OFF');
        Logger::reset();
        $logger = Logger::getLogger('test');
        $this->assertTrue($logger->isSuppressed());
    }

    public function testSetSuppressed(): void
    {
        $logger = Logger::getLogger('test');
        $logger->setSuppressed(true);
        $this->assertTrue($logger->isSuppressed());
        $logger->setSuppressed(false);
        $this->assertFalse($logger->isSuppressed());
    }

    public function testShouldLogLevelFiltering(): void
    {
        $logger = Logger::getLogger('test');
        $logger->setLevel('warn');

        $this->assertFalse($logger->shouldLog('debug'));
        $this->assertFalse($logger->shouldLog('info'));
        $this->assertTrue($logger->shouldLog('warn'));
        $this->assertTrue($logger->shouldLog('error'));
    }

    public function testShouldLogDefaultLevel(): void
    {
        $logger = Logger::getLogger('test');
        // Default is info
        $this->assertFalse($logger->shouldLog('debug'));
        $this->assertTrue($logger->shouldLog('info'));
        $this->assertTrue($logger->shouldLog('warn'));
        $this->assertTrue($logger->shouldLog('error'));
    }

    public function testShouldLogDebugLevel(): void
    {
        $logger = Logger::getLogger('test');
        $logger->setLevel('debug');

        $this->assertTrue($logger->shouldLog('debug'));
        $this->assertTrue($logger->shouldLog('info'));
        $this->assertTrue($logger->shouldLog('warn'));
        $this->assertTrue($logger->shouldLog('error'));
    }

    public function testShouldLogErrorLevel(): void
    {
        $logger = Logger::getLogger('test');
        $logger->setLevel('error');

        $this->assertFalse($logger->shouldLog('debug'));
        $this->assertFalse($logger->shouldLog('info'));
        $this->assertFalse($logger->shouldLog('warn'));
        $this->assertTrue($logger->shouldLog('error'));
    }

    public function testSuppressedBlocksAll(): void
    {
        $logger = Logger::getLogger('test');
        $logger->setSuppressed(true);

        $this->assertFalse($logger->shouldLog('debug'));
        $this->assertFalse($logger->shouldLog('info'));
        $this->assertFalse($logger->shouldLog('warn'));
        $this->assertFalse($logger->shouldLog('error'));
    }

    public function testUnsuppressedResumesLogging(): void
    {
        $logger = Logger::getLogger('test');
        $logger->setSuppressed(true);
        $this->assertFalse($logger->shouldLog('error'));

        $logger->setSuppressed(false);
        $this->assertTrue($logger->shouldLog('error'));
    }

    public function testHasLogMethods(): void
    {
        $logger = Logger::getLogger('test');
        $logger->setSuppressed(true);
        // The four level methods exist and are invocable without error.
        $logger->debug('m');
        $logger->info('m');
        $logger->warn('m');
        $logger->error('m');
        $this->assertSame('test', $logger->getName());
    }

    public function testLogOutputFormat(): void
    {
        $logger = Logger::getLogger('testformat');
        $logger->setLevel('debug');

        // Capture stderr by spawning a child PHP process so we can
        // really inspect what fwrite(STDERR, ...) wrote — STDERR can't
        // be redirected from inside PHPUnit's own process.
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $script = <<<PHP
<?php
require '{$autoload}';
\$logger = \\SignalWire\\Logging\\Logger::getLogger('child');
\$logger->setLevel('debug');
\$logger->debug('msg-debug');
\$logger->info('msg-info');
\$logger->warn('msg-warn');
\$logger->error('msg-error');
PHP;
        $tmp = \tempnam(\sys_get_temp_dir(), 'sw_log_test_') . '.php';
        \file_put_contents($tmp, $script);
        try {
            // CLI mode: STDERR is defined, fwrite goes to the process stream.
            $cmd = \escapeshellcmd(PHP_BINARY) . ' ' . \escapeshellarg($tmp);
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $env = ['PHPUNIT_TEST_LOGGER' => '1'];
            $proc = \proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), $env);
            $this->assertIsResource($proc, 'Failed to spawn child PHP process');
            \fclose($pipes[0]);
            $stdout = \stream_get_contents($pipes[1]);
            $stderr = \stream_get_contents($pipes[2]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            \proc_close($proc);
            // Each level's content must appear, tagged with [LEVEL] and [child].
            $this->assertStringContainsString(
                '[DEBUG] [child] msg-debug',
                $stderr,
                'stderr was: ' . $stderr . ' / stdout was: ' . $stdout
            );
            $this->assertStringContainsString('[INFO] [child] msg-info', $stderr);
            $this->assertStringContainsString('[WARN] [child] msg-warn', $stderr);
            $this->assertStringContainsString('[ERROR] [child] msg-error', $stderr);
            // Body should be empty — Logger writes only to stderr.
            $this->assertSame('', $stdout, 'Logger leaked into stdout');
        } finally {
            @\unlink($tmp);
        }
    }

    /**
     * Regression test for the bare-STDERR bug: under php -S (the built-in
     * webserver SAPI), the global STDERR constant is NOT defined inside
     * request worker processes. A logger that writes via the bare name
     * `STDERR` from within `namespace SignalWire\Logging` triggers
     * "Undefined constant SignalWire\Logging\STDERR" and fatal-errors out
     * of every request — silently breaking the SWMLService HTTP server.
     *
     * The fix uses `\STDERR` and falls back to opening `php://stderr` so
     * the logger works in CLI, php -S, php-fpm, mod_php, etc. This test
     * stands up a one-shot `php -S` server, drives a logger from inside
     * a request, and asserts the response is intact instead of being
     * truncated by a fatal Logger crash.
     */
    public function testLoggerWorksUnderPhpBuiltinWebserver(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $script = <<<PHP
<?php
require '{$autoload}';
\$logger = \\SignalWire\\Logging\\Logger::getLogger('webtest');
\$logger->setLevel('debug');
\$logger->info('hit-from-php-S');
header('Content-Type: text/plain');
echo "OK\\n";
PHP;
        $tmp = \tempnam(\sys_get_temp_dir(), 'sw_log_phps_') . '.php';
        \file_put_contents($tmp, $script);

        // Bind ephemeral port.
        $sock = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($sock);
        \socket_bind($sock, '127.0.0.1', 0);
        \socket_getsockname($sock, $addr, $port);
        \socket_close($sock);

        $cmd = \escapeshellcmd(PHP_BINARY)
            . ' -S 127.0.0.1:' . (int) $port
            . ' ' . \escapeshellarg($tmp);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = \proc_open($cmd, $descriptors, $pipes);
        $this->assertIsResource($proc, 'Failed to start php -S');
        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        try {
            // Wait for bind.
            $ok = false;
            $deadline = \microtime(true) + 5.0;
            while (\microtime(true) < $deadline) {
                $err = 0;
                $errStr = '';
                $conn = @\fsockopen('127.0.0.1', $port, $err, $errStr, 0.2);
                if ($conn !== false) {
                    \fclose($conn);
                    $ok = true;
                    break;
                }
                \usleep(100_000);
            }
            $this->assertTrue($ok, "php -S did not bind to 127.0.0.1:{$port}");

            $ctx = \stream_context_create(['http' => ['timeout' => 5.0]]);
            $body = @\file_get_contents("http://127.0.0.1:{$port}/", false, $ctx);

            // Drain server stderr to surface useful diagnostics on failure.
            $serverStderr = (string) \stream_get_contents($pipes[2]);

            $this->assertNotFalse($body, 'Request to php -S failed');
            $this->assertStringContainsString(
                'OK',
                (string) $body,
                'Response truncated — Logger likely fatal-errored under php -S. '
                . 'Server stderr: ' . $serverStderr
            );
            $this->assertStringNotContainsString(
                'Undefined constant',
                (string) $body,
                'Logger leaked an Undefined-constant error into the response.'
            );
            $this->assertStringNotContainsString(
                'Undefined constant',
                $serverStderr,
                'Logger fatal-errored on the server side under php -S.'
            );
        } finally {
            \proc_terminate($proc, SIGTERM);
            \proc_close($proc);
            @\unlink($tmp);
        }
    }

    public function testResetClearsInstances(): void
    {
        $a = Logger::getLogger('test');
        Logger::reset();
        $b = Logger::getLogger('test');
        $this->assertNotSame($a, $b);
    }

    /**
     * setLevel()/shouldLog() accept the typed LogLevel enum and a bare string
     * interchangeably: both drive the identical level state and filtering. No
     * mocks — the assertions read the real configured level and the real
     * shouldLog() decision.
     */
    public function testSetLevelAcceptsLogLevelEnumOrString(): void
    {
        // Configure via the typed enum; getLevel() below confirms the enum's
        // backed value ('warn') is what the Logger keys on.
        $enumLogger = Logger::getLogger('enum-level');
        $enumLogger->setLevel(LogLevel::Warn);
        $this->assertSame('warn', $enumLogger->getLevel());
        // Real filtering: below-threshold suppressed, at/above emitted.
        $this->assertFalse($enumLogger->shouldLog(LogLevel::Info));
        $this->assertFalse($enumLogger->shouldLog('info'));          // string arg, same answer
        $this->assertTrue($enumLogger->shouldLog(LogLevel::Warn));
        $this->assertTrue($enumLogger->shouldLog('error'));

        // Parity: the bare string configures the identical state (Python uses str).
        $stringLogger = Logger::getLogger('string-level');
        $stringLogger->setLevel('warn');
        $this->assertSame(
            $enumLogger->getLevel(),
            $stringLogger->getLevel(),
            'enum and string setLevel must yield the same level',
        );
        $this->assertFalse($stringLogger->shouldLog(LogLevel::Info));
        $this->assertTrue($stringLogger->shouldLog(LogLevel::Warn));
    }

    /**
     * The control-char scrub must be ON THE EMISSION PATH, not merely available.
     *
     * `LoggingConfig::stripControlChars` shipped public and correct with ZERO call
     * sites, so a caller-supplied NUL or ESC-[ escape reached the terminal verbatim
     * and could forge log lines. A test that calls the scrub helper directly passes
     * even with the wiring deleted — the only assertion that can tell the difference
     * is one that reads what the logger ACTUALLY wrote.
     */
    public function testEmittedLineHasControlCharsStripped(): void
    {
        // The escapes are written for the CHILD's parser: this single-quoted string
        // carries a literal backslash-x-0-0, which becomes a real NUL when the child
        // compiles its own double-quoted literal. Asserted on the bytes below.
        $stderr = $this->runChildLogger('$logger->info("user said\x00\x1b[31mRED\x07");');

        $this->assertStringNotContainsString("\x00", $stderr, 'NUL survived into the emitted line');
        $this->assertStringNotContainsString("\x1b", $stderr, 'ESC survived into the emitted line');
        $this->assertStringNotContainsString("\x07", $stderr, 'BEL survived into the emitted line');
        // The ordinary space is legal and survives; only the control bytes are removed,
        // so the ESC-[ escape is defanged down to the visible text "[31mRED".
        $this->assertStringContainsString('user said[31mRED', $stderr);
    }

    /**
     * Tab/newline/CR are LEGAL in a log line and must survive — a scrub that ate
     * them would satisfy "no control chars" while mangling every multi-line message.
     */
    public function testEmittedLineKeepsLegalWhitespace(): void
    {
        $stderr = $this->runChildLogger('$logger->info("line1\tcol\nline2\r end");');

        $this->assertStringContainsString("line1\tcol\nline2\r end", $stderr);
    }

    /**
     * Drive the real Logger in a child PHP process and return its stderr.
     *
     * STDERR cannot be redirected from inside PHPUnit's own process (see
     * testLogOutputFormat), so the logger has to be exercised out-of-process for the
     * emitted bytes to be inspectable. Scratch files go in a repo-local dir, never a
     * shared global temp.
     */
    private function runChildLogger(string $body): string
    {
        $root = dirname(__DIR__);
        $autoload = $root . '/vendor/autoload.php';
        $script = <<<PHP
            <?php
            require '{$autoload}';
            \$logger = \\SignalWire\\Logging\\Logger::getLogger('inject.test');
            \$logger->setLevel('debug');
            {$body}
            PHP;

        $scratch = $root . '/.tmp';
        if (!\is_dir($scratch)) {
            \mkdir($scratch, 0o777, true);
        }
        $tmp = \tempnam($scratch, 'sw_scrub_') . '.php';
        \file_put_contents($tmp, $script);

        try {
            $cmd = \escapeshellcmd(PHP_BINARY) . ' ' . \escapeshellarg($tmp);
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = \proc_open($cmd, $descriptors, $pipes, $root, ['PHPUNIT_TEST_LOGGER' => '1']);
            $this->assertIsResource($proc, 'Failed to spawn child PHP process');
            \fclose($pipes[0]);
            $stdout = \stream_get_contents($pipes[1]);
            $stderr = \stream_get_contents($pipes[2]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            \proc_close($proc);

            $this->assertNotSame('', $stderr, 'child emitted nothing; stdout was: ' . $stdout);

            return $stderr;
        } finally {
            @\unlink($tmp);
        }
    }
}
