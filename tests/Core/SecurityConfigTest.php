<?php

declare(strict_types=1);

namespace SignalWire\Tests\Core;

use PHPUnit\Framework\TestCase;
use SignalWire\Core\SecurityConfig;

/**
 * Real-behavior tests for SignalWire\Core\SecurityConfig.
 *
 * Mirrors Python's signalwire.core.security_config.SecurityConfig: env-driven
 * defaults, SSL validation, security headers (+ HSTS), CORS config, host
 * allowlist, URL scheme, and the PHP-native TLS-options replacement for
 * get_ssl_context_kwargs (mirrors TS SslConfig.getServerOptions).
 */
final class SecurityConfigTest extends TestCase
{
    /** @var list<string> */
    private array $envKeys = [
        'SWML_SSL_ENABLED', 'SWML_SSL_CERT_PATH', 'SWML_SSL_KEY_PATH',
        'SWML_ALLOWED_HOSTS', 'SWML_CORS_ORIGINS', 'SWML_USE_HSTS',
        'SWML_BASIC_AUTH_USER', 'SWML_BASIC_AUTH_PASSWORD', 'SWML_HSTS_MAX_AGE',
    ];

    protected function setUp(): void
    {
        foreach ($this->envKeys as $k) {
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envKeys as $k) {
            putenv($k);
        }
    }

    public function testSecureDefaults(): void
    {
        $cfg = new SecurityConfig();
        $this->assertFalse($cfg->sslEnabled);
        $this->assertSame(['*'], $cfg->allowedHosts);
        $this->assertSame(['*'], $cfg->corsOrigins);
        $this->assertTrue($cfg->useHsts);
        $this->assertSame('http', $cfg->getUrlScheme());
    }

    public function testLoadFromEnv(): void
    {
        putenv('SWML_SSL_ENABLED=true');
        putenv('SWML_ALLOWED_HOSTS=a.com,b.com');
        putenv('SWML_CORS_ORIGINS=https://x.com');
        putenv('SWML_USE_HSTS=false');

        $cfg = new SecurityConfig();
        $this->assertTrue($cfg->sslEnabled);
        $this->assertSame('https', $cfg->getUrlScheme());
        $this->assertSame(['a.com', 'b.com'], $cfg->allowedHosts);
        $this->assertSame(['https://x.com'], $cfg->corsOrigins);
        $this->assertFalse($cfg->useHsts);
    }

    public function testValidateSslConfigMissingCert(): void
    {
        putenv('SWML_SSL_ENABLED=true');
        $cfg = new SecurityConfig();
        [$valid, $error] = $cfg->validateSslConfig();
        $this->assertFalse($valid);
        $this->assertStringContainsString('SWML_SSL_CERT_PATH', (string) $error);
    }

    public function testValidateSslConfigValidWithRealFiles(): void
    {
        $cert = tempnam(sys_get_temp_dir(), 'cert');
        $key = tempnam(sys_get_temp_dir(), 'key');
        putenv('SWML_SSL_ENABLED=true');
        putenv("SWML_SSL_CERT_PATH={$cert}");
        putenv("SWML_SSL_KEY_PATH={$key}");

        $cfg = new SecurityConfig();
        [$valid, $error] = $cfg->validateSslConfig();
        $this->assertTrue($valid);
        $this->assertNull($error);

        // PHP-native TLS options (replacement for get_ssl_context_kwargs).
        $opts = $cfg->getServerTlsOptions();
        $this->assertSame($cert, $opts['local_cert']);
        $this->assertSame($key, $opts['local_pk']);

        @unlink($cert);
        @unlink($key);
    }

    public function testServerTlsOptionsEmptyWhenDisabled(): void
    {
        $cfg = new SecurityConfig();
        $this->assertSame([], $cfg->getServerTlsOptions());
    }

    public function testSecurityHeaders(): void
    {
        $cfg = new SecurityConfig();
        $headers = $cfg->getSecurityHeaders(false);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $headers['X-Frame-Options']);
        $this->assertArrayNotHasKey('Strict-Transport-Security', $headers);

        $withHsts = $cfg->getSecurityHeaders(true);
        $this->assertStringContainsString('max-age=', $withHsts['Strict-Transport-Security']);
        $this->assertStringContainsString('includeSubDomains', $withHsts['Strict-Transport-Security']);
    }

    public function testShouldAllowHost(): void
    {
        putenv('SWML_ALLOWED_HOSTS=only.com');
        $cfg = new SecurityConfig();
        $this->assertTrue($cfg->shouldAllowHost('only.com'));
        $this->assertFalse($cfg->shouldAllowHost('evil.com'));

        $wildcard = new SecurityConfig();
        $wildcard->allowedHosts = ['*'];
        $this->assertTrue($wildcard->shouldAllowHost('anything.com'));
    }

    public function testCorsConfig(): void
    {
        putenv('SWML_CORS_ORIGINS=https://a.com,https://b.com');
        $cfg = new SecurityConfig();
        $cors = $cfg->getCorsConfig();
        $this->assertSame(['https://a.com', 'https://b.com'], $cors['allow_origins']);
        $this->assertTrue($cors['allow_credentials']);
        $this->assertSame(['*'], $cors['allow_methods']);
    }

    public function testGetBasicAuthGeneratesPassword(): void
    {
        $cfg = new SecurityConfig();
        [$user, $pass] = $cfg->getBasicAuth();
        $this->assertSame('signalwire', $user);
        $this->assertNotEmpty($pass);
        // Stable across calls once generated.
        [, $pass2] = $cfg->getBasicAuth();
        $this->assertSame($pass, $pass2);
    }

    public function testLogConfigReturnsSummary(): void
    {
        putenv('SWML_ALLOWED_HOSTS=x.com');
        $cfg = new SecurityConfig();
        $summary = $cfg->logConfig('TestService');
        $this->assertSame('TestService', $summary['service']);
        $this->assertSame(['x.com'], $summary['allowed_hosts']);
        $this->assertFalse($summary['has_basic_auth']);
    }
}
