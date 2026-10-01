<?php

declare(strict_types=1);

namespace SignalWire\Tests\Tls;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\Core\SecurityConfig;
use SignalWire\Server\AgentServer;
use SignalWire\SWML\Service;
use SignalWire\Web\WebService;

/**
 * SECURITY (#90 — the silently-plain-HTTP shape).
 *
 * An operator who turns the ssl_enabled switch ON but supplies no usable
 * cert/key must NOT get a working plain-HTTP listener. They asked for
 * encryption; serving cleartext without saying so is the defect.
 *
 * Before the fix, all three serving paths folded "TLS configured but unusable"
 * into "TLS off" and bound a plaintext listener:
 *
 *   AgentServer::serve()  — resolveSslPaths() returned [null, null] and control
 *                           fell through to `php -S` (plaintext).
 *   WebService::start()   — getServerTlsOptions() returned [] so $useSsl was
 *                           false; the startup line even logged `https://`
 *                           while `php -S` served cleartext.
 *   Service::serve()      — never consulted the TLS config at all, while
 *                           getFullUrl() advertised `https://`.
 *
 * Each path refuses BEFORE binding, so a misconfigured service never accepts a
 * single plaintext byte. The refusal names the missing key and both remedies.
 *
 * SCOPE CONTROL: the *_PlainHttp_StillWorks cases assert that plain HTTP keeps
 * working when no TLS is requested, so the guard cannot pass by refusing
 * everything.
 */
#[Group('tls')]
final class TlsNoSilentDowngradeTest extends TestCase
{
    /** @var array<int, string> env keys this test mutates, restored in tearDown. */
    private const ENV_KEYS = [
        'SWML_SSL_ENABLED',
        'SWML_SSL_CERT_PATH',
        'SWML_SSL_KEY_PATH',
    ];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $k) {
            $this->savedEnv[$k] = getenv($k);
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $k) {
            $v = $this->savedEnv[$k] ?? false;
            if (is_string($v)) {
                putenv("{$k}={$v}");
            } else {
                putenv($k);
            }
        }
    }

    /**
     * @param array<string, string> $env
     */
    private function withEnv(array $env): void
    {
        foreach ($env as $k => $v) {
            putenv("{$k}={$v}");
        }
    }

    // ------------------------------------------------------------------
    // AgentServer::serve()
    // ------------------------------------------------------------------

    public function testAgentServerRefusesWhenSslEnabledWithNoCertPath(): void
    {
        $this->withEnv(['SWML_SSL_ENABLED' => 'true']);

        $server = new AgentServer('127.0.0.1', 0);
        $server->register($this->makeAgent(), '/');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SWML_SSL_CERT_PATH/');
        $server->serve();
    }

    public function testAgentServerRefusesWhenSslEnabledWithMissingCertFile(): void
    {
        $this->withEnv([
            'SWML_SSL_ENABLED'   => 'true',
            'SWML_SSL_CERT_PATH' => __DIR__ . '/does-not-exist.pem',
            'SWML_SSL_KEY_PATH'  => __DIR__ . '/does-not-exist.key',
        ]);

        $server = new AgentServer('127.0.0.1', 0);
        $server->register($this->makeAgent(), '/');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does-not-exist\.pem/');
        $server->serve();
    }

    public function testAgentServerRefusesWhenSslEnabledWithMissingKeyFile(): void
    {
        [$cert, ] = $this->makeCertPair();
        $this->withEnv([
            'SWML_SSL_ENABLED'   => 'true',
            'SWML_SSL_CERT_PATH' => $cert,
            'SWML_SSL_KEY_PATH'  => __DIR__ . '/does-not-exist.key',
        ]);

        $server = new AgentServer('127.0.0.1', 0);
        $server->register($this->makeAgent(), '/');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SWML_SSL_KEY_PATH|does-not-exist\.key/');
        $server->serve();
    }

    // ------------------------------------------------------------------
    // WebService::start()
    // ------------------------------------------------------------------

    public function testWebServiceRefusesWhenSslEnabledWithNoCertPath(): void
    {
        $this->withEnv(['SWML_SSL_ENABLED' => 'true']);

        $svc = new WebService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SWML_SSL_CERT_PATH/');
        $svc->start('127.0.0.1', 0);
    }

    public function testWebServiceRefusesWhenSslEnabledWithMissingCertFile(): void
    {
        $this->withEnv([
            'SWML_SSL_ENABLED'   => 'true',
            'SWML_SSL_CERT_PATH' => __DIR__ . '/does-not-exist.pem',
            'SWML_SSL_KEY_PATH'  => __DIR__ . '/does-not-exist.key',
        ]);

        $svc = new WebService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does-not-exist\.pem/');
        $svc->start('127.0.0.1', 0);
    }

    // ------------------------------------------------------------------
    // Service::serve()  (SWMLService)
    // ------------------------------------------------------------------

    public function testSwmlServiceRefusesWhenSslEnabledWithNoCertPath(): void
    {
        $this->withEnv(['SWML_SSL_ENABLED' => 'true']);

        $svc = new Service('probe', '/', '127.0.0.1', 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SWML_SSL_CERT_PATH/');
        $svc->serve();
    }

    public function testSwmlServiceRefusesWhenSslEnabledWithMissingCertFile(): void
    {
        $this->withEnv([
            'SWML_SSL_ENABLED'   => 'true',
            'SWML_SSL_CERT_PATH' => __DIR__ . '/does-not-exist.pem',
            'SWML_SSL_KEY_PATH'  => __DIR__ . '/does-not-exist.key',
        ]);

        $svc = new Service('probe', '/', '127.0.0.1', 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does-not-exist\.pem/');
        $svc->serve();
    }

    // ------------------------------------------------------------------
    // SCOPE CONTROLS — the refusal must not be "refuse everything".
    // ------------------------------------------------------------------

    public function testAgentServerPlainHttpStillWorksWhenNoTlsRequested(): void
    {
        // No SWML_SSL_* set at all: TLS was never requested, so plaintext is
        // the CORRECT outcome and serve() must not refuse.
        $server = new AgentServer('127.0.0.1', 0);
        $server->register($this->makeAgent(), '/');

        $m = new \ReflectionMethod($server, 'assertTlsUsableOrRefuse');
        $m->setAccessible(true);
        $m->invoke($server);   // must NOT throw

        // And the plaintext branch is genuinely reachable: no cert/key resolves.
        $r = new \ReflectionMethod($server, 'resolveSslPaths');
        $r->setAccessible(true);
        $this->assertSame([null, null], $r->invoke($server));
    }

    public function testWebServicePlainHttpStillWorksWhenNoTlsRequested(): void
    {
        $cfg = new SecurityConfig();

        // TLS off -> no refusal, and no TLS options.
        $this->assertFalse($cfg->sslEnabled);
        $this->assertSame([], $cfg->getServerTlsOptions());
        [$valid, $err] = $cfg->validateSslConfig();
        $this->assertTrue($valid);
        $this->assertNull($err);
    }

    public function testSwmlServicePlainHttpStillWorksWhenNoTlsRequested(): void
    {
        $svc = new Service('probe', '/', '127.0.0.1', 0);

        $m = new \ReflectionMethod($svc, 'assertTlsUsableOrRefuse');
        $m->setAccessible(true);
        $m->invoke($svc);   // must NOT throw

        // And the service really is in plaintext mode.
        $this->assertFalse($svc->sslEnabled);
        $this->assertStringStartsWith('http://', $svc->getFullUrl(false));
    }

    /**
     * A FULLY configured TLS setup must NOT be refused by the guard — proving
     * the guard rejects misconfiguration specifically, not TLS in general.
     */
    public function testFullyConfiguredTlsPassesTheGuard(): void
    {
        [$cert, $key] = $this->makeCertPair();
        $this->withEnv([
            'SWML_SSL_ENABLED'   => 'true',
            'SWML_SSL_CERT_PATH' => $cert,
            'SWML_SSL_KEY_PATH'  => $key,
        ]);

        $server = new AgentServer('127.0.0.1', 0);
        $m = new \ReflectionMethod($server, 'assertTlsUsableOrRefuse');
        $m->setAccessible(true);
        $m->invoke($server);

        $svc = new Service('probe', '/', '127.0.0.1', 0);
        $m2 = new \ReflectionMethod($svc, 'assertTlsUsableOrRefuse');
        $m2->setAccessible(true);
        $m2->invoke($svc);   // must NOT throw

        // The same config validates and yields real TLS-serving options, so the
        // guard passed because the config is USABLE, not because it is inert.
        $cfg = new SecurityConfig();
        $this->assertTrue($cfg->sslEnabled);
        [$isValid, $err] = $cfg->validateSslConfig();
        $this->assertTrue($isValid);
        $this->assertNull($err);
        $this->assertSame(
            ['local_cert' => $cert, 'local_pk' => $key],
            $cfg->getServerTlsOptions()
        );
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function makeAgent(): AgentBase
    {
        return new class ('probe', '/') extends AgentBase {
        };
    }

    /**
     * Write a throwaway cert/key PAIR whose only requirement is that both
     * files EXIST and parse — the guard checks presence/readability, not trust.
     *
     * @return array{0: string, 1: string} [certPath, keyPath]
     */
    private function makeCertPair(): array
    {
        // Repo-local scratch, never a shared global temp.
        $dir = dirname(__DIR__, 2) . '/.sw-tmp/tls-downgrade-test';
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        $cert = $dir . '/probe.crt';
        $key  = $dir . '/probe.key';
        if (!is_file($cert) || !is_file($key)) {
            $pkey = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            self::assertNotFalse($pkey, 'openssl_pkey_new failed');
            $csr = openssl_csr_new(['commonName' => 'localhost'], $pkey, ['digest_alg' => 'sha256']);
            self::assertInstanceOf(\OpenSSLCertificateSigningRequest::class, $csr);
            $x509 = openssl_csr_sign($csr, null, $pkey, 365, ['digest_alg' => 'sha256']);
            self::assertNotFalse($x509, 'openssl_csr_sign failed');
            openssl_x509_export($x509, $certOut);
            openssl_pkey_export($pkey, $keyOut);
            file_put_contents($cert, $certOut);
            file_put_contents($key, $keyOut);
        }
        return [$cert, $key];
    }
}
