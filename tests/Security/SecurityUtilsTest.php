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
use SignalWire\Security\SecurityUtils;

/**
 * Parity tests for SecurityUtils. Mirrors the Python reference at
 * signalwire-python/signalwire/signalwire/core/security/security_utils.py
 * (filter_sensitive_headers, redact_url, is_valid_hostname).
 */
class SecurityUtilsTest extends TestCase
{
    // --- filterSensitiveHeaders ----------------------------------------

    public function testFilterRemovesSensitiveHeadersCaseInsensitively(): void
    {
        $headers = [
            'Authorization' => 'Bearer abc',
            'Cookie' => 'session=1',
            'X-API-Key' => 'k',
            'Proxy-Authorization' => 'Basic x',
            'Set-Cookie' => 'a=b',
            'Content-Type' => 'application/json',
            'X-Request-Id' => 'r-123',
        ];

        $filtered = SecurityUtils::filterSensitiveHeaders($headers);

        // All five sensitive keys removed regardless of casing.
        $this->assertArrayNotHasKey('Authorization', $filtered);
        $this->assertArrayNotHasKey('Cookie', $filtered);
        $this->assertArrayNotHasKey('X-API-Key', $filtered);
        $this->assertArrayNotHasKey('Proxy-Authorization', $filtered);
        $this->assertArrayNotHasKey('Set-Cookie', $filtered);

        // Non-sensitive keys preserved with original casing + values.
        $this->assertSame(
            ['Content-Type' => 'application/json', 'X-Request-Id' => 'r-123'],
            $filtered
        );
    }

    public function testFilterEmptyInputReturnsEmptyArray(): void
    {
        $this->assertSame([], SecurityUtils::filterSensitiveHeaders([]));
    }

    public function testFilterReturnsNewArrayAndDoesNotMutateInput(): void
    {
        // Build the input dynamically so the input's exact value is opaque to
        // static analysis — this keeps the mutation check below a real runtime
        // assertion rather than a statically-folded tautology.
        $headers = $this->sampleHeaders();
        $before = $headers;
        $filtered = SecurityUtils::filterSensitiveHeaders($headers);

        $this->assertSame(['x-trace' => '1'], $filtered);
        // Original untouched — filterSensitiveHeaders takes $headers by value.
        $this->assertSame($before, $headers);
    }

    /** @return array<string,string> */
    private function sampleHeaders(): array
    {
        $headers = [];
        foreach ([['authorization', 'secret'], ['x-trace', '1']] as [$k, $v]) {
            $headers[$k] = $v;
        }

        return $headers;
    }

    public function testFilterPreservesNonSensitiveKeysAsGiven(): void
    {
        // A header that merely contains a sensitive substring is NOT removed.
        $headers = ['X-Authorization-Info' => 'meta', 'cookie-jar' => 'kept'];
        $this->assertSame($headers, SecurityUtils::filterSensitiveHeaders($headers));
    }

    // --- redactUrl ------------------------------------------------------

    public function testRedactMasksPasswordInUserinfo(): void
    {
        $this->assertSame(
            'https://user:****@host/path',
            SecurityUtils::redactUrl('https://user:secret@host/path')
        );
    }

    public function testRedactUrlWithoutCredentialsUnchanged(): void
    {
        $this->assertSame(
            'https://host/path?q=1',
            SecurityUtils::redactUrl('https://host/path?q=1')
        );
    }

    public function testRedactUrlWithUserButNoPasswordUnchanged(): void
    {
        $this->assertSame(
            'https://user@host/path',
            SecurityUtils::redactUrl('https://user@host/path')
        );
    }

    public function testRedactPreservesUsername(): void
    {
        $this->assertSame(
            'sip://alice:****@example.com',
            SecurityUtils::redactUrl('sip://alice:hunter2@example.com')
        );
    }

    // --- isValidHostname ------------------------------------------------

    public function testValidHostnameAccepted(): void
    {
        $this->assertTrue(SecurityUtils::isValidHostname('example.com'));
        $this->assertTrue(SecurityUtils::isValidHostname('sub.example.co.uk'));
        $this->assertTrue(SecurityUtils::isValidHostname('localhost'));
        $this->assertTrue(SecurityUtils::isValidHostname('192.168.1.1'));
    }

    public function testEmptyHostnameRejected(): void
    {
        $this->assertFalse(SecurityUtils::isValidHostname(''));
    }

    public function testHostnameWithWhitespaceRejected(): void
    {
        $this->assertFalse(SecurityUtils::isValidHostname('exa mple.com'));
        $this->assertFalse(SecurityUtils::isValidHostname("host\tname"));
        $this->assertFalse(SecurityUtils::isValidHostname("host\nname"));
    }

    public function testHostnameWithSlashesRejected(): void
    {
        $this->assertFalse(SecurityUtils::isValidHostname('example.com/path'));
        $this->assertFalse(SecurityUtils::isValidHostname('example.com\\evil'));
    }

    public function testHostnameWithControlCharsRejected(): void
    {
        $this->assertFalse(SecurityUtils::isValidHostname("host\x00name"));
        $this->assertFalse(SecurityUtils::isValidHostname("host\x1fname"));
        $this->assertFalse(SecurityUtils::isValidHostname("host\x7fname"));
    }
}
