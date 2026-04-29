<?php

declare(strict_types=1);

namespace SignalWire\Logging;

class Logger
{
    private const LEVELS = [
        'debug' => 0,
        'info'  => 1,
        'warn'  => 2,
        'error' => 3,
    ];

    private static array $instances = [];

    private string $name;
    private string $level;
    private bool $suppressed;

    private function __construct(string $name)
    {
        $this->name = $name;

        $envLevel = getenv('SIGNALWIRE_LOG_LEVEL');
        $this->level = ($envLevel !== false && isset(self::LEVELS[strtolower($envLevel)]))
            ? strtolower($envLevel)
            : 'info';

        $envMode = getenv('SIGNALWIRE_LOG_MODE');
        $this->suppressed = ($envMode !== false && strtolower($envMode) === 'off');
    }

    public static function getLogger(string $name = 'signalwire'): self
    {
        if (!isset(self::$instances[$name])) {
            self::$instances[$name] = new self($name);
        }
        return self::$instances[$name];
    }

    /**
     * Reset all logger instances (for testing).
     */
    public static function reset(): void
    {
        self::$instances = [];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): void
    {
        $lower = strtolower($level);
        if (isset(self::LEVELS[$lower])) {
            $this->level = $lower;
        }
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed;
    }

    public function setSuppressed(bool $suppressed): void
    {
        $this->suppressed = $suppressed;
    }

    public function shouldLog(string $level): bool
    {
        if ($this->suppressed) {
            return false;
        }
        $levelNum = self::LEVELS[strtolower($level)] ?? 1;
        $currentNum = self::LEVELS[$this->level] ?? 1;
        return $levelNum >= $currentNum;
    }

    public function debug(string ...$messages): void
    {
        $this->log('debug', ...$messages);
    }

    public function info(string ...$messages): void
    {
        $this->log('info', ...$messages);
    }

    public function warn(string ...$messages): void
    {
        $this->log('warn', ...$messages);
    }

    public function error(string ...$messages): void
    {
        $this->log('error', ...$messages);
    }

    private function log(string $level, string ...$messages): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }
        $timestamp = date('Y-m-d H:i:s');
        $upperLevel = strtoupper($level);
        $message = implode(' ', $messages);
        $line = "[{$timestamp}] [{$upperLevel}] [{$this->name}] {$message}" . PHP_EOL;

        // Resolve a stderr handle that works in every SAPI:
        //   - CLI: the global \STDERR constant is defined.
        //   - php -S (built-in webserver) and most non-CLI SAPIs: \STDERR
        //     is NOT defined, so referencing the bare `STDERR` name from
        //     inside namespace SignalWire\Logging triggers
        //     "Undefined constant SignalWire\Logging\STDERR" and
        //     fatal-errors out of the request handler. Open the
        //     `php://stderr` stream as a fallback once and cache it.
        $handle = self::resolveStderr();
        if ($handle !== null) {
            fwrite($handle, $line);
        }
    }

    /**
     * @var resource|null Cached stderr stream, lazily opened.
     */
    private static $stderrHandle = null;

    /**
     * Return a writable stderr stream resource that works under any SAPI.
     *
     * Checks the global `\STDERR` constant first (defined in CLI, undefined
     * under `php -S`); falls back to opening `php://stderr` and caches the
     * result so each log call doesn't re-open the stream.
     *
     * @return resource|null
     */
    private static function resolveStderr()
    {
        if (\defined('\\STDERR')) {
            // Use the CLI-provided stream resource directly.
            return \STDERR;
        }
        if (self::$stderrHandle === null) {
            $h = @\fopen('php://stderr', 'w');
            self::$stderrHandle = ($h !== false) ? $h : null;
        }
        return self::$stderrHandle;
    }
}
