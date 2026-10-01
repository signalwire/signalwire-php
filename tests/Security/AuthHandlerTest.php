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
use SignalWire\Security\BasicCredentials;
use SignalWire\Security\BearerCredentials;
use SignalWire\Tests\Support\Shape;

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
        $this->assertTrue($h->verifyBearerToken(new BearerCredentials('Bearer', 'abc123')));
        $this->assertFalse($h->verifyBearerToken(new BearerCredentials('Bearer', 'nope')));
    }

    /**
     * Only the token is matched — the scheme the carrier records is not part of
     * the comparison (the reference reads `credentials.credentials` alone).
     */
    public function testVerifyBearerTokenIgnoresTheScheme(): void
    {
        $h = new AuthHandler(bearerToken: 'abc123');
        $this->assertTrue($h->verifyBearerToken(new BearerCredentials('Token', 'abc123')));
    }

    public function testVerifyBasicAuthValidAndInvalid(): void
    {
        $h = new AuthHandler(basicAuth: ['admin', 'pw']);
        $this->assertTrue($h->verifyBasicAuth(new BasicCredentials('admin', 'pw')));
        $this->assertFalse($h->verifyBasicAuth(new BasicCredentials('admin', 'wrong')));
        $this->assertFalse($h->verifyBasicAuth(new BasicCredentials('other', 'pw')));
    }

    public function testCredentialCarriersExposeTheirFields(): void
    {
        $basic = new BasicCredentials('admin', 'pw');
        $this->assertSame('admin', $basic->username);
        $this->assertSame('pw', $basic->password);

        $bearer = new BearerCredentials('Bearer', 'abc123');
        $this->assertSame('Bearer', $bearer->scheme);
        $this->assertSame('abc123', $bearer->credentials);
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
        $this->assertTrue(Shape::at($info, 'bearer', 'enabled'));
        $this->assertSame('X-Custom-Key', Shape::at($info, 'api_key', 'header'));
        $this->assertSame('Use X-Custom-Key: <key>', Shape::at($info, 'api_key', 'hint'));
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

    /**
     * RFC 7235 makes the auth-scheme token case-insensitive, and the reference
     * (FastAPI HTTPBearer) partitions the header on the first space and
     * compares `scheme.lower() != "bearer"`. So `bearer <token>` is legal and
     * must authenticate.
     */
    public function testValidateAcceptsBearerSchemeCaseInsensitively(): void
    {
        $h = new AuthHandler(bearerToken: 'abc123');
        $this->assertTrue($h->validate(['Authorization' => 'bearer abc123']));
        $this->assertTrue($h->validate(['Authorization' => 'BeArEr abc123']));
        $this->assertTrue($h->validate(['Authorization' => 'BEARER abc123']));
    }

    /** The reference compares `scheme.lower() != "basic"` — `basic` is legal. */
    public function testValidateAcceptsBasicSchemeCaseInsensitively(): void
    {
        $h = new AuthHandler(basicAuth: ['admin', 'pw']);
        $cred = base64_encode('admin:pw');
        $this->assertTrue($h->validate(['authorization' => 'basic ' . $cred]));
        $this->assertTrue($h->validate(['authorization' => 'BaSiC ' . $cred]));
        $this->assertTrue($h->validate(['authorization' => 'BASIC ' . $cred]));
    }

    /**
     * Case-insensitivity must not widen the accepted scheme set: a different
     * scheme, a scheme that merely starts with the right token, the other
     * branch's scheme, and a scheme-less header all stay rejected.
     */
    public function testValidateStillRejectsWrongSchemesOnTheBearerBranch(): void
    {
        $h = new AuthHandler(bearerToken: 'abc123');
        foreach ([
            'Digest abc123',
            'Negotiate abc123',
            'Bearerx abc123',
            'bearerx abc123',
            'Basic abc123',
            'abc123',
        ] as $header) {
            $this->assertFalse($h->validate(['Authorization' => $header]), $header);
        }
    }

    public function testValidateStillRejectsWrongSchemesOnTheBasicBranch(): void
    {
        $h = new AuthHandler(basicAuth: ['admin', 'pw']);
        $cred = base64_encode('admin:pw');
        foreach ([
            'Digest ' . $cred,
            'Negotiate ' . $cred,
            'Basicx ' . $cred,
            'basicx ' . $cred,
            'Bearer ' . $cred,
            $cred,
        ] as $header) {
            $this->assertFalse($h->validate(['Authorization' => $header]), $header);
        }
    }

    /**
     * The reference does `username, separator, password = data.partition(":")`
     * and raises when there is no separator, so a colon-less decoded payload is
     * rejected outright — it must never authenticate as a user with an empty
     * password.
     */
    public function testValidateRejectsColonLessBasicPayloadInEitherSchemeCase(): void
    {
        $h = new AuthHandler(basicAuth: ['admin', '']);
        $cred = base64_encode('admin');
        $this->assertFalse($h->validate(['Authorization' => 'Basic ' . $cred]));
        $this->assertFalse($h->validate(['Authorization' => 'basic ' . $cred]));
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
