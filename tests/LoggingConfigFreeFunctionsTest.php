<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Logging\Logger;
use SignalWire\Logging\LoggingConfig;

/**
 * Behavioral parity tests for the LoggingConfig free-function hosts:
 * configureLogging / getLogger / resetLoggingConfiguration / stripControlChars.
 * These project to the Python module-level free functions in
 * signalwire.core.logging_config (get_logger / configure_logging / etc.).
 * Mirrors the Python reference and the TS oracle (Logger.ts).
 */
class LoggingConfigFreeFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        LoggingConfig::resetLoggingConfiguration();
    }

    protected function tearDown(): void
    {
        LoggingConfig::resetLoggingConfiguration();
    }

    public function testGetLoggerReturnsNamedLogger(): void
    {
        $logger = LoggingConfig::getLogger('my.component');
        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertSame('my.component', $logger->getName());
    }

    public function testConfigureLoggingIsIdempotent(): void
    {
        // First configure caches the Logger instance.
        LoggingConfig::configureLogging();
        $a = LoggingConfig::getLogger('same.name');
        // Second configure is a no-op (does not clear the cache).
        LoggingConfig::configureLogging();
        $b = LoggingConfig::getLogger('same.name');
        $this->assertSame($a, $b, 'idempotent configure must keep the cached logger');
    }

    public function testResetLoggingConfigurationClearsLoggerCache(): void
    {
        $a = LoggingConfig::getLogger('cached');
        LoggingConfig::resetLoggingConfiguration();
        $b = LoggingConfig::getLogger('cached');
        $this->assertNotSame($a, $b, 'reset must drop the cached logger so the next call rebuilds it');
    }

    public function testStripControlCharsRemovesControlCharacters(): void
    {
        $dirty = [
            'msg' => "hello\x00world\x1b[31m",
            'clean' => 'ok',
            'nested' => ['inner' => "a\x07b"],
            'num' => 42,
        ];
        $result = LoggingConfig::stripControlChars($dirty);

        $this->assertSame('helloworld[31m', $result['msg']);
        $this->assertSame('ok', $result['clean']);
        $this->assertSame('ab', $result['nested']['inner']);
        $this->assertSame(42, $result['num']);
    }

    public function testStripControlCharsPreservesNewlinesAndTabs(): void
    {
        // \n (\x0a), \r (\x0d) and \t (\x09) are NOT in the stripped set.
        $result = LoggingConfig::stripControlChars(['v' => "line1\nline2\tcol"]);
        $this->assertSame("line1\nline2\tcol", $result['v']);
    }
}
