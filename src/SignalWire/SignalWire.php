<?php

declare(strict_types=1);

namespace SignalWire;

use SignalWire\Logging\Logger;

final class SignalWire
{
    public const VERSION = '1.0.0';

    /**
     * Get a logger instance.
     */
    public static function getLogger(string $name = 'signalwire'): Logger
    {
        return Logger::getLogger($name);
    }
}
