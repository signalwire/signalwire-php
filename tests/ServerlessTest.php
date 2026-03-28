<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Serverless\Adapter;

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

        $this->assertSame(200, $result['statusCode']);
        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('body', $result);
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

        $this->assertArrayHasKey('statusCode', $result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('body', $result);
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

        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('body', $result);
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

        // Azure uses 'status', not 'statusCode'
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayNotHasKey('statusCode', $result);
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
        $this->assertSame('healthy', $body['status']);
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

        $agent = new class ($captured) {
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

        $agent = new class ($captured) {
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
        };

        ob_start();
        Adapter::handleCgi($agent);
        ob_end_clean();

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
    //  Helper: Create a mock agent
    // ==================================================================

    /**
     * Create a mock agent object that expects specific handleRequest args
     * and returns a predefined response.
     */
    private function createMockAgent(
        int $statusCode,
        array $responseData,
        string $expectedMethod,
        string $expectedPath,
        array $expectedHeaders,
        ?string $expectedBody,
    ): object {
        $test = $this;

        return new class ($statusCode, $responseData, $expectedMethod, $expectedPath, $expectedHeaders, $expectedBody, $test) {
            private int $statusCode;
            private array $responseData;
            private string $expectedMethod;
            private string $expectedPath;
            private array $expectedHeaders;
            private ?string $expectedBody;
            private TestCase $test;

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
                return [
                    $this->statusCode,
                    ['Content-Type' => 'application/json'],
                    $responseBody,
                ];
            }
        };
    }
}
