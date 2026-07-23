<?php

declare(strict_types=1);

/*
 * Copyright (c) 2026 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

namespace SignalWire\Tests\AIChat;

use PHPUnit\Framework\TestCase;
use SignalWire\AIChat\AIChatClient;
use SignalWire\AIChat\AIChatError;
use SignalWire\AIChat\AuthenticationError;
use SignalWire\AIChat\ChatInProgressError;
use SignalWire\AIChat\ConversationNotFoundError;
use SignalWire\AIChat\RateLimitError;
use SignalWire\AIChat\SummaryError;

/**
 * Wire-behavioral unit tests for {@see AIChatClient}.
 *
 * These drive the REAL cURL transport against a REAL local HTTP socket — a
 * ``php -S`` process running ``bin/ai-chat-mock-router.php`` (which mirrors the
 * shared porting-sdk ``mock_ai_chat`` contract). No SDK-internal transport
 * mocking; the mock server IS the harness, matching the REST suite's discipline.
 */
final class AIChatClientTest extends TestCase
{
    /** @var resource|null */
    private static $proc = null;
    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        $router = dirname(__DIR__, 2) . '/bin/ai-chat-mock-router.php';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $cmd = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg($router)
        );
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            self::fail('failed to start php -S mock server');
        }
        self::$proc = $proc;
        self::$baseUrl = sprintf('http://127.0.0.1:%d/api/ai/chat', $port);

        // Poll until the server accepts connections (bounded).
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($conn)) {
                fclose($conn);
                return;
            }
            usleep(50_000);
        }
        self::fail('mock server did not become reachable within 10s');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
            self::$proc = null;
        }
    }

    private static function freePort(): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($sock)) {
            self::fail('could not bind a free port');
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
        return $port;
    }

    private function client(): AIChatClient
    {
        // readIdleTimeoutSeconds:0 keeps the tests free of any timer.
        return new AIChatClient(
            project: 'proj-1',
            token: 'tok-1',
            url: self::$baseUrl,
            readIdleTimeoutSeconds: 0,
        );
    }

    // ── construction ─────────────────────────────────────────────────

    public function testRequiresProject(): void
    {
        $saved = getenv('SIGNALWIRE_PROJECT_ID');
        putenv('SIGNALWIRE_PROJECT_ID');
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/project is required/');
            new AIChatClient(url: 'http://x');
        } finally {
            if ($saved !== false) {
                putenv('SIGNALWIRE_PROJECT_ID=' . $saved);
            }
        }
    }

    public function testBuildsSpaceUrl(): void
    {
        $c = new AIChatClient(project: 'p', token: 't', space: 'myspace');
        $this->assertSame('https://myspace.signalwire.com/api/ai/chat', $c->url);
    }

    public function testExplicitUrlVerbatim(): void
    {
        $c = new AIChatClient(project: 'p', token: 't', url: 'http://local/api/ai/chat');
        $this->assertSame('http://local/api/ai/chat', $c->url);
    }

    public function testCloseIsNoOpAndClientRemainsUsable(): void
    {
        // close() completes the reference lifecycle contract. This client
        // opens/closes a cURL handle per request, so close() releases nothing
        // (no-op) and the client stays fully usable afterward — proven by
        // reading $url, an operation unaffected by close().
        $c = new AIChatClient(project: 'p', token: 't', url: 'http://local/api/ai/chat');
        $c->close();
        $c->close(); // idempotent
        $this->assertSame('http://local/api/ai/chat', $c->url);
    }

    public function testThrowsWhenNoUrlResolves(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No service URL/');
        new AIChatClient(project: 'p', token: 't');
    }

    // ── wire behavior ────────────────────────────────────────────────

    public function testCreateConversationDecodesResult(): void
    {
        $info = $this->client()->createConversation('conv-1', configUrl: 'http://cfg', timeout: 30, reinit: true);
        $this->assertSame('conv-1', $info->id);
        $this->assertSame('created', $info->status);
        $this->assertSame('hello', $info->initialMessage);
    }

    public function testChatDecodesResponseAndUserEvent(): void
    {
        $reply = $this->client()->chat('conv-1', 'hello', timeout: 30, reinit: true);
        $this->assertSame('hi there', $reply->text);
        $this->assertSame('conv-1', $reply->conversationId);
        $this->assertSame(['event_type' => 'demo', 'n' => 1], $reply->userEvent);
    }

    public function testEndReturnsTrueOnEnded(): void
    {
        $this->assertTrue($this->client()->end('conv-1'));
    }

    public function testDeleteReturnsTrueOnDeleted(): void
    {
        $this->assertTrue($this->client()->delete('conv-1'));
    }

    public function testLogDecodesMessagesAndTimeline(): void
    {
        $log = $this->client()->log('conv-1');
        $this->assertSame([['role' => 'user', 'content' => 'm']], $log->messages);
        $this->assertSame([['t' => 1]], $log->callTimeline);
    }

    public function testSummarizeReturnsSummaryString(): void
    {
        $this->assertSame('a concise summary', $this->client()->summarize('conv-1'));
    }

    // ── summarize {error} one_of branch: MUST surface, never swallow ──

    public function testSummarizeErrorBranchThrowsSummaryError(): void
    {
        $this->expectException(SummaryError::class);
        try {
            $this->client()->summarize('__summarize_error');
        } catch (SummaryError $e) {
            $this->assertNull($e->getErrorCode());
            $this->assertSame('Failed to generate summary', $e->getServerMessage());
            throw $e;
        }
    }

    // ── error-code mapping ────────────────────────────────────────────

    public function testConversationNotFoundMapping(): void
    {
        try {
            $this->client()->chat('__err_-32001', 'x');
            $this->fail('expected ConversationNotFoundError');
        } catch (ConversationNotFoundError $e) {
            $this->assertSame(-32001, $e->getErrorCode());
        }
    }

    public function testRateLimitMapping(): void
    {
        try {
            $this->client()->chat('__err_-32005', 'x');
            $this->fail('expected RateLimitError');
        } catch (RateLimitError $e) {
            $this->assertSame(-32005, $e->getErrorCode());
        }
    }

    public function testChatInProgressMapping(): void
    {
        try {
            $this->client()->chat('__err_-32007', 'x');
            $this->fail('expected ChatInProgressError');
        } catch (ChatInProgressError $e) {
            $this->assertSame(-32007, $e->getErrorCode());
        }
    }

    public function testAuthenticationMapping(): void
    {
        try {
            $this->client()->chat('__err_-32009', 'x');
            $this->fail('expected AuthenticationError');
        } catch (AuthenticationError $e) {
            $this->assertSame(-32009, $e->getErrorCode());
        }
    }

    public function testUnmappedCodeFallsToBaseError(): void
    {
        try {
            $this->client()->chat('__err_-32602', 'x');
            $this->fail('expected base AIChatError');
        } catch (AIChatError $e) {
            $this->assertSame(AIChatError::class, $e::class);
            $this->assertSame(-32602, $e->getErrorCode());
        }
    }
}
