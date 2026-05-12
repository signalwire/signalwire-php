<?php

declare(strict_types=1);

namespace SignalWire\Tests\Security;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\Logging\Logger;
use SignalWire\Security\WebhookValidator;
use SignalWire\SWML\Schema;

/**
 * AgentBase webhook signature integration tests.
 *
 * Cross-language SDK contract: when AgentBase is constructed with a
 * signing_key (or SIGNALWIRE_SIGNING_KEY env), it MUST auto-mount
 * signature validation on POST /, /swaig, /post_prompt and reject
 * unsigned / invalidly-signed requests with 403.
 */
class AgentBaseSigningKeyTest extends TestCase
{
    private const SIGNING_KEY = 'PSKtest1234567890abcdef';

    protected function setUp(): void
    {
        Logger::reset();
        Schema::reset();
        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
        putenv('SWML_PROXY_URL_BASE');
        putenv('SIGNALWIRE_SIGNING_KEY');
        putenv('PORT');
    }

    protected function tearDown(): void
    {
        Logger::reset();
        Schema::reset();
        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
        putenv('SWML_PROXY_URL_BASE');
        putenv('SIGNALWIRE_SIGNING_KEY');
        putenv('PORT');
        unset($_SERVER['REQUEST_URI']);
    }

    private function makeAgent(array $opts = []): AgentBase
    {
        return new AgentBase(
            name: $opts['name'] ?? 'test-agent',
            route: $opts['route'] ?? '/',
            host: $opts['host'] ?? null,
            port: $opts['port'] ?? null,
            basicAuthUser: $opts['basic_auth_user'] ?? 'testuser',
            basicAuthPassword: $opts['basic_auth_password'] ?? 'testpass',
            signingKey: $opts['signing_key'] ?? null,
        );
    }

    private function authHeader(): array
    {
        return ['Authorization' => 'Basic ' . base64_encode('testuser:testpass')];
    }

    /**
     * Build a valid X-SignalWire-Signature for a Scheme A POST. The agent
     * reconstructs URL via getProxyUrlBase(); we inject X-Forwarded-Proto /
     * X-Forwarded-Host so the URL is fully deterministic in-test.
     */
    private function signedHeaders(string $path, string $body): array
    {
        $url = "https://signed-host.example.com{$path}";
        $sig = bin2hex(hash_hmac('sha1', $url . $body, self::SIGNING_KEY, true));
        return array_merge($this->authHeader(), [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'signed-host.example.com',
            'X-SignalWire-Signature' => $sig,
        ]);
    }

    // ------------------------------------------------------------------
    // signing_key option propagation
    // ------------------------------------------------------------------

    public function testSigningKeyExplicitOption(): void
    {
        $agent = $this->makeAgent(['signing_key' => self::SIGNING_KEY]);
        $this->assertSame(self::SIGNING_KEY, $agent->getSigningKey());
    }

    public function testSigningKeyEnvFallback(): void
    {
        putenv('SIGNALWIRE_SIGNING_KEY=' . self::SIGNING_KEY);
        $agent = $this->makeAgent();
        $this->assertSame(self::SIGNING_KEY, $agent->getSigningKey());
    }

    public function testExplicitOptionWinsOverEnv(): void
    {
        putenv('SIGNALWIRE_SIGNING_KEY=env-key-xxx');
        $agent = $this->makeAgent(['signing_key' => self::SIGNING_KEY]);
        $this->assertSame(self::SIGNING_KEY, $agent->getSigningKey());
    }

    public function testNoSigningKeyMeansNullAndDoesNotEnforce(): void
    {
        $agent = $this->makeAgent();
        $this->assertNull($agent->getSigningKey());

        // Without signing_key, requests pass with auth alone (existing behaviour).
        [$status, , ] = $agent->handleRequest('POST', '/', $this->authHeader(), '{}');
        $this->assertSame(200, $status, 'unsigned POST must pass when signing_key is unset');
    }

    public function testEmptyStringSigningKeyTreatedAsUnset(): void
    {
        $agent = $this->makeAgent(['signing_key' => '']);
        $this->assertNull($agent->getSigningKey());
    }

    // ------------------------------------------------------------------
    // Signed POST / (SWML) — auto-mount enforcement
    // ------------------------------------------------------------------

    public function testValidSignatureOnRootSwmlAccepted(): void
    {
        $agent = $this->makeAgent(['signing_key' => self::SIGNING_KEY]);

        $body = '{}';
        [$status, , $respBody] = $agent->handleRequest(
            'POST',
            '/',
            $this->signedHeaders('/', $body),
            $body,
        );

        $this->assertSame(200, $status, 'validly signed POST / must pass and render SWML');
        $this->assertNotSame('Forbidden', $respBody);
        // SWML should be returned, not the auth-fail path.
        $this->assertStringContainsString('"version"', $respBody);
    }

    public function testInvalidSignatureOnRootRejectedWith403(): void
    {
        $agent = $this->makeAgent(['signing_key' => self::SIGNING_KEY]);

        $headers = array_merge($this->authHeader(), [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'signed-host.example.com',
            'X-SignalWire-Signature' => 'tampered-signature-value',
        ]);

        [$status, , $body] = $agent->handleRequest('POST', '/', $headers, '{}');

        $this->assertSame(403, $status);
        $this->assertSame('Forbidden', $body);
    }

    public function testMissingSignatureOnRootRejectedWith403(): void
    {
        $agent = $this->makeAgent(['signing_key' => self::SIGNING_KEY]);

        // Auth is present but no X-SignalWire-Signature header.
        [$status, , $body] = $agent->handleRequest('POST', '/', $this->authHeader(), '{}');

        $this->assertSame(403, $status);
        $this->assertSame('Forbidden', $body);
    }

    public function testValidSignatureOnSwaigAccepted(): void
    {
        $agent = $this->makeAgent(['signing_key' => self::SIGNING_KEY]);
        $agent->defineTool(
            'echo_tool',
            'Echoes input',
            ['msg' => ['type' => 'string']],
            fn(array $args) => new \SignalWire\SWAIG\FunctionResult('Echo: ' . ($args['msg'] ?? '')),
        );

        $body = json_encode([
            'function' => 'echo_tool',
            'argument' => ['parsed' => [['msg' => 'hi']]],
        ]);

        [$status, , $respBody] = $agent->handleRequest(
            'POST',
            '/swaig',
            $this->signedHeaders('/swaig', $body),
            $body,
        );

        $this->assertSame(200, $status);
        $decoded = json_decode($respBody, true);
        $this->assertSame('Echo: hi', $decoded['response']);
    }

    public function testInvalidSignatureOnSwaigRejected(): void
    {
        $agent = $this->makeAgent(['signing_key' => self::SIGNING_KEY]);

        $body = json_encode(['function' => 'whatever', 'argument' => ['parsed' => [[]]]]);
        $headers = array_merge($this->authHeader(), [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'signed-host.example.com',
            'X-SignalWire-Signature' => 'bad-sig',
        ]);

        [$status, , ] = $agent->handleRequest('POST', '/swaig', $headers, $body);

        $this->assertSame(403, $status);
    }

    public function testValidSignatureOnPostPromptAccepted(): void
    {
        $agent = $this->makeAgent(['signing_key' => self::SIGNING_KEY]);

        $body = json_encode(['post_prompt_data' => ['raw' => 'A summary.']]);

        [$status, ,] = $agent->handleRequest(
            'POST',
            '/post_prompt',
            $this->signedHeaders('/post_prompt', $body),
            $body,
        );

        $this->assertSame(200, $status);
    }

    public function testMissingSignatureOnPostPromptRejected(): void
    {
        $agent = $this->makeAgent(['signing_key' => self::SIGNING_KEY]);

        $body = json_encode(['post_prompt_data' => ['raw' => 'x']]);

        [$status, , $respBody] = $agent->handleRequest(
            'POST',
            '/post_prompt',
            $this->authHeader(),
            $body,
        );

        $this->assertSame(403, $status);
        $this->assertSame('Forbidden', $respBody);
    }

    // ------------------------------------------------------------------
    // GET / (SWML render) is NOT a signed route — must remain accessible.
    // ------------------------------------------------------------------

    public function testGetRootStillAllowedWhenSigningKeyConfigured(): void
    {
        $agent = $this->makeAgent(['signing_key' => self::SIGNING_KEY]);

        // GETs from browsers / curl probe the SWML doc — not a webhook POST.
        // Spec only requires enforcement on POST signed routes.
        [$status, ,] = $agent->handleRequest('GET', '/', $this->authHeader());

        $this->assertSame(200, $status);
    }

    // ------------------------------------------------------------------
    // Health/ready bypass auth and signature both.
    // ------------------------------------------------------------------

    public function testHealthEndpointBypassesSignatureCheck(): void
    {
        $agent = $this->makeAgent(['signing_key' => self::SIGNING_KEY]);

        [$status, ,] = $agent->handleRequest('GET', '/health');
        $this->assertSame(200, $status);
    }

    // ------------------------------------------------------------------
    // Twilio compat alias header accepted.
    // ------------------------------------------------------------------

    public function testTwilioSignatureAliasAcceptedOnAgentBase(): void
    {
        $agent = $this->makeAgent(['signing_key' => self::SIGNING_KEY]);

        $body = '{}';
        $url = 'https://signed-host.example.com/';
        $sig = bin2hex(hash_hmac('sha1', $url . $body, self::SIGNING_KEY, true));

        $headers = array_merge($this->authHeader(), [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'signed-host.example.com',
            'X-Twilio-Signature' => $sig,
        ]);

        [$status, ,] = $agent->handleRequest('POST', '/', $headers, $body);
        $this->assertSame(200, $status);
    }

    // ------------------------------------------------------------------
    // Real raw body forwarded byte-for-byte to handler.
    // ------------------------------------------------------------------

    public function testValidatorReceivesByteForByteRawBody(): void
    {
        // Whitespace-shaped body that would mutate if re-serialised.
        $body = '{"event":"call.state","params":{"call_id":"abc-123","state":"answered"}}';
        $url = 'https://signed-host.example.com/';
        $sig = bin2hex(hash_hmac('sha1', $url . $body, self::SIGNING_KEY, true));

        // Independently verify our test fixture matches the canonical hex digest.
        $this->assertTrue(WebhookValidator::validateWebhookSignature(
            self::SIGNING_KEY, $sig, $url, $body,
        ));

        $agent = $this->makeAgent(['signing_key' => self::SIGNING_KEY]);
        $headers = array_merge($this->authHeader(), [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'signed-host.example.com',
            'X-SignalWire-Signature' => $sig,
        ]);

        [$status, ,] = $agent->handleRequest('POST', '/', $headers, $body);
        $this->assertSame(200, $status, 'agent must accept the same body byte-for-byte that the validator accepts');
    }

    public function testReSerialisedBodyRejected(): void
    {
        // Caller MUST pass raw body; if the SDK accidentally re-serialised
        // a parsed dict, whitespace/key-order changes would invalidate the
        // signature. Verify rejection.
        $rawBody = '{"event":"call.state"}';
        $url = 'https://signed-host.example.com/';
        $sig = bin2hex(hash_hmac('sha1', $url . $rawBody, self::SIGNING_KEY, true));

        $reserialised = json_encode(json_decode($rawBody, true));
        // The bodies should differ in how they are typed — sanity:
        $this->assertSame($rawBody, $reserialised, 'baseline equal');

        // Now use a whitespace-modified body to prove signature is body-bound.
        $whitespacey = '{ "event":"call.state" }';
        $agent = $this->makeAgent(['signing_key' => self::SIGNING_KEY]);
        $headers = array_merge($this->authHeader(), [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'signed-host.example.com',
            'X-SignalWire-Signature' => $sig,
        ]);

        [$status, ,] = $agent->handleRequest('POST', '/', $headers, $whitespacey);
        $this->assertSame(403, $status);
    }
}
