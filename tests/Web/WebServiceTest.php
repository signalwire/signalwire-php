<?php

declare(strict_types=1);

namespace SignalWire\Tests\Web;

use PHPUnit\Framework\TestCase;
use SignalWire\Web\WebService;

/**
 * Real-behavior tests for SignalWire\Web\WebService.
 *
 * Mirrors Python's signalwire.web.web_service.WebService: directory mounting,
 * static file serving with real content + MIME, extension filtering, directory
 * browsing, path-traversal protection, the /health + root endpoints, and basic
 * auth. Requests are driven through the native handleRequest() dispatcher and
 * asserted on the [status, headers, body] output.
 */
final class WebServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sw_webtest_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/hello.txt', 'hello world');
        file_put_contents($this->dir . '/page.html', '<h1>hi</h1>');
        file_put_contents($this->dir . '/.env', 'SECRET=1');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    private function service(array $overrides = []): WebService
    {
        return new WebService(
            port: $overrides['port'] ?? 8099,
            directories: $overrides['directories'] ?? ['/static' => $this->dir],
            basicAuth: $overrides['basicAuth'] ?? null,
            enableDirectoryBrowsing: $overrides['enableDirectoryBrowsing'] ?? false,
            allowedExtensions: $overrides['allowedExtensions'] ?? null,
        );
    }

    public function testServesRealFileWithMime(): void
    {
        $svc = $this->service();
        [$status, $headers, $body] = $svc->handleRequest('GET', '/static/hello.txt', ['Host' => 'localhost']);
        $this->assertSame(200, $status);
        $this->assertSame('hello world', $body);
        $this->assertSame('text/plain', $headers['Content-Type']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    public function testHtmlMime(): void
    {
        $svc = $this->service();
        [$status, $headers, $body] = $svc->handleRequest('GET', '/static/page.html', ['Host' => 'localhost']);
        $this->assertSame(200, $status);
        $this->assertSame('<h1>hi</h1>', $body);
        $this->assertSame('text/html', $headers['Content-Type']);
    }

    public function testBlockedDotEnvNotServed(): void
    {
        $svc = $this->service();
        [$status] = $svc->handleRequest('GET', '/static/.env', ['Host' => 'localhost']);
        $this->assertSame(403, $status);
    }

    public function testAllowedExtensionsFilter(): void
    {
        $svc = $this->service(['allowedExtensions' => ['.html']]);
        [$txtStatus] = $svc->handleRequest('GET', '/static/hello.txt', ['Host' => 'localhost']);
        [$htmlStatus] = $svc->handleRequest('GET', '/static/page.html', ['Host' => 'localhost']);
        $this->assertSame(403, $txtStatus);
        $this->assertSame(200, $htmlStatus);
    }

    public function testPathTraversalBlocked(): void
    {
        $svc = $this->service();
        [$status] = $svc->handleRequest('GET', '/static/../../etc/passwd', ['Host' => 'localhost']);
        $this->assertSame(403, $status);
    }

    public function testMissingFile404(): void
    {
        $svc = $this->service();
        [$status] = $svc->handleRequest('GET', '/static/nope.txt', ['Host' => 'localhost']);
        $this->assertSame(404, $status);
    }

    public function testDirectoryBrowsingDisabledByDefault(): void
    {
        $svc = $this->service();
        [$status] = $svc->handleRequest('GET', '/static/', ['Host' => 'localhost']);
        $this->assertSame(403, $status);
    }

    public function testDirectoryBrowsingEnabledListsFiles(): void
    {
        $svc = $this->service(['enableDirectoryBrowsing' => true]);
        [$status, $headers, $body] = $svc->handleRequest('GET', '/static/', ['Host' => 'localhost']);
        $this->assertSame(200, $status);
        $this->assertSame('text/html', $headers['Content-Type']);
        $this->assertStringContainsString('hello.txt', $body);
        $this->assertStringContainsString('Directory listing', $body);
        // Hidden .env must not be listed.
        $this->assertStringNotContainsString('.env', $body);
    }

    public function testHealthEndpoint(): void
    {
        $svc = $this->service();
        [$status, $headers, $body] = $svc->handleRequest('GET', '/health', ['Host' => 'localhost']);
        $this->assertSame(200, $status);
        $this->assertSame('application/json', $headers['Content-Type']);
        $decoded = json_decode($body, true);
        $this->assertSame('healthy', $decoded['status']);
        $this->assertContains('/static', $decoded['directories']);
    }

    public function testRootEndpointListsDirectories(): void
    {
        $svc = $this->service();
        [$status, $headers, $body] = $svc->handleRequest('GET', '/', ['Host' => 'localhost']);
        $this->assertSame(200, $status);
        $this->assertSame('text/html', $headers['Content-Type']);
        $this->assertStringContainsString('/static', $body);
    }

    public function testAddAndRemoveDirectory(): void
    {
        $svc = new WebService(directories: []);
        $svc->addDirectory('extra', $this->dir);
        $this->assertArrayHasKey('/extra', $svc->directories);

        [$status, , $body] = $svc->handleRequest('GET', '/extra/hello.txt', ['Host' => 'localhost']);
        $this->assertSame(200, $status);
        $this->assertSame('hello world', $body);

        $svc->removeDirectory('extra');
        $this->assertArrayNotHasKey('/extra', $svc->directories);
    }

    public function testAddDirectoryRejectsMissing(): void
    {
        $svc = new WebService(directories: []);
        $this->expectException(\InvalidArgumentException::class);
        $svc->addDirectory('/x', $this->dir . '/does-not-exist');
    }

    public function testBasicAuthEnforced(): void
    {
        $svc = $this->service(['basicAuth' => ['admin', 'secret']]);

        [$status] = $svc->handleRequest('GET', '/static/hello.txt', ['Host' => 'localhost']);
        $this->assertSame(401, $status);

        $auth = 'Basic ' . base64_encode('admin:secret');
        [$okStatus, , $body] = $svc->handleRequest('GET', '/static/hello.txt', [
            'Host' => 'localhost',
            'Authorization' => $auth,
        ]);
        $this->assertSame(200, $okStatus);
        $this->assertSame('hello world', $body);
    }

    public function testStartNoOpInCliMode(): void
    {
        putenv('SWAIG_CLI_MODE=true');
        try {
            $svc = $this->service();
            // Should return immediately without binding a port or spawning a
            // server, and leave the service state intact + still serviceable.
            $svc->start('127.0.0.1', 0);
            $svc->stop();

            // Config preserved and the dispatcher still serves after start/stop.
            $this->assertSame(8099, $svc->port);
            [$status, , $body] = $svc->handleRequest('GET', '/static/hello.txt', ['Host' => 'localhost']);
            $this->assertSame(200, $status);
            $this->assertSame('hello world', $body);
        } finally {
            putenv('SWAIG_CLI_MODE');
        }
    }
}
