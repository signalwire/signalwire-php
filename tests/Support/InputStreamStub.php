<?php

declare(strict_types=1);

namespace SignalWire\Tests\Support;

/**
 * Makes `php://input` readable under the CLI SAPI so the CGI and Google Cloud
 * Function serverless transports can be driven with a request BODY in tests.
 *
 * Both transports read the request body with
 * `file_get_contents('php://input')`. Under the CLI SAPI that stream is
 * ALWAYS EMPTY — it is not wired to stdin — so a test that does not override
 * it can only ever exercise the empty-body path (a 400 "Missing request
 * body"), never a real SWAIG POST. That blind spot is why the SWAIG
 * `__token` contract was previously only proven on the lambda envelope.
 *
 * This stub re-registers the `php` stream scheme: `php://input` serves a
 * fixture body, and every other `php://` stream (notably `php://output`,
 * `php://memory`, `php://temp`) is delegated to the real implementation, so
 * output buffering and the rest of PHP keep working while it is installed.
 *
 * Always pair {@see install()} with {@see uninstall()} in a `finally` — the
 * wrapper is process-global.
 */
final class InputStreamStub
{
    /** The body `php://input` serves while this stub is installed. */
    public static string $body = '';

    /**
     * Stream-wrapper context, assigned by PHP on the wrapper instance.
     *
     * @var resource|null
     */
    public $context;

    private string $buffer = '';

    private int $position = 0;

    /**
     * The delegate handle for every non-`php://input` stream.
     *
     * @var resource|null
     */
    private $inner = null;

    /** Install the stub and point `php://input` at $body. */
    public static function install(string $body): void
    {
        self::$body = $body;
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);
    }

    /** Restore PHP's built-in `php` stream wrapper. */
    public static function uninstall(): void
    {
        stream_wrapper_restore('php');
        self::$body = '';
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (strtolower($path) === 'php://input') {
            $this->buffer = self::$body;
            $this->position = 0;

            return true;
        }

        // Delegate everything else to the real wrapper, restoring it just for
        // the fopen() so we do not recurse into ourselves.
        stream_wrapper_restore('php');
        $handle = @fopen($path, $mode);
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);

        if ($handle === false) {
            return false;
        }

        $this->inner = $handle;

        return true;
    }

    public function stream_read(int $count): string
    {
        if ($count < 1) {
            return '';
        }

        if ($this->inner !== null) {
            return (string) fread($this->inner, $count);
        }

        $chunk = substr($this->buffer, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_write(string $data): int
    {
        if ($this->inner !== null) {
            return (int) fwrite($this->inner, $data);
        }

        return 0;
    }

    public function stream_eof(): bool
    {
        if ($this->inner !== null) {
            return feof($this->inner);
        }

        return $this->position >= strlen($this->buffer);
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        if ($this->inner !== null) {
            $stat = fstat($this->inner);

            return $stat === false ? [] : $stat;
        }

        return [];
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return false;
    }

    public function stream_flush(): bool
    {
        return $this->inner !== null ? fflush($this->inner) : true;
    }

    public function stream_close(): void
    {
        if ($this->inner !== null) {
            fclose($this->inner);
        }
    }
}
