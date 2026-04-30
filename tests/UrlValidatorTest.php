<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Utils\UrlValidator;

/**
 * Parity tests for UrlValidator::validateUrl. Mirrors the Python
 * reference at signalwire-python/tests/unit/utils/test_url_validator.py.
 * DNS is stubbed via the public $resolver field for hermetic execution.
 */
class UrlValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        UrlValidator::$resolver = null;
        putenv('SWML_ALLOW_PRIVATE_URLS');
    }

    protected function tearDown(): void
    {
        UrlValidator::$resolver = null;
        putenv('SWML_ALLOW_PRIVATE_URLS');
    }

    private function stubResolver(string $ip): void
    {
        UrlValidator::$resolver = static fn (string $h): array => [$ip];
    }

    private function stubFailedResolver(): void
    {
        UrlValidator::$resolver = static fn (string $h): ?array => null;
    }

    // --- Scheme ---------------------------------------------------------

    public function testHttpSchemeAllowed(): void
    {
        $this->stubResolver('1.2.3.4');
        $this->assertTrue(UrlValidator::validateUrl('http://example.com'));
    }

    public function testHttpsSchemeAllowed(): void
    {
        $this->stubResolver('1.2.3.4');
        $this->assertTrue(UrlValidator::validateUrl('https://example.com'));
    }

    public function testFtpSchemeRejected(): void
    {
        $this->assertFalse(UrlValidator::validateUrl('ftp://example.com'));
    }

    public function testFileSchemeRejected(): void
    {
        $this->assertFalse(UrlValidator::validateUrl('file:///etc/passwd'));
    }

    public function testJavascriptSchemeRejected(): void
    {
        $this->assertFalse(UrlValidator::validateUrl('javascript:alert(1)'));
    }

    // --- Hostname -------------------------------------------------------

    public function testNoHostnameRejected(): void
    {
        $this->assertFalse(UrlValidator::validateUrl('http://'));
    }

    public function testUnresolvableHostnameRejected(): void
    {
        $this->stubFailedResolver();
        $this->assertFalse(UrlValidator::validateUrl('http://nonexistent.invalid'));
    }

    // --- Blocked ranges -------------------------------------------------

    public function testLoopbackIpv4Rejected(): void
    {
        $this->stubResolver('127.0.0.1');
        $this->assertFalse(UrlValidator::validateUrl('http://localhost'));
    }

    public function testRfc1918_10_Rejected(): void
    {
        $this->stubResolver('10.0.0.5');
        $this->assertFalse(UrlValidator::validateUrl('http://internal'));
    }

    public function testRfc1918_192_Rejected(): void
    {
        $this->stubResolver('192.168.1.1');
        $this->assertFalse(UrlValidator::validateUrl('http://router'));
    }

    public function testRfc1918_172_Rejected(): void
    {
        $this->stubResolver('172.16.0.1');
        $this->assertFalse(UrlValidator::validateUrl('http://corp'));
    }

    public function testLinkLocalMetadataRejected(): void
    {
        $this->stubResolver('169.254.169.254');
        $this->assertFalse(UrlValidator::validateUrl('http://metadata'));
    }

    public function testZeroIpRejected(): void
    {
        $this->stubResolver('0.0.0.0');
        $this->assertFalse(UrlValidator::validateUrl('http://void'));
    }

    public function testIpv6LoopbackRejected(): void
    {
        $this->stubResolver('::1');
        $this->assertFalse(UrlValidator::validateUrl('http://[::1]'));
    }

    public function testIpv6LinkLocalRejected(): void
    {
        $this->stubResolver('fe80::1');
        $this->assertFalse(UrlValidator::validateUrl('http://link-local'));
    }

    public function testIpv6PrivateRejected(): void
    {
        $this->stubResolver('fc00::1');
        $this->assertFalse(UrlValidator::validateUrl('http://ipv6-private'));
    }

    public function testPublicIpAllowed(): void
    {
        $this->stubResolver('8.8.8.8');
        $this->assertTrue(UrlValidator::validateUrl('http://dns.google'));
    }

    // --- allow_private bypass ------------------------------------------

    public function testAllowPrivateParamBypassesCheck(): void
    {
        // No resolver stub: allow_private short-circuits before DNS.
        $this->assertTrue(UrlValidator::validateUrl('http://10.0.0.5', true));
    }

    public function testEnvVarBypassesCheck(): void
    {
        putenv('SWML_ALLOW_PRIVATE_URLS=true');
        $this->assertTrue(UrlValidator::validateUrl('http://10.0.0.5'));
    }

    public function testEnvVarYesBypassesCheck(): void
    {
        putenv('SWML_ALLOW_PRIVATE_URLS=YES');
        $this->assertTrue(UrlValidator::validateUrl('http://10.0.0.5'));
    }

    public function testEnvVar1BypassesCheck(): void
    {
        putenv('SWML_ALLOW_PRIVATE_URLS=1');
        $this->assertTrue(UrlValidator::validateUrl('http://10.0.0.5'));
    }

    public function testEnvVarFalseDoesNotBypass(): void
    {
        putenv('SWML_ALLOW_PRIVATE_URLS=false');
        $this->stubResolver('10.0.0.5');
        $this->assertFalse(UrlValidator::validateUrl('http://internal'));
    }

    public function testBlockedNetworksHasAllNine(): void
    {
        $this->assertCount(9, UrlValidator::BLOCKED_NETWORKS);
    }
}
