<?php

declare(strict_types=1);

namespace SignalWire\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\Skills\Builtin\Spider;

/**
 * Spider::$session — the fetcher every page fetch goes through refuses a
 * private, internal or invalid URL (signalwire-python skills/spider/skill.py
 * `self.session = _PublicSession()`, url_validator.py:199).
 */
class SpiderSessionTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SWML_ALLOW_PRIVATE_URLS');
    }

    /** @param array<string, mixed> $params */
    private static function spider(array $params = []): Spider
    {
        $agent = new AgentBase(name: 'spider-host', basicAuthUser: 'u', basicAuthPassword: 'p');
        return new Spider($agent, $params);
    }

    public function testSessionCarriesTheConfiguredHeaders(): void
    {
        $spider = self::spider(['user_agent' => 'TestBot/1', 'headers' => ['X-Trace' => 'abc']]);
        $this->assertSame('TestBot/1', $spider->session->headers['User-Agent']);
        $this->assertSame('abc', $spider->session->headers['X-Trace']);
    }

    public function testSessionRefusesAPrivateAddress(): void
    {
        putenv('SWML_ALLOW_PRIVATE_URLS');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is private, internal or invalid');
        self::spider()->session->get('http://127.0.0.1:9/');
    }

    public function testSessionRefusesANonHttpScheme(): void
    {
        $this->expectException(\RuntimeException::class);
        self::spider()->session->get('file:///etc/passwd');
    }

    public function testPrivateUrlsAllowedByTheEnvSwitch(): void
    {
        // With the switch on the URL check passes; the closed port then fails
        // at the transport, not at the address check.
        putenv('SWML_ALLOW_PRIVATE_URLS=true');
        try {
            self::spider()->session->get('http://127.0.0.1:9/', timeout: 2);
            $this->fail('expected a transport failure');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('private, internal or invalid', $e->getMessage());
        }
    }
}
