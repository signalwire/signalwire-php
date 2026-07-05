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

    // ------------------------------------------------------------------
    // Decomposed framework-free validation core: WebhookMiddleware::validate.
    // Contract (porting-sdk oracle + webhooks.md):
    //   validate(method, url, headers, body, signingKey)
    //     -> null            on pass (caller proceeds to its handler)
    //     -> [status,h,body] on reject (send this, do NOT call handler)
    // ------------------------------------------------------------------

    public function testValidateReturnsNullOnValidSignature(): void
    {
        $result = WebhookMiddleware::validate(
            'POST',
            self::URL,
            ['X-SignalWire-Signature' => self::SIG_A],
            self::RAW_BODY,
            self::KEY,
        );

        $this->assertNull($result, 'A valid signature must return null (pass).');
    }

    public function testValidateReturns403TripleOnBadSignature(): void
    {
        $result = WebhookMiddleware::validate(
            'POST',
            self::URL,
            ['X-SignalWire-Signature' => 'wrong-signature'],
            self::RAW_BODY,
            self::KEY,
        );

        $this->assertIsArray($result, 'A bad signature must return a reject triple.');
        [$status, , $body] = $result;
        $this->assertSame(403, $status);
        $this->assertSame('Forbidden', $body);
        // The reject triple must never leak the key or the presented signature.
        $this->assertStringNotContainsString(self::KEY, $body);
        $this->assertStringNotContainsString('wrong-signature', $body);
    }

    public function testValidateReturns403TripleOnMissingHeader(): void
    {
        $result = WebhookMiddleware::validate(
            'POST',
            self::URL,
            [],
            self::RAW_BODY,
            self::KEY,
        );

        $this->assertIsArray($result, 'A missing signature header must reject, not throw.');
        [$status, , $body] = $result;
        $this->assertSame(403, $status);
        $this->assertSame('Forbidden', $body);
    }

    public function testValidateHonorsTwilioSignatureAlias(): void
    {
        $result = WebhookMiddleware::validate(
            'POST',
            self::URL,
            ['X-Twilio-Signature' => self::SIG_A],
            self::RAW_BODY,
            self::KEY,
        );

        $this->assertNull(
            $result,
            'The X-Twilio-Signature alias must be honored by the decomposed core (cXML compat).',
        );
    }

    public function testValidateEmptySigningKeyRaises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WebhookMiddleware::validate(
            'POST',
            self::URL,
            ['X-SignalWire-Signature' => self::SIG_A],
            self::RAW_BODY,
            '',
        );
    }
}
