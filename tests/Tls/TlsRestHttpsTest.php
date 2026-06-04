<?php

declare(strict_types=1);

namespace SignalWire\Tests\Tls;

use PHPUnit\Framework\TestCase;
use SignalWire\REST\RestClient;
use SignalWire\REST\SignalWireRestError;

/**
 * TLS capability test (quadrant 2 of 3): the REST client performs a *real*
 * verified HTTPS request.
 *
 * Spawns the shared mock_signalwire in --tls mode (HTTPS, cert signed by the
 * porting-sdk self-signed test CA), points a real RestClient at
 * https://127.0.0.1:<port> trusting ca.crt, and performs a GET against a
 * spec-backed endpoint. A real JSON response with a "data" array can only come
 * back over a completed, CA-verified TLS session. CA trust is wired through
 * the cURL CAINFO option (PHP's cURL does not honor SSL_CERT_FILE on its own);
 * peer verification stays ON (CURLOPT_SSL_VERIFYPEER=true). No verify=false,
 * no transport mock.
 *
 * A negative test issues the same GET with NO trusted CA and asserts the
 * handshake is rejected, proving genuine certificate verification.
 *
 * @group tls
 */
final class TlsRestHttpsTest extends TestCase
{
    private static ?string $certs = null;
    private static ?string $httpsBase = null;

    public static function setUpBeforeClass(): void
    {
        $certs = TlsSupport::certsDir();
        if ($certs === null) {
            self::markTestSkipped('porting-sdk/test_harness/tls not adjacent to repo');
        }
        self::$certs = $certs;
        try {
            self::$httpsBase = TlsSupport::startTlsMockSignalwire(TlsSupport::caCert($certs));
        } catch (\Throwable $e) {
            self::markTestSkipped('mock_signalwire --tls unavailable: ' . $e->getMessage());
        }
    }

    public static function tearDownAfterClass(): void
    {
        TlsSupport::shutdownAll();
    }

    public function testRestClientGetsOverHttps(): void
    {
        $caCert = TlsSupport::caCert((string) self::$certs);
        TlsSupport::restReset((string) self::$httpsBase, $caCert);

        // Real REST client, repointed at the https:// mock, trusting the CA.
        $client = new RestClient('test_proj', 'test_tok', (string) self::$httpsBase, $caCert);

        // GET a spec-backed collection endpoint over HTTPS.
        $body = $client->addresses()->list(['page_size' => '5']);
        $this->assertArrayHasKey('data', $body, 'https response missing "data" key');
        $this->assertIsArray($body['data']);

        // Wire proof: the mock journaled the GET on its HTTPS control plane.
        $last = TlsSupport::restLastJournal((string) self::$httpsBase, $caCert);
        $this->assertIsArray($last, 'mock journal empty after HTTPS GET');
        $this->assertSame('GET', $last['method'] ?? null);
        $this->assertSame('/api/relay/rest/addresses', $last['path'] ?? null);
    }

    public function testUntrustedClientIsRejected(): void
    {
        // Same https:// endpoint, but NO trusted CA (no caBundle, and the
        // process never exported SSL_CERT_FILE) — the private-CA server cert
        // must fail verification against cURL's default store.
        $this->assertFalse(
            getenv('SSL_CERT_FILE') !== false && getenv('SSL_CERT_FILE') !== '',
            'precondition: SSL_CERT_FILE must be unset for the negative case'
        );
        $client = new RestClient('test_proj', 'test_tok', (string) self::$httpsBase);

        $this->expectException(SignalWireRestError::class);
        $this->expectExceptionMessageMatches('/SSL|certificate|verif/i');
        $client->addresses()->list(['page_size' => '5']);
    }
}
