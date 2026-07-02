<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Serverless\Adapter;
use SignalWire\Serverless\ExecutionMode;
use SignalWire\SWML\RequestHandlerLike;
use SignalWire\Tests\Support\Shape;

class ServerlessTest extends TestCase
{
    /**
     * Environment variables to clean up after each test.
     */
    private const ENV_VARS = [
        'AWS_LAMBDA_FUNCTION_NAME',
        'FUNCTION_TARGET',
        'K_SERVICE',
        'AZURE_FUNCTIONS_ENVIRONMENT',
        'GATEWAY_INTERFACE',
    ];

    protected function setUp(): void
    {
        // Clear all serverless env vars before each test
        foreach (self::ENV_VARS as $var) {
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        // Clean up env vars after each test
        foreach (self::ENV_VARS as $var) {
            putenv($var);
        }
        // Restore $_SERVER to avoid leaking between tests
        unset(
            $_SERVER['GATEWAY_INTERFACE'],
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['PATH_INFO'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['CONTENT_TYPE'],
            $_SERVER['CONTENT_LENGTH'],
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['HTTP_ACCEPT'],
        );
    }

    // ==================================================================
    //  1. detect() — Lambda
    // ==================================================================

    public function testDetectLambda(): void
    {
        putenv('AWS_LAMBDA_FUNCTION_NAME=my-function');

        $this->assertSame('lambda', Adapter::detect());
    }

    // ==================================================================
    //  2. detect() — Google Cloud Functions (FUNCTION_TARGET)
    // ==================================================================

    public function testDetectGcfFunctionTarget(): void
    {
        putenv('FUNCTION_TARGET=myHandler');

        $this->assertSame('gcf', Adapter::detect());
    }

    // ==================================================================
    //  3. detect() — Google Cloud Functions (K_SERVICE)
    // ==================================================================

    public function testDetectGcfKService(): void
    {
        putenv('K_SERVICE=my-cloud-run-service');

        $this->assertSame('gcf', Adapter::detect());
    }

    // ==================================================================
    //  4. detect() — Azure
    // ==================================================================

    public function testDetectAzure(): void
    {
        putenv('AZURE_FUNCTIONS_ENVIRONMENT=Production');

        $this->assertSame('azure', Adapter::detect());
    }

    // ==================================================================
    //  5. detect() — CGI (via $_SERVER)
    // ==================================================================

    public function testDetectCgiViaServer(): void
    {
        $_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';

        $this->assertSame('cgi', Adapter::detect());
    }

    // ==================================================================
    //  6. detect() — CGI (via env var)
    // ==================================================================

    public function testDetectCgiViaEnv(): void
    {
        putenv('GATEWAY_INTERFACE=CGI/1.1');

        $this->assertSame('cgi', Adapter::detect());
    }

    // ==================================================================
    //  7. detect() — Server (default)
    // ==================================================================

    public function testDetectServerDefault(): void
    {
        // No serverless env vars set
        $this->assertSame('server', Adapter::detect());
    }

    // ==================================================================
    //  8. detect() — Priority: Lambda wins over GCF
    // ==================================================================

    public function testDetectLambdaPriorityOverGcf(): void
    {
        putenv('AWS_LAMBDA_FUNCTION_NAME=my-function');
        putenv('FUNCTION_TARGET=myHandler');

        $this->assertSame('lambda', Adapter::detect());
    }

    // ==================================================================
    //  9. detect() — Priority: Lambda wins over Azure
    // ==================================================================

    public function testDetectLambdaPriorityOverAzure(): void
    {
        putenv('AWS_LAMBDA_FUNCTION_NAME=my-function');
        putenv('AZURE_FUNCTIONS_ENVIRONMENT=Production');

        $this->assertSame('lambda', Adapter::detect());
    }

    // ==================================================================
    // 10. detect() — Priority: GCF wins over Azure
    // ==================================================================

    public function testDetectGcfPriorityOverAzure(): void
    {
        putenv('FUNCTION_TARGET=myHandler');
        putenv('AZURE_FUNCTIONS_ENVIRONMENT=Production');

        $this->assertSame('gcf', Adapter::detect());
    }

    // ==================================================================
    // 11. handleLambda — Basic API Gateway event
    // ==================================================================

    public function testHandleLambdaBasicEvent(): void
    {
        $agent = $this->createMockAgent(200, ['status' => 'ok'], 'GET', '/test', [], null);

        $event = [
            'httpMethod' => 'GET',
            'path'       => '/test',
            'headers'    => [],
            'body'       => null,
        ];

        $result = Adapter::handleLambda($agent, $event, new \stdClass());

        // The Lambda envelope is a fixed shape: statusCode + headers + body.
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame('application/json', $result['headers']['Content-Type']);
        $this->assertSame('{"status":"ok"}', $result['body']);
    }

    // ==================================================================
    // 12. handleLambda — POST with body
    // ==================================================================

    public function testHandleLambdaPostWithBody(): void
    {
        $expectedBody = '{"key":"value"}';
        $agent = $this->createMockAgent(200, ['received' => true], 'POST', '/api', [], $expectedBody);

        $event = [
            'httpMethod' => 'POST',
            'path'       => '/api',
            'headers'    => [],
            'body'       => $expectedBody,
        ];

        $result = Adapter::handleLambda($agent, $event, new \stdClass());

        $this->assertSame(200, $result['statusCode']);
    }

    // ==================================================================
    // 13. handleLambda — Base64 encoded body
    // ==================================================================

    public function testHandleLambdaBase64Body(): void
    {
        $rawBody = '{"data":"hello"}';
        $encoded = base64_encode($rawBody);

        $agent = $this->createMockAgent(200, ['ok' => true], 'POST', '/encoded', [], $rawBody);

        $event = [
            'httpMethod'      => 'POST',
            'path'            => '/encoded',
            'headers'         => [],
            'body'            => $encoded,
            'isBase64Encoded' => true,
        ];

        $result = Adapter::handleLambda($agent, $event, new \stdClass());

        $this->assertSame(200, $result['statusCode']);
    }

    // ==================================================================
    // 14. handleLambda — HTTP API v2 format
    // ==================================================================

    public function testHandleLambdaHttpApiV2Format(): void
    {
        $agent = $this->createMockAgent(200, ['v2' => true], 'GET', '/v2path', [], null);

        $event = [
            'requestContext' => [
                'http' => [
                    'method' => 'GET',
                ],
            ],
            'rawPath' => '/v2path',
            'headers' => [],
            'body'    => null,
        ];

        $result = Adapter::handleLambda($agent, $event, new \stdClass());

        $this->assertSame(200, $result['statusCode']);
    }

    // ==================================================================
    // 15. handleLambda — Headers passed through
    // ==================================================================

    public function testHandleLambdaHeadersPassedThrough(): void
    {
        $expectedHeaders = ['Authorization' => 'Basic dGVzdDp0ZXN0'];
        $agent = $this->createMockAgent(200, ['auth' => true], 'GET', '/', $expectedHeaders, null);

        $event = [
            'httpMethod' => 'GET',
            'path'       => '/',
            'headers'    => $expectedHeaders,
            'body'       => null,
        ];

        $result = Adapter::handleLambda($agent, $event, new \stdClass());

        $this->assertSame(200, $result['statusCode']);
    }

    // ==================================================================
    // 16. handleLambda — Response structure
    // ==================================================================

    public function testHandleLambdaResponseStructure(): void
    {
        $agent = $this->createMockAgent(404, ['error' => 'Not Found'], 'GET', '/missing', [], null);

        $event = [
            'httpMethod' => 'GET',
            'path'       => '/missing',
            'headers'    => [],
            'body'       => null,
        ];

        $result = Adapter::handleLambda($agent, $event, new \stdClass());

        $this->assertSame(404, $result['statusCode']);
    }

    // ==================================================================
    // 17. handleAzure — Basic request
    // ==================================================================

    public function testHandleAzureBasicRequest(): void
    {
        $agent = $this->createMockAgent(200, ['azure' => true], 'GET', '/func', [], null);

        $request = [
            'method'  => 'GET',
            'url'     => 'https://myapp.azurewebsites.net/func',
            'headers' => [],
            'body'    => null,
        ];

        $result = Adapter::handleAzure($agent, $request);

        // The Azure envelope is a fixed shape: status + headers + body.
        $this->assertSame(200, $result['status']);
        $this->assertSame('application/json', $result['headers']['Content-Type']);
    }

    // ==================================================================
    // 18. handleAzure — POST with body
    // ==================================================================

    public function testHandleAzurePostWithBody(): void
    {
        $body = '{"input":"data"}';
        $agent = $this->createMockAgent(200, ['processed' => true], 'POST', '/api/func', [], $body);

        $request = [
            'method'  => 'POST',
            'url'     => 'https://host/api/func',
            'headers' => [],
            'body'    => $body,
        ];

        $result = Adapter::handleAzure($agent, $request);

        $this->assertSame(200, $result['status']);
    }

    // ==================================================================
    // 19. handleAzure — Response uses 'status' not 'statusCode'
    // ==================================================================

    public function testHandleAzureResponseFormat(): void
    {
        $agent = $this->createMockAgent(201, ['created' => true], 'POST', '/create', [], '{}');

        $request = [
            'method'  => 'POST',
            'url'     => 'https://host/create',
            'headers' => [],
            'body'    => '{}',
        ];

        $result = Adapter::handleAzure($agent, $request);

        // Azure uses 'status', not 'statusCode' (enforced by the envelope shape).
        $this->assertSame(201, $result['status']);
    }

    // ==================================================================
    // 20. handleCgi — Output format
    // ==================================================================

    public function testHandleCgiOutputFormat(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['PATH_INFO']      = '/health';

        $agent = $this->createMockAgent(200, ['status' => 'healthy'], 'GET', '/health', [], null);

        ob_start();
        Adapter::handleCgi($agent);
        $output = ob_get_clean();
        $this->assertNotFalse($output);

        // Should start with Status line
        $this->assertStringStartsWith('Status: 200 OK', $output);

        // Should contain Content-Type header
        $this->assertStringContainsString('Content-Type: application/json', $output);

        // Should contain a blank line separator
        $this->assertStringContainsString("\r\n\r\n", $output);

        // Should contain the JSON body after the blank line
        $parts = explode("\r\n\r\n", $output, 2);
        $this->assertCount(2, $parts);
        $body = json_decode($parts[1], true);
        $this->assertSame('healthy', Shape::at($body, 'status'));
    }

    // ==================================================================
    // 21. handleCgi — POST request
    // ==================================================================

    public function testHandleCgiPostRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['PATH_INFO']      = '/swaig';
        $_SERVER['CONTENT_TYPE']   = 'application/json';

        $agent = $this->createMockAgent(200, ['dispatched' => true], 'POST', '/swaig', [], null);

        ob_start();
        Adapter::handleCgi($agent);
        $output = ob_get_clean();
        $this->assertNotFalse($output);

        $this->assertStringStartsWith('Status: 200 OK', $output);
    }

    // ==================================================================
    // 22. handleCgi — 404 response
    // ==================================================================

    public function testHandleCgi404Response(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['PATH_INFO']      = '/unknown';

        $agent = $this->createMockAgent(404, ['error' => 'Not Found'], 'GET', '/unknown', [], null);

        ob_start();
        Adapter::handleCgi($agent);
        $output = ob_get_clean();
        $this->assertNotFalse($output);

        $this->assertStringStartsWith('Status: 404 Not Found', $output);
    }

    // ==================================================================
    // 23. handleCgi — Query string stripped from path
    // ==================================================================

    public function testHandleCgiStripsQueryString(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['PATH_INFO']      = '/test?foo=bar';

        // Path passed to handleRequest should be /test without query string
        $agent = $this->createMockAgent(200, ['ok' => true], 'GET', '/test', [], null);

        ob_start();
        Adapter::handleCgi($agent);
        $output = ob_get_clean();
        $this->assertNotFalse($output);

        $this->assertStringStartsWith('Status: 200 OK', $output);
    }

    // ==================================================================
    // 24. handleCgi — Headers extracted from HTTP_* vars
    // ==================================================================

    public function testHandleCgiExtractsHttpHeaders(): void
    {
        $_SERVER['REQUEST_METHOD']    = 'GET';
        $_SERVER['PATH_INFO']         = '/';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dGVzdDp0ZXN0';
        $_SERVER['HTTP_ACCEPT']        = 'application/json';

        $captured = null;

        $agent = new class ($captured) implements RequestHandlerLike {
            // @phpstan-ignore property.onlyWritten (reference-bound to outer $captured; writes here propagate out, no direct read)
            private mixed $ref;

            public function __construct(mixed &$ref)
            {
                $this->ref = &$ref;
            }

            /**
             * @return array{int, array<string, string>, string}
             */
            public function handleRequest(string $method, string $path, array $headers = [], ?string $body = null): array
            {
                $this->ref = $headers;
                return [200, ['Content-Type' => 'application/json'], '{"ok":true}'];
            }

            public function run(): void
            {
            }
        };

        ob_start();
        Adapter::handleCgi($agent);
        ob_end_clean();

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('Authorization', $captured);
        $this->assertSame('Basic dGVzdDp0ZXN0', $captured['Authorization']);
        $this->assertArrayHasKey('Accept', $captured);
        $this->assertSame('application/json', $captured['Accept']);
    }

    // ==================================================================
    // 25. handleCgi — Content-Type header extracted
    // ==================================================================

    public function testHandleCgiExtractsContentType(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['PATH_INFO']      = '/';
        $_SERVER['CONTENT_TYPE']   = 'application/json';

        $captured = null;

        $agent = new class ($captured) implements RequestHandlerLike {
            // @phpstan-ignore property.onlyWritten (reference-bound to outer $captured; writes here propagate out, no direct read)
            private mixed $ref;

            public function __construct(mixed &$ref)
            {
                $this->ref = &$ref;
            }

            /**
             * @return array{int, array<string, string>, string}
             */
            public function handleRequest(string $method, string $path, array $headers = [], ?string $body = null): array
            {
                $this->ref = $headers;
                return [200, ['Content-Type' => 'application/json'], '{}'];
            }

            public function run(): void
            {
            }
        };

        ob_start();
        Adapter::handleCgi($agent);
        ob_end_clean();

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('Content-Type', $captured);
        $this->assertSame('application/json', $captured['Content-Type']);
    }

    // ==================================================================
    // 26. handleLambda — Empty event defaults
    // ==================================================================

    public function testHandleLambdaEmptyEventDefaults(): void
    {
        $agent = $this->createMockAgent(200, ['default' => true], 'GET', '/', [], null);

        $result = Adapter::handleLambda($agent, [], new \stdClass());

        $this->assertSame(200, $result['statusCode']);
    }

    // ==================================================================
    // 27. handleAzure — PascalCase key support
    // ==================================================================

    public function testHandleAzurePascalCaseKeys(): void
    {
        $agent = $this->createMockAgent(200, ['ok' => true], 'POST', '/func', ['X-Custom' => 'val'], '{"data":1}');

        $request = [
            'Method'  => 'POST',
            'Url'     => 'https://host/func',
            'Headers' => ['X-Custom' => 'val'],
            'Body'    => '{"data":1}',
        ];

        $result = Adapter::handleAzure($agent, $request);

        $this->assertSame(200, $result['status']);
    }

    // ==================================================================
    // 28. ExecutionMode — each case backs its exact wire/dispatch string
    // ==================================================================

    public function testExecutionModeBackingStrings(): void
    {
        // The enum's value set is exactly the strings detect() can return.
        // (Iterating cases() keeps this a real runtime check of every backing
        // string rather than statically-folded per-case comparisons.)
        $values = array_map(fn (ExecutionMode $m) => $m->value, ExecutionMode::cases());
        sort($values);
        $this->assertSame(['azure', 'cgi', 'gcf', 'lambda', 'server'], $values);
    }

    // ==================================================================
    // 29. detectMode() returns the typed mode and round-trips with detect()
    // ==================================================================

    public function testDetectModeTypedAndRoundTrips(): void
    {
        // detect() returns exactly detectMode()->value, so the two round-trip.
        // We prove the round-trip once (below) and then, per environment,
        // assert the typed mode and its bare-string counterpart independently.
        $this->assertSame(Adapter::detect(), Adapter::detectMode()->value);

        // Default (server) environment.
        $this->assertSame(ExecutionMode::Server, Adapter::detectMode());
        $this->assertSame('server', Adapter::detect());

        putenv('AWS_LAMBDA_FUNCTION_NAME=fn');
        $this->assertSame(ExecutionMode::Lambda, Adapter::detectMode());
        $this->assertSame('lambda', Adapter::detect());
        putenv('AWS_LAMBDA_FUNCTION_NAME');

        putenv('AZURE_FUNCTIONS_ENVIRONMENT=Production');
        $this->assertSame(ExecutionMode::Azure, Adapter::detectMode());
        $this->assertSame('azure', Adapter::detect());
        putenv('AZURE_FUNCTIONS_ENVIRONMENT');

        $_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';
        $this->assertSame(ExecutionMode::Cgi, Adapter::detectMode());
        $this->assertSame('cgi', Adapter::detect());
    }

    // ==================================================================
    // 30. isServerless() — server is the only non-serverless mode
    // ==================================================================

    public function testExecutionModeIsServerless(): void
    {
        $this->assertFalse(ExecutionMode::Server->isServerless());
        $this->assertTrue(ExecutionMode::Lambda->isServerless());
        $this->assertTrue(ExecutionMode::Gcf->isServerless());
        $this->assertTrue(ExecutionMode::Azure->isServerless());
        $this->assertTrue(ExecutionMode::Cgi->isServerless());
    }

    // ==================================================================
    // 31. coerce() accepts the enum OR its backing string
    // ==================================================================

    public function testExecutionModeCoerceAcceptsEnumAndString(): void
    {
        // String arm (parity with the stringly-typed original).
        $this->assertSame(ExecutionMode::Azure, ExecutionMode::coerce('azure'));
        $this->assertSame(ExecutionMode::Cgi, ExecutionMode::coerce('cgi'));

        // Enum arm (passthrough).
        $this->assertSame(ExecutionMode::Lambda, ExecutionMode::coerce(ExecutionMode::Lambda));

        // Out-of-set string is rejected loudly.
        $this->expectException(\ValueError::class);
        ExecutionMode::coerce('totally-bogus');
    }

    // ==================================================================
    // 32. serve($agent, ExecutionMode::Server) dispatches to agent->run()
    // ==================================================================

    public function testServeWithEnumServerCallsRun(): void
    {
        $agent = $this->makeRecordingAgent();

        Adapter::serve($agent, ExecutionMode::Server);

        $this->assertTrue($agent->ran, 'serve(Server) should call agent->run()');
        $this->assertSame(0, $agent->handleRequestCalls, 'no request handling in server mode');
    }

    // ==================================================================
    // 33. serve($agent, 'server') — string accepted ALONGSIDE the enum
    // ==================================================================

    public function testServeWithStringServerCallsRun(): void
    {
        $agent = $this->makeRecordingAgent();

        Adapter::serve($agent, 'server');

        $this->assertTrue($agent->ran, "serve('server') should behave identically to serve(ExecutionMode::Server)");
        $this->assertSame(0, $agent->handleRequestCalls);
    }

    // ==================================================================
    // 34. serve($agent, ExecutionMode::Azure) dispatches to handleAzure path
    //     (real behavior: handleRequest invoked, JSON response emitted)
    // ==================================================================

    public function testServeWithEnumAzureDispatchesToHandler(): void
    {
        $agent = $this->makeRecordingAgent();

        // serve() reads php://input for azure; with no body it parses to []
        // and still drives handleAzure -> agent->handleRequest.
        ob_start();
        Adapter::serve($agent, ExecutionMode::Azure);
        $output = ob_get_clean();
        $this->assertNotFalse($output);

        $this->assertFalse($agent->ran, 'azure mode must NOT call run()');
        $this->assertSame(1, $agent->handleRequestCalls, 'azure mode drives handleRequest exactly once');

        // The emitted Azure envelope is JSON with a status field.
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(200, Shape::at($decoded, 'status'));
    }

    // ==================================================================
    // 35. serve($agent, 'azure') — string arm reaches the same handler
    // ==================================================================

    public function testServeWithStringAzureDispatchesToHandler(): void
    {
        $agent = $this->makeRecordingAgent();

        ob_start();
        Adapter::serve($agent, 'azure');
        $output = ob_get_clean();
        $this->assertNotFalse($output);

        $this->assertFalse($agent->ran);
        $this->assertSame(1, $agent->handleRequestCalls);
        $decoded = json_decode($output, true);
        $this->assertSame(200, Shape::at($decoded, 'status'));
    }

    // ==================================================================
    // 36. serve($agent, <bad string>) raises ValueError (no silent fallback)
    // ==================================================================

    public function testServeWithUnknownModeStringRaises(): void
    {
        $agent = $this->makeRecordingAgent();

        $this->expectException(\ValueError::class);
        Adapter::serve($agent, 'not-a-real-mode');
    }

    // ==================================================================
    // 37. serve($agent) with no mode auto-detects (server by default)
    // ==================================================================

    public function testServeNoModeAutoDetectsServer(): void
    {
        $agent = $this->makeRecordingAgent();

        // No serverless env set (setUp cleared them) -> auto-detect server.
        Adapter::serve($agent);

        $this->assertTrue($agent->ran, 'default env auto-detects server -> run()');
    }

    // ==================================================================
    //  Helper: a real recording agent (NOT a PHPUnit mock — a concrete
    //  object whose handleRequest()/run() record that they were invoked).
    // ==================================================================

    private function makeRecordingAgent(): RecordingAgent
    {
        return new RecordingAgent();
    }

    // ==================================================================
    //  Helper: Create a mock agent
    // ==================================================================

    /**
     * Create a mock agent object that expects specific handleRequest args
     * and returns a predefined response.
     *
     * @param array<string, mixed>  $responseData
     * @param array<string, string> $expectedHeaders
     */
    private function createMockAgent(
        int $statusCode,
        array $responseData,
        string $expectedMethod,
        string $expectedPath,
        array $expectedHeaders,
        ?string $expectedBody,
    ): RequestHandlerLike {
        $test = $this;

        return new class ($statusCode, $responseData, $expectedMethod, $expectedPath, $expectedHeaders, $expectedBody, $test) implements RequestHandlerLike {
            private int $statusCode;
            /** @var array<string, mixed> */
            private array $responseData;
            private string $expectedMethod;
            private string $expectedPath;
            /** @var array<string, string> */
            private array $expectedHeaders;
            private ?string $expectedBody;
            private TestCase $test;

            /**
             * @param array<string, mixed>  $responseData
             * @param array<string, string> $expectedHeaders
             */
            public function __construct(
                int $statusCode,
                array $responseData,
                string $expectedMethod,
                string $expectedPath,
                array $expectedHeaders,
                ?string $expectedBody,
                TestCase $test,
            ) {
                $this->statusCode      = $statusCode;
                $this->responseData    = $responseData;
                $this->expectedMethod  = $expectedMethod;
                $this->expectedPath    = $expectedPath;
                $this->expectedHeaders = $expectedHeaders;
                $this->expectedBody    = $expectedBody;
                $this->test            = $test;
            }

            /**
             * @return array{int, array<string, string>, string}
             */
            public function handleRequest(string $method, string $path, array $headers = [], ?string $body = null): array
            {
                $this->test->assertSame($this->expectedMethod, $method);
                $this->test->assertSame($this->expectedPath, $path);

                foreach ($this->expectedHeaders as $key => $value) {
                    $this->test->assertArrayHasKey($key, $headers);
                    $this->test->assertSame($value, $headers[$key]);
                }

                if ($this->expectedBody !== null) {
                    $this->test->assertSame($this->expectedBody, $body);
                }

                $responseBody = json_encode($this->responseData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->test->assertNotFalse($responseBody);
                return [
                    $this->statusCode,
                    ['Content-Type' => 'application/json'],
                    $responseBody,
                ];
            }

            public function run(): void
            {
                // No-op: the serverless dispatch tests never invoke the
                // blocking serve loop (RequestHandlerLike contract method).
            }
        };
    }
}

/**
 * A concrete recording agent (NOT a PHPUnit mock) whose run()/handleRequest()
 * record that they were invoked. Named (rather than anonymous) so the helper's
 * return type carries both the RequestHandlerLike contract and the public
 * counters the serve() dispatch tests read.
 */
final class RecordingAgent implements RequestHandlerLike
{
    public bool $ran = false;
    public int $handleRequestCalls = 0;

    public function run(): void
    {
        $this->ran = true;
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    public function handleRequest(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $this->handleRequestCalls++;
        return [200, ['Content-Type' => 'application/json'], '{"ok":true}'];
    }
}
