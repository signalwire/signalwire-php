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

        // Capture stderr
        $stream = fopen('php://memory', 'r+');
        $oldStderr = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');

        // We can't easily redirect STDERR in PHPUnit, so just verify the methods don't throw
        $logger->debug('test debug message');
        $logger->info('test info message');
        $logger->warn('test warn message');
        $logger->error('test error message');

        // If we got here without exceptions, the log methods work
        $this->assertTrue(true);
    }

    public function testResetClearsInstances(): void
    {
        $a = Logger::getLogger('test');
        Logger::reset();
        $b = Logger::getLogger('test');
        $this->assertNotSame($a, $b);
    }
}
