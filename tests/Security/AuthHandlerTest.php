<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Tests\Security;

use PHPUnit\Framework\TestCase;
use SignalWire\Security\AuthHandler;

/**
 * Behavioral parity tests for AuthHandler — the multi-method auth helper.
 * Mirrors the TS oracle (AuthHandler.ts) and Python reference
 * (core/auth_handler.py): verify_api_key / verify_basic_auth /
 * verify_bearer_token / get_auth_info, plus the PHP-native middleware analog.
 */
class AuthHandlerTest extends TestCase
{
    public function testVerifyApiKeyValidAndInvalid(): void
    {
        $h = new AuthHandler(apiKey: 's3cret-key');
        $this->assertTrue($h->verifyApiKey('s3cret-key'));
        $this->assertFalse($h->verifyApiKey('wrong-key'));
    }

    public function testVerifyApiKeyReturnsFalseWhenNotConfigured(): void
    {
        $h = new AuthHandler(bearerToken: 'tok');
        $this->assertFalse($h->verifyApiKey('anything'));
    }

    public function testVerifyBearerTokenValidAndInvalid(): void
    {
        $h = new AuthHandler(bearerToken: 'abc123');
        $this->assertTrue($h->verifyBearerToken('abc123'));
        $this->assertFalse($h->verifyBearerToken('nope'));
    }

    public function testVerifyBasicAuthValidAndInvalid(): void
    {
        $h = new AuthHandler(basicAuth: ['admin', 'pw']);
        $this->assertTrue($h->verifyBasicAuth('admin', 'pw'));
        $this->assertFalse($h->verifyBasicAuth('admin', 'wrong'));
        $this->assertFalse($h->verifyBasicAuth('other', 'pw'));
    }

    public function testGetAuthInfoReflectsConfiguredMethods(): void
    {
        $h = new AuthHandler(
            bearerToken: 'tok',
            apiKey: 'key',
            basicAuth: ['user', 'pass'],
            apiKeyHeader: 'X-Custom-Key',
        );
        $info = $h->getAuthInfo();

        $this->assertSame(['enabled' => true, 'username' => 'user'], $info['basic']);
        $this->assertTrue($info['bearer']['enabled']);
        $this->assertSame('X-Custom-Key', $info['api_key']['header']);
        $this->assertSame('Use X-Custom-Key: <key>', $info['api_key']['hint']);
    }

    public function testGetAuthInfoOmitsUnconfiguredMethods(): void
    {
        $h = new AuthHandler(apiKey: 'key');
        $info = $h->getAuthInfo();
        $this->assertArrayHasKey('api_key', $info);
        $this->assertArrayNotHasKey('basic', $info);
        $this->assertArrayNotHasKey('bearer', $info);
    }

    public function testValidateAcceptsBearerHeader(): void
    {
        $h = new AuthHandler(bearerToken: 'abc123');
        $this->assertTrue($h->validate(['Authorization' => 'Bearer abc123']));
        $this->assertFalse($h->validate(['Authorization' => 'Bearer wrong']));
    }

    public function testValidateAcceptsBasicHeader(): void
    {
        $h = new AuthHandler(basicAuth: ['admin', 'pw']);
        $header = 'Basic ' . base64_encode('admin:pw');
        $this->assertTrue($h->validate(['authorization' => $header]));
        $bad = 'Basic ' . base64_encode('admin:bad');
        $this->assertFalse($h->validate(['authorization' => $bad]));
    }

    public function testValidateAcceptsApiKeyHeaderCaseInsensitive(): void
    {
        $h = new AuthHandler(apiKey: 'key', apiKeyHeader: 'X-Api-Key');
        $this->assertTrue($h->validate(['x-api-key' => 'key']));
        $this->assertFalse($h->validate(['x-api-key' => 'nope']));
    }

    public function testMiddlewareRejectsUnauthenticatedWith401(): void
    {
        $h = new AuthHandler(apiKey: 'key');
        $mw = $h->middleware();

        $allowed = $mw(['X-Api-Key' => 'key']);
        $this->assertNull($allowed, 'valid credentials should be allowed through (null)');

        $rejected = $mw(['X-Api-Key' => 'wrong']);
        $this->assertIsArray($rejected);
        $this->assertSame(401, $rejected[0]);
        $this->assertStringContainsString('Unauthorized', $rejected[2]);
    }

    public function testMiddlewareOptionalAllowsThrough(): void
    {
        $h = new AuthHandler(apiKey: 'key');
        $mw = $h->middleware(optional: true);
        $this->assertNull($mw(['X-Api-Key' => 'wrong']));
    }
}
