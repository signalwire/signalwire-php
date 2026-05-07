<?php

declare(strict_types=1);

namespace SignalWire\Tests\Security;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SignalWire\Logging\Logger;
use SignalWire\Security\WebhookMiddleware;

class WebhookMiddlewareTest extends TestCase
{
    private const KEY = 'PSKtest1234567890abcdef';
    private const URL = 'https://example.ngrok.io/webhook';
    private const RAW_BODY = '{"event":"call.state","params":{"call_id":"abc-123","state":"answered"}}';
    private const SIG_A = 'c3c08c1fefaf9ee198a100d5906765a6f394bf0f';

    protected function setUp(): void
    {
        Logger::reset();
    }

    protected function tearDown(): void
    {
        Logger::reset();
    }

    private function makeNext(): callable
    {
        return function (string $method, string $url, array $headers, string $rawBody): array {
            return [
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['method' => $method, 'url' => $url, 'rawBody' => $rawBody, 'received' => true]),
            ];
        };
    }

    public function testValidSignatureForwardsToHandlerAndReturns200(): void
    {
        $mw = new WebhookMiddleware(self::KEY);
        $called = false;
        $next = function (string $method, string $url, array $headers, string $rawBody) use (&$called) {
            $called = true;
            $this->assertSame(self::RAW_BODY, $rawBody, 'Raw body must reach the handler unchanged.');
            $this->assertSame(self::URL, $url);
            $this->assertSame('POST', $method);
            return [200, ['Content-Type' => 'text/plain'], 'ok'];
        };

        [$status, , $body] = $mw->process(
            'POST',
            self::URL,
            ['X-SignalWire-Signature' => self::SIG_A],
            self::RAW_BODY,
            $next,
        );

        $this->assertTrue($called, 'next() must be called when signature is valid.');
        $this->assertSame(200, $status);
        $this->assertSame('ok', $body);
    }

    public function testInvalidSignatureReturns403AndDoesNotCallHandler(): void
    {
        $mw = new WebhookMiddleware(self::KEY);
        $called = false;
        $next = function () use (&$called) {
            $called = true;
            return [200, [], 'never'];
        };

        [$status, $headers, $body] = $mw->process(
            'POST',
            self::URL,
            ['X-SignalWire-Signature' => 'wrong-signature'],
            self::RAW_BODY,
            $next,
        );

        $this->assertSame(403, $status);
        $this->assertFalse($called, 'next() must NOT be called on invalid signature.');
        $this->assertSame('Forbidden', $body);
        $this->assertStringNotContainsString(self::KEY, $body);
        $this->assertStringNotContainsString(self::SIG_A, $body);
    }

    public function testMissingSignatureHeaderReturns403(): void
    {
        $mw = new WebhookMiddleware(self::KEY);
        $called = false;
        $next = function () use (&$called) {
            $called = true;
            return [200, [], 'never'];
        };

        [$status, , $body] = $mw->process(
            'POST',
            self::URL,
            [],
            self::RAW_BODY,
            $next,
        );

        $this->assertSame(403, $status);
        $this->assertFalse($called);
        $this->assertSame('Forbidden', $body);
    }

    public function testEmptySignatureHeaderReturns403(): void
    {
        $mw = new WebhookMiddleware(self::KEY);
        $called = false;
        $next = function () use (&$called) {
            $called = true;
            return [200, [], 'never'];
        };

        [$status, ,] = $mw->process(
            'POST',
            self::URL,
            ['X-SignalWire-Signature' => ''],
            self::RAW_BODY,
            $next,
        );

        $this->assertSame(403, $status);
        $this->assertFalse($called);
    }

    public function testTwilioSignatureAliasAccepted(): void
    {
        $mw = new WebhookMiddleware(self::KEY);
        $called = false;
        $next = function () use (&$called) {
            $called = true;
            return [200, [], 'ok'];
        };

        [$status, ,] = $mw->process(
            'POST',
            self::URL,
            ['X-Twilio-Signature' => self::SIG_A],
            self::RAW_BODY,
            $next,
        );

        $this->assertSame(200, $status);
        $this->assertTrue($called, 'X-Twilio-Signature alias must be accepted (cXML compat).');
    }

    public function testCaseInsensitiveHeaderLookup(): void
    {
        $mw = new WebhookMiddleware(self::KEY);
        $called = false;
        $next = function () use (&$called) {
            $called = true;
            return [200, [], 'ok'];
        };

        [$status, ,] = $mw->process(
            'POST',
            self::URL,
            ['x-signalwire-signature' => self::SIG_A],
            self::RAW_BODY,
            $next,
        );

        $this->assertSame(200, $status);
        $this->assertTrue($called);
    }

    public function testRawBodyNotMutatedAcrossMiddleware(): void
    {
        $mw = new WebhookMiddleware(self::KEY);
        $captured = null;
        $next = function (string $m, string $u, array $h, string $rawBody) use (&$captured) {
            $captured = $rawBody;
            return [200, [], 'ok'];
        };

        $original = self::RAW_BODY;
        $mw->process(
            'POST',
            self::URL,
            ['X-SignalWire-Signature' => self::SIG_A],
            $original,
            $next,
        );

        $this->assertSame($original, $captured, 'Raw body bytes must reach handler byte-for-byte.');
    }

    public function testEmptyKeyConstructorRaises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WebhookMiddleware('');
    }

    public function testForbiddenResponseDoesNotLeakKeyOrSignature(): void
    {
        $mw = new WebhookMiddleware(self::KEY);
        [$status, $headers, $body] = $mw->process(
            'POST',
            self::URL,
            ['X-SignalWire-Signature' => 'tampered'],
            self::RAW_BODY,
            fn () => [500, [], 'should not run'],
        );

        $this->assertSame(403, $status);
        $this->assertStringNotContainsString(self::KEY, $body);
        $this->assertStringNotContainsString('tampered', $body);
        foreach ($headers as $h) {
            $this->assertStringNotContainsString(self::KEY, (string) $h);
        }
    }
}
