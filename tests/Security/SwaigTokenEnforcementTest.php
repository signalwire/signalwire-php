<?php

declare(strict_types=1);

namespace SignalWire\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\Logging\Logger;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\SWML\Schema;
use SignalWire\Tests\Support\InputStreamStub;

/**
 * `secure=true` SWAIG tools REQUIRE a valid per-call `__token`, on EVERY
 * transport.
 *
 * The contract, identical for the direct HTTP dispatcher and for every
 * serverless envelope (lambda / azure_function / google_cloud_function / cgi),
 * all of which funnel through {@see \SignalWire\SWML\Service::handleRequest()}:
 *
 *   valid token   -> the handler RUNS
 *   forged token  -> the handler does NOT run; REFUSED
 *   absent token  -> the handler does NOT run; REFUSED (fail-CLOSED — omitting
 *                    the credential must never be weaker than presenting a
 *                    wrong one, or `secure` would permit anonymous calls)
 *   absent call_id-> REFUSED; a token is only meaningful bound to a call_id,
 *                    so with none there is nothing to validate it against
 *   insecure tool -> RUNS ungated in all four cases
 *
 * The refusal is delivered as a **200 + FunctionResult body**, never an HTTP
 * error status: the engine (mod_openai) has no handling for a SWAIG refusal
 * status, so the tool reports it cannot execute and the model relays that.
 */
class SwaigTokenEnforcementTest extends TestCase
{
    private const USER = 'u';
    private const PASS = 'p';

    private const REFUSAL = "I'm sorry, the security token for this function is invalid "
        . 'or expired. I cannot execute this action.';

    protected function setUp(): void
    {
        Schema::reset();
        Logger::reset();
    }

    protected function tearDown(): void
    {
        Schema::reset();
        Logger::reset();
    }

    /** An agent at route "/" with one secure tool and one insecure tool. */
    private function agent(): AgentBase
    {
        $a = new AgentBase(
            name: 'demo',
            route: '/',
            basicAuthUser: self::USER,
            basicAuthPassword: self::PASS,
        );
        $a->defineTool(
            name: 'say_hello',
            description: 'greet',
            parameters: [],
            handler: static fn (array $args, array $rawData): FunctionResult
                => new FunctionResult('hello there'),
            secure: true,
        );
        $a->defineTool(
            name: 'open_hello',
            description: 'greet, ungated',
            parameters: [],
            handler: static fn (array $args, array $rawData): FunctionResult
                => new FunctionResult('open hello'),
            secure: false,
        );
        return $a;
    }

    /** @return array<string,string> */
    private function auth(): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode(self::USER . ':' . self::PASS),
            'Content-Type'  => 'application/json',
        ];
    }

    private function body(string $function, ?string $callId): string
    {
        $payload = ['function' => $function, 'argument' => ['parsed' => [[]]]];
        if ($callId !== null) {
            $payload['call_id'] = $callId;
        }
        $json = json_encode($payload);
        $this->assertIsString($json);
        return $json;
    }

    /**
     * Drive the DIRECT HTTP dispatcher. `$query` is the raw query string
     * (without the leading `?`), or null for none.
     *
     * @return array{int, array<string,mixed>}
     */
    private function viaHttp(AgentBase $a, string $function, ?string $callId, ?string $query): array
    {
        $path = '/swaig' . ($query === null || $query === '' ? '' : '?' . $query);
        [$status, , $bodyStr] = $a->handleRequest('POST', $path, $this->auth(), $this->body($function, $callId));
        $decoded = json_decode($bodyStr, true);
        return [$status, is_array($decoded) ? $decoded : []];
    }

    /**
     * Drive the SERVERLESS (lambda) envelope. The token rides
     * `queryStringParameters`; the call_id rides the POST body.
     *
     * @return array{int, array<string,mixed>}
     */
    private function viaLambda(AgentBase $a, string $function, ?string $callId, ?string $token): array
    {
        $event = [
            'rawPath'        => '/swaig',
            'headers'        => array_change_key_case($this->auth(), CASE_LOWER),
            'body'           => $this->body($function, $callId),
            'requestContext' => ['http' => ['method' => 'POST']],
        ];
        if ($token !== null) {
            $event['queryStringParameters'] = ['__token' => $token];
        }
        $resp = $a->handleServerlessRequest(event: $event, mode: 'lambda');
        $this->assertIsArray($resp);
        $bodyStr = is_string($resp['body'] ?? null) ? $resp['body'] : '';
        $decoded = json_decode($bodyStr, true);
        $status  = $resp['statusCode'] ?? null;
        $this->assertIsInt($status);
        return [$status, is_array($decoded) ? $decoded : []];
    }

    /**
     * Drive the AZURE FUNCTIONS envelope. The token rides the request `url`
     * query; the call_id rides the POST body. Azure answers with `status`,
     * not `statusCode`.
     *
     * @return array{int, array<string,mixed>}
     */
    private function viaAzure(AgentBase $a, string $function, ?string $callId, ?string $token): array
    {
        $query = $token === null ? '' : '?__token=' . rawurlencode($token);
        $request = [
            'method'  => 'POST',
            'url'     => 'https://demo.azurewebsites.net/swaig' . $query,
            'headers' => $this->auth(),
            'body'    => $this->body($function, $callId),
        ];

        $resp = $a->handleServerlessRequest(event: $request, mode: 'azure_function');
        $this->assertIsArray($resp);
        $bodyStr = is_string($resp['body'] ?? null) ? $resp['body'] : '';
        $decoded = json_decode($bodyStr, true);
        $status  = $resp['status'] ?? null;
        $this->assertIsInt($status);
        return [$status, is_array($decoded) ? $decoded : []];
    }

    /**
     * Drive the GOOGLE CLOUD FUNCTION or CGI envelope. Both read the request
     * from `$_SERVER` plus `php://input` and WRITE the response to the output
     * stream rather than returning it, so the body is captured from the
     * buffer. CGI prefixes a `Status:`/header block terminated by a blank
     * line; GCF echoes the body alone.
     *
     * The status is read back from the decoded FunctionResult path rather
     * than the envelope, because GCF's status goes through
     * `http_response_code()`, which is a no-op once output has started.
     *
     * @return array<string,mixed>
     */
    private function viaOutputStream(
        AgentBase $a,
        string $mode,
        string $function,
        ?string $callId,
        ?string $token,
    ): array {
        $query = $token === null ? '' : '__token=' . rawurlencode($token);
        $body  = $this->body($function, $callId);

        $prevServer = $_SERVER;
        $_SERVER['REQUEST_METHOD']     = 'POST';
        $_SERVER['PATH_INFO']          = '/swaig';
        $_SERVER['REQUEST_URI']        = '/swaig' . ($query === '' ? '' : '?' . $query);
        $_SERVER['QUERY_STRING']       = $query;
        $_SERVER['CONTENT_TYPE']       = 'application/json';
        $_SERVER['CONTENT_LENGTH']     = (string) strlen($body);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode(self::USER . ':' . self::PASS);

        InputStreamStub::install($body);
        ob_start();
        try {
            $a->handleServerlessRequest(event: null, mode: $mode);
            $out = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            InputStreamStub::uninstall();
            $_SERVER = $prevServer;
            throw $e;
        }
        InputStreamStub::uninstall();
        $_SERVER = $prevServer;

        // Strip the CGI header block when present; GCF emits the body alone.
        $parts   = explode("\r\n\r\n", $out, 2);
        $bodyStr = count($parts) === 2 ? $parts[1] : $out;
        $decoded = json_decode($bodyStr, true);

        return is_array($decoded) ? $decoded : [];
    }

    // ------------------------------------------------------------------
    // HTTP transport
    // ------------------------------------------------------------------

    public function testHttpValidTokenRunsTheSecureHandler(): void
    {
        $a = $this->agent();
        $token = $a->createToolToken('say_hello', 'c1');
        $this->assertNotSame('', $token);

        [$status, $body] = $this->viaHttp($a, 'say_hello', 'c1', '__token=' . rawurlencode($token));

        $this->assertSame(200, $status);
        $this->assertSame('hello there', $body['response'] ?? null, 'a VALID token must let the secure handler run');
    }

    public function testHttpForgedTokenRefusesWithoutRunningTheHandler(): void
    {
        $a = $this->agent();
        [$status, $body] = $this->viaHttp($a, 'say_hello', 'c1', '__token=' . rawurlencode('forged.token.value'));

        $this->assertSame(200, $status, 'a refusal is a 200 + FunctionResult body, not an HTTP error status');
        $this->assertSame(self::REFUSAL, $body['response'] ?? null, 'a FORGED token must be refused');
    }

    public function testHttpAbsentTokenRefusesFailClosed(): void
    {
        $a = $this->agent();
        [$status, $body] = $this->viaHttp($a, 'say_hello', 'c1', null);

        $this->assertSame(200, $status);
        $this->assertSame(self::REFUSAL, $body['response'] ?? null, 'an ABSENT token must fail CLOSED, not open');
    }

    public function testHttpAbsentCallIdRefusesEvenWithAMintedToken(): void
    {
        $a = $this->agent();
        $token = $a->createToolToken('say_hello', 'c1');
        [$status, $body] = $this->viaHttp($a, 'say_hello', null, '__token=' . rawurlencode($token));

        $this->assertSame(200, $status);
        $this->assertSame(
            self::REFUSAL,
            $body['response'] ?? null,
            'without a call_id there is nothing to validate the token against — never a bypass',
        );
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function insecureCases(): iterable
    {
        yield 'valid-shaped token' => ['c1', '__token=whatever'];
        yield 'forged token'       => ['c1', '__token=forged'];
        yield 'absent token'       => ['c1', null];
        yield 'absent call_id'     => [null, '__token=forged'];
    }

    #[DataProvider('insecureCases')]
    public function testHttpInsecureToolRunsUngatedInEveryCase(?string $callId, ?string $query): void
    {
        $a = $this->agent();
        [$status, $body] = $this->viaHttp($a, 'open_hello', $callId, $query);

        $this->assertSame(200, $status);
        $this->assertSame('open hello', $body['response'] ?? null, 'a secure=false tool must never be gated');
    }

    // ------------------------------------------------------------------
    // Serverless (lambda) transport — same contract, different envelope
    // ------------------------------------------------------------------

    public function testServerlessValidTokenRunsTheSecureHandler(): void
    {
        $a = $this->agent();
        $token = $a->createToolToken('say_hello', 'c1');
        $this->assertNotSame('', $token);

        [$status, $body] = $this->viaLambda($a, 'say_hello', 'c1', $token);

        $this->assertSame(200, $status);
        $this->assertSame('hello there', $body['response'] ?? null, 'a VALID token must let the secure handler run');
    }

    public function testServerlessForgedTokenRefusesWithoutRunningTheHandler(): void
    {
        $a = $this->agent();
        [$status, $body] = $this->viaLambda($a, 'say_hello', 'c1', 'forged.token.value');

        $this->assertSame(200, $status, 'a refusal is a 200 + FunctionResult body, not an HTTP error status');
        $this->assertSame(self::REFUSAL, $body['response'] ?? null, 'a FORGED token must be refused on serverless too');
    }

    public function testServerlessAbsentTokenRefusesFailClosed(): void
    {
        $a = $this->agent();
        [$status, $body] = $this->viaLambda($a, 'say_hello', 'c1', null);

        $this->assertSame(200, $status);
        $this->assertSame(self::REFUSAL, $body['response'] ?? null, 'serverless is not a weaker transport');
    }

    public function testServerlessAbsentCallIdRefusesEvenWithAMintedToken(): void
    {
        $a = $this->agent();
        $token = $a->createToolToken('say_hello', 'c1');
        [$status, $body] = $this->viaLambda($a, 'say_hello', null, $token);

        $this->assertSame(200, $status);
        $this->assertSame(self::REFUSAL, $body['response'] ?? null);
    }

    #[DataProvider('insecureServerlessCases')]
    public function testServerlessInsecureToolRunsUngatedInEveryCase(?string $callId, ?string $token): void
    {
        $a = $this->agent();
        [$status, $body] = $this->viaLambda($a, 'open_hello', $callId, $token);

        $this->assertSame(200, $status);
        $this->assertSame('open hello', $body['response'] ?? null, 'a secure=false tool must never be gated');
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function insecureServerlessCases(): iterable
    {
        yield 'token present' => ['c1', 'whatever'];
        yield 'forged token'  => ['c1', 'forged'];
        yield 'absent token'  => ['c1', null];
        yield 'absent call_id' => [null, 'forged'];
    }

    // ------------------------------------------------------------------
    // EVERY serverless transport, not just lambda
    //
    // The four serverless envelopes reach handleRequest() by four DIFFERENT
    // routes — lambda parses `queryStringParameters`, azure_function parses
    // the `url` query, and google_cloud_function/cgi read `$_SERVER` +
    // `php://input` — so a change to any one of them (a renamed mode token, a
    // reshaped path, a dropped query string) can un-wire `__token`
    // enforcement on that transport ALONE while the others stay green.
    // Proving the contract on lambda alone therefore proved nothing about the
    // other three. These cases close that hole.
    // ------------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function outputStreamModes(): iterable
    {
        yield 'google_cloud_function' => ['google_cloud_function'];
        yield 'cgi' => ['cgi'];
    }

    public function testAzureValidTokenRunsTheSecureHandler(): void
    {
        $a = $this->agent();
        $token = $a->createToolToken('say_hello', 'c1');
        [$status, $body] = $this->viaAzure($a, 'say_hello', 'c1', $token);

        $this->assertSame(200, $status);
        $this->assertSame('hello there', $body['response'] ?? null);
    }

    public function testAzureForgedTokenRefusesWithoutRunningTheHandler(): void
    {
        $a = $this->agent();
        [$status, $body] = $this->viaAzure($a, 'say_hello', 'c1', 'forged.token.value');

        $this->assertSame(200, $status);
        $this->assertSame(self::REFUSAL, $body['response'] ?? null);
    }

    public function testAzureAbsentTokenRefusesFailClosed(): void
    {
        $a = $this->agent();
        [$status, $body] = $this->viaAzure($a, 'say_hello', 'c1', null);

        $this->assertSame(200, $status);
        $this->assertSame(self::REFUSAL, $body['response'] ?? null, 'azure_function is not a weaker transport');
    }

    #[DataProvider('outputStreamModes')]
    public function testOutputStreamValidTokenRunsTheSecureHandler(string $mode): void
    {
        $a = $this->agent();
        $token = $a->createToolToken('say_hello', 'c1');
        $body = $this->viaOutputStream($a, $mode, 'say_hello', 'c1', $token);

        $this->assertSame('hello there', $body['response'] ?? null, "{$mode}: a VALID token must let the secure handler run");
    }

    #[DataProvider('outputStreamModes')]
    public function testOutputStreamForgedTokenRefusesWithoutRunningTheHandler(string $mode): void
    {
        $a = $this->agent();
        $body = $this->viaOutputStream($a, $mode, 'say_hello', 'c1', 'forged.token.value');

        $this->assertSame(self::REFUSAL, $body['response'] ?? null, "{$mode}: a FORGED token must be refused");
    }

    #[DataProvider('outputStreamModes')]
    public function testOutputStreamAbsentTokenRefusesFailClosed(string $mode): void
    {
        $a = $this->agent();
        $body = $this->viaOutputStream($a, $mode, 'say_hello', 'c1', null);

        $this->assertSame(self::REFUSAL, $body['response'] ?? null, "{$mode} is not a weaker transport");
    }

    // ------------------------------------------------------------------
    // The token cannot be replayed across functions or calls
    // ------------------------------------------------------------------

    public function testTokenMintedForAnotherFunctionIsRefused(): void
    {
        $a = $this->agent();
        $token = $a->createToolToken('open_hello', 'c1');
        [, $body] = $this->viaHttp($a, 'say_hello', 'c1', '__token=' . rawurlencode($token));

        $this->assertSame(self::REFUSAL, $body['response'] ?? null, 'a token is bound to ONE function');
    }

    public function testTokenMintedForAnotherCallIsRefused(): void
    {
        $a = $this->agent();
        $token = $a->createToolToken('say_hello', 'other-call');
        [, $body] = $this->viaHttp($a, 'say_hello', 'c1', '__token=' . rawurlencode($token));

        $this->assertSame(self::REFUSAL, $body['response'] ?? null, 'a token is bound to ONE call_id');
    }

    // ------------------------------------------------------------------
    // The bare `token` alias the reference also accepts
    // ------------------------------------------------------------------

    public function testHttpBareTokenQueryParamIsAlsoAccepted(): void
    {
        $a = $this->agent();
        $token = $a->createToolToken('say_hello', 'c1');
        [$status, $body] = $this->viaHttp($a, 'say_hello', 'c1', 'token=' . rawurlencode($token));

        $this->assertSame(200, $status);
        $this->assertSame('hello there', $body['response'] ?? null);
    }
}
