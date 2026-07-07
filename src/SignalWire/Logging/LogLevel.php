<?php

declare(strict_types=1);

namespace SignalWire\Logging;

/**
 * Logger severity levels as a typed, compile-time-checked closed set.
 *
 * {@see \SignalWire\Logging\Logger::setLevel()} and {@see Logger::shouldLog()}
 * accept this enum OR a string. The enum gives editor autocompletion and makes
 * a typo fail at the call site (a bare string like `'wrn'` only fails silently
 * at runtime — `setLevel()` ignores unknown levels); strings stays consistent with
 * the Python reference (which configures stdlib `logging` with bare names) and
 * remain available for callers reading a level out of config/env.
 *
 *     $logger->setLevel(LogLevel::Warn);   // typed, autocompleted
 *     $logger->setLevel('warn');           // string still works (for compatibility)
 *
 * The backing values are the exact lowercase strings the Logger keys on.
 */
enum LogLevel: string
{
    case Debug = 'debug';
    case Info  = 'info';
    case Warn  = 'warn';
    case Error = 'error';
}
