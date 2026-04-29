<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Logging\Logger;

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
        $this->assertInstanceOf(Logger::class, $logger);
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
        $this->assertTrue(method_exists($logger, 'debug'));
        $this->assertTrue(method_exists($logger, 'info'));
        $this->assertTrue(method_exists($logger, 'warn'));
        $this->assertTrue(method_exists($logger, 'error'));
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
}
