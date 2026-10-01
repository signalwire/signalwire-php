<?php

declare(strict_types=1);

namespace SignalWire\Logging;

/**
 * Named, per-name-singleton logger writing one line per record to stderr.
 *
 * Instances are interned by name in {@see Logger::getLogger()} and the
 * constructor is private, so two calls with the same name yield the same
 * object — a level or suppression change is therefore visible to every holder
 * of that name. Initial state is read from the environment at first
 * construction: `SIGNALWIRE_LOG_LEVEL` (one of debug/info/warn/error,
 * case-insensitive; anything else falls back to `info`) and
 * `SIGNALWIRE_LOG_MODE=off` to start suppressed.
 *
 * Every message is passed through the shared control-character scrubber before
 * it is written — a log-injection defence, so a caller-supplied NUL or ANSI
 * escape cannot forge log lines. Output goes to stderr via a SAPI-agnostic
 * handle (`\STDERR` under CLI, an opened `php://stderr` elsewhere), and a
 * stream that cannot be opened silently drops the record rather than throwing.
 */
class Logger
{
    private const LEVELS = [
        'debug' => 0,
        'info'  => 1,
        'warn'  => 2,
        'error' => 3,
    ];

    /** @var array<string, self> */
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

    /** The name. */
    public function getName(): string
    {
        return $this->name;
    }

    /** The level. */
    public function getLevel(): string
    {
        return $this->level;
    }

    /**
     * @param LogLevel|string $level severity level — the typed {@see LogLevel}
     *   enum (typo-checked at the call site) or a bare string (matches the
     *   Python reference). Unknown string levels are ignored, as before.
     */
    public function setLevel(LogLevel|string $level): void
    {
        $lower = strtolower($level instanceof LogLevel ? $level->value : $level);
        if (isset(self::LEVELS[$lower])) {
            $this->level = $lower;
        }
    }

    /** Whether the suppressed. */
    public function isSuppressed(): bool
    {
        return $this->suppressed;
    }

    /**
     * Turn all output from this logger on or off, independent of the level.
     * Suppression short-circuits {@see Logger::shouldLog()}, so it beats any
     * level. Because loggers are interned by name, this affects every holder of
     * the same name.
     */
    public function setSuppressed(bool $suppressed): void
    {
        $this->suppressed = $suppressed;
    }

    /**
     * @param LogLevel|string $level severity to test — the typed
     *   {@see LogLevel} enum or a bare string (matches Python).
     */
    public function shouldLog(LogLevel|string $level): bool
    {
        if ($this->suppressed) {
            return false;
        }
        $name = strtolower($level instanceof LogLevel ? $level->value : $level);
        $levelNum = self::LEVELS[$name] ?? 1;
        $currentNum = self::LEVELS[$this->level] ?? 1;
        return $levelNum >= $currentNum;
    }

    /**
     * Log at the lowest severity. Variadic parts are joined with a single
     * space before scrubbing and emission.
     */
    public function debug(string ...$messages): void
    {
        $this->log('debug', ...$messages);
    }

    /** Log at `info` — the default severity when the environment sets none. */
    public function info(string ...$messages): void
    {
        $this->log('info', ...$messages);
    }

    /** Log at `warn`. */
    public function warn(string ...$messages): void
    {
        $this->log('warn', ...$messages);
    }

    /**
     * Log at the highest severity. Note this still writes to stderr like every
     * other level and never throws — it does not raise or exit.
     */
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
        // Scrub control characters BEFORE emitting — log-injection defence, and the
        // reason the reference registers strip_control_chars in both of its structlog
        // processor chains. A port that merely EXPOSES the scrub without putting it on
        // the emission path offers no protection at all: a caller-supplied NUL or an
        // ESC-[ escape reaches the terminal verbatim and can forge log lines.
        // Route through the reference's event-map contract rather than a second
        // public helper: a port-only `stripControlCharsValue` would be surface the
        // reference does not have, and the surface gate reports it as an invented
        // addition. One key in, one key out.
        $scrubbed = LoggingConfig::stripControlChars(['event' => $message])['event'];
        // The event-map contract is `array<mixed>` in and `array<mixed>` out, so the
        // value reads back as mixed. A string went in and scrubbing only ever swaps a
        // string for a string, so this narrowing always takes the first branch — it is
        // a type-checker proof obligation, not a cast that could hide a real mismatch.
        $safe = is_string($scrubbed) ? $scrubbed : $message;
        $line = "[{$timestamp}] [{$upperLevel}] [{$this->name}] {$safe}" . PHP_EOL;

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
