<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\REST\RequestOptions;
use SignalWire\REST\RestClient;
use SignalWire\REST\SignalWireRestError;
use SignalWire\REST\SignalWireRestTransportError;

/**
 * RequestOptions envelope — behavioral contract over the real mock (plan 4.2).
 *
 * Translated from signalwire-python/tests/unit/rest/test_request_options.py.
 * These drive a real {@see RestClient} through the real cURL transport into the
 * shared mock_signalwire server and assert on the recorded journal — the same
 * journal the REST-COVERAGE gate reads. Retry / timeout are wire-observable: the
 * mock sees N attempts and honors the backoff ordering, so the contract is
 * proven over the real mock, NOT a transport mock.
 *
 * Contract pinned here (the oracle):
 * - retries: a retryable failure is retried up to ``retries`` extra times; the
 *   mock sees ``retries + 1`` attempts; the final success is returned.
 * - idempotency asymmetry: GET/PUT/DELETE retry on the full retry_on_status
 *   set; POST/PATCH retry only on 429/503 (throttles), never 500/502/504.
 * - timeout: a server-side delay exceeding the timeout raises the transport
 *   error family.
 * - abort_signal: set before a request raises the transport error family.
 * - per-request options shallow-override the client default.
 */
class RequestOptionsMockTest extends TestCase
{
    private const ADDRESSES_ENDPOINT = 'fabric.list_fabric_addresses';
    private const ADDRESSES_PATH = '/api/fabric/addresses';
    private const CREATE_ADDRESS_ENDPOINT = 'relay-rest.create_address';
    private const CREATE_ADDRESS_PATH = '/api/relay/rest/addresses';

    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        [$this->client, $this->mock] = MockTest::scopedClient();
    }

    /**
     * Count journal entries for this scoped client that match $method + $path.
     */
    private function countRequests(string $method, string $path): int
    {
        $count = 0;
        foreach ($this->mock->journal()->all() as $e) {
            if ($e->method === $method && $e->path === $path) {
                $count++;
            }
        }
        return $count;
    }

    // ----- retry contract --------------------------------------------------

    #[Test]
    public function getRetries503ThenSucceeds(): void
    {
        // Arm a single 503; the default synthesized 200 follows it. With
        // retries=1 the client retries the 503 into the 200 => 2 attempts.
        $this->mock->scenarios()->set(self::ADDRESSES_ENDPOINT, 503, ['errors' => [['code' => 'X']]]);

        $result = $this->client->getHttp()->get(
            self::ADDRESSES_PATH,
            [],
            new RequestOptions(retries: 1, retryBackoff: 0.0),
        );

        $this->assertArrayHasKey('data', $result);
        $this->assertSame(2, $this->countRequests('GET', self::ADDRESSES_PATH), 'expected 2 attempts (503 then 200)');
    }

    #[Test]
    public function noRetriesByDefaultRaisesOnFirstFailure(): void
    {
        // Default retries=0: the first non-2xx raises immediately (retries are
        // opt-in; the no-retry contract remains the default).
        $this->mock->scenarios()->set(self::ADDRESSES_ENDPOINT, 503, ['errors' => [['code' => 'X']]]);

        try {
            $this->client->getHttp()->get(self::ADDRESSES_PATH);
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame(1, $this->countRequests('GET', self::ADDRESSES_PATH), 'default must not retry');
    }

    #[Test]
    public function retriesExhaustedRaisesLastError(): void
    {
        // Two 503s + retries=1 => attempts = 2, both 503 => raise the 503.
        $this->mock->scenarios()->set(self::ADDRESSES_ENDPOINT, 503, ['errors' => [['code' => 'X']]]);
        $this->mock->scenarios()->set(self::ADDRESSES_ENDPOINT, 503, ['errors' => [['code' => 'X']]]);

        try {
            $this->client->getHttp()->get(
                self::ADDRESSES_PATH,
                [],
                new RequestOptions(retries: 1, retryBackoff: 0.0),
            );
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame(2, $this->countRequests('GET', self::ADDRESSES_PATH), 'retries=1 => exactly 2 attempts');
    }

    // ----- idempotency asymmetry ------------------------------------------

    #[Test]
    public function postDoesNotRetry500(): void
    {
        // A real POST route; 500 is NOT retryable for a non-idempotent method
        // even with retries armed => exactly one attempt, raise the 500.
        $this->mock->scenarios()->set(self::CREATE_ADDRESS_ENDPOINT, 500, ['error' => 'x']);

        try {
            $this->client->getHttp()->post(
                self::CREATE_ADDRESS_PATH,
                ['label' => 'x'],
                new RequestOptions(retries: 2, retryBackoff: 0.0),
            );
            $this->fail('expected SignalWireRestError');
        } catch (SignalWireRestError $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertSame(1, $this->countRequests('POST', self::CREATE_ADDRESS_PATH), 'POST must not retry a 500 (side-effect safety)');
    }

    #[Test]
    public function postDoesRetry503(): void
    {
        // 503 (throttle) IS retryable even for a non-idempotent method => the
        // 503 retries into the default 200/201.
        $this->mock->scenarios()->set(self::CREATE_ADDRESS_ENDPOINT, 503, ['error' => 'x']);

        $this->client->getHttp()->post(
            self::CREATE_ADDRESS_PATH,
            ['label' => 'x'],
            new RequestOptions(retries: 1, retryBackoff: 0.0),
        );

        $this->assertSame(2, $this->countRequests('POST', self::CREATE_ADDRESS_PATH), 'POST retries a 503 throttle (safe): 503 then 200');
    }

    // ----- timeout ---------------------------------------------------------

    #[Test]
    public function slowResponseTimesOut(): void
    {
        // Arm a 200 delayed 400ms; a 100ms timeout must fire => transport error.
        $this->mock->scenarios()->setRaw(
            self::ADDRESSES_ENDPOINT,
            ['status' => 200, 'response' => ['data' => [], 'links' => []], 'delay_ms' => 400],
        );

        $this->expectException(SignalWireRestTransportError::class);
        $this->client->getHttp()->get(
            self::ADDRESSES_PATH,
            [],
            new RequestOptions(timeout: 0.1),
        );
    }

    // ----- abort signal ----------------------------------------------------

    #[Test]
    public function presetAbortRaisesTransportError(): void
    {
        // A callable abort signal that reports "set" (cancelled).
        $signal = static fn (): bool => true;

        try {
            $this->client->getHttp()->get(
                self::ADDRESSES_PATH,
                [],
                new RequestOptions(abortSignal: $signal),
            );
            $this->fail('expected SignalWireRestTransportError');
        } catch (SignalWireRestTransportError $e) {
            // expected
        }
        // Nothing reached the mock — cancelled before the send.
        $this->assertSame(0, $this->countRequests('GET', self::ADDRESSES_PATH), 'aborted request must not reach the server');
    }

    #[Test]
    public function presetAbortViaIsSetObjectRaisesTransportError(): void
    {
        // The object form: anything exposing isSet(): bool.
        $signal = new class () {
            public function isSet(): bool
            {
                return true;
            }
        };

        $this->expectException(SignalWireRestTransportError::class);
        $this->client->getHttp()->get(
            self::ADDRESSES_PATH,
            [],
            new RequestOptions(abortSignal: $signal),
        );
    }

    // ----- per-request override -------------------------------------------

    #[Test]
    public function perRequestRetriesOverrideClientDefault(): void
    {
        // Client default = no retries; per-request opts in to 1 retry.
        [$client, $mock] = MockTest::scopedClient(new RequestOptions(retries: 0));
        $mock->scenarios()->set(self::ADDRESSES_ENDPOINT, 503, ['errors' => [['code' => 'X']]]);

        $result = $client->getHttp()->get(
            self::ADDRESSES_PATH,
            [],
            new RequestOptions(retries: 1, retryBackoff: 0.0),
        );

        $this->assertArrayHasKey('data', $result);
        $gets = 0;
        foreach ($mock->journal()->all() as $e) {
            if ($e->method === 'GET' && $e->path === self::ADDRESSES_PATH) {
                $gets++;
            }
        }
        $this->assertSame(2, $gets, 'per-request retries=1 overrides the client default of 0');
    }
}
