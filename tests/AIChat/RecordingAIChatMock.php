<?php

declare(strict_types=1);

/*
 * Copyright (c) 2026 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

namespace SignalWire\Tests\AIChat;

/**
 * Boots the shared AI Chat mock (bin/ai-chat-mock-router.php) behind a journal
 * so a test can read back every JSON-RPC body the SDK sent.
 *
 * Same harness as AIChatClientTest — a real `php -S` socket driven by the real
 * cURL transport — plus the journal written by fixtures/recording-router.php.
 * The journal lives in the repo-local, gitignored `.sw-tmp/` directory.
 */
final class RecordingAIChatMock
{
    /** @var resource */
    private $proc;

    /**
     * @param resource $proc
     */
    private function __construct(
        $proc,
        public readonly string $url,
        private readonly string $journal,
    ) {
        $this->proc = $proc;
    }

    /** Start a mock on a free port and wait until it accepts connections. */
    public static function start(): self
    {
        $root = dirname(__DIR__, 2);
        $dir = $root . '/.sw-tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $journal = sprintf('%s/ai-chat-journal-%d-%s.jsonl', $dir, getmypid(), bin2hex(random_bytes(4)));
        file_put_contents($journal, '');

        $port = self::freePort();
        $cmd = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg(__DIR__ . '/fixtures/recording-router.php'),
        );
        $env = getenv();
        $env['AI_CHAT_MOCK_JOURNAL'] = $journal;
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('failed to start php -S mock server');
        }

        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($conn)) {
                fclose($conn);
                return new self($proc, sprintf('http://127.0.0.1:%d/api/ai/chat', $port), $journal);
            }
            usleep(50_000);
        }
        proc_terminate($proc);
        proc_close($proc);
        throw new \RuntimeException('mock server did not become reachable within 10s');
    }

    /** Stop the server and remove the journal. */
    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        if (is_file($this->journal)) {
            unlink($this->journal);
        }
    }

    /**
     * Every JSON-RPC body received so far, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function seen(): array
    {
        $raw = file_get_contents($this->journal);
        $out = [];
        foreach (explode("\n", is_string($raw) ? $raw : '') as $line) {
            if (trim($line) === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $row = [];
                foreach ($decoded as $k => $v) {
                    $row[(string) $k] = $v;
                }
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * The most recent JSON-RPC body, or an empty array when none arrived.
     *
     * @return array<string, mixed>
     */
    public function last(): array
    {
        $seen = $this->seen();
        return $seen === [] ? [] : $seen[count($seen) - 1];
    }

    /**
     * Params of the most recent body.
     *
     * @return array<string, mixed>
     */
    public function lastParams(): array
    {
        $params = $this->last()['params'] ?? [];
        $out = [];
        foreach (is_array($params) ? $params : [] as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    private static function freePort(): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($sock)) {
            throw new \RuntimeException('could not bind a free port');
        }
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
