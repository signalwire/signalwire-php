<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Logging\LoggingConfig;

/**
 * Cross-language parity tests for
 * LoggingConfig::getExecutionMode() and LoggingConfig::isServerlessMode().
 * Mirrors the Python reference at
 * signalwire-python/tests/unit/utils/test_execution_mode.py.
 */
class LoggingConfigTest extends TestCase
{
    /** Every env var the function inspects — clear all of them per test. */
    private const ENV_KEYS = [
        'GATEWAY_INTERFACE',
        'AWS_LAMBDA_FUNCTION_NAME',
        'LAMBDA_TASK_ROOT',
        'FUNCTION_TARGET',
        'K_SERVICE',
        'GOOGLE_CLOUD_PROJECT',
        'AZURE_FUNCTIONS_ENVIRONMENT',
        'FUNCTIONS_WORKER_RUNTIME',
        'AzureWebJobsStorage',
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $k) {
            $this->saved[$k] = getenv($k);
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === false) {
                putenv($k);
            } else {
                putenv("{$k}={$v}");
            }
        }
    }

    private function setEnv(string $k, string $v): void
    {
        putenv("{$k}={$v}");
    }

    // ------------------------------------------------------------------
    // getExecutionMode — every branch.
    // ------------------------------------------------------------------

    public function testDefaultIsServer(): void
    {
        $this->assertSame('server', LoggingConfig::getExecutionMode());
    }

    public function testCgiViaGatewayInterface(): void
    {
        $this->setEnv('GATEWAY_INTERFACE', 'CGI/1.1');
        $this->assertSame('cgi', LoggingConfig::getExecutionMode());
    }

    public function testLambdaViaFunctionName(): void
    {
        $this->setEnv('AWS_LAMBDA_FUNCTION_NAME', 'my-fn');
        $this->assertSame('lambda', LoggingConfig::getExecutionMode());
    }

    public function testLambdaViaTaskRoot(): void
    {
        $this->setEnv('LAMBDA_TASK_ROOT', '/var/task');
        $this->assertSame('lambda', LoggingConfig::getExecutionMode());
    }

    public function testGoogleCloudFunctionViaFunctionTarget(): void
    {
        $this->setEnv('FUNCTION_TARGET', 'my_handler');
        $this->assertSame('google_cloud_function', LoggingConfig::getExecutionMode());
    }

    public function testGoogleCloudFunctionViaKService(): void
    {
        $this->setEnv('K_SERVICE', 'svc');
        $this->assertSame('google_cloud_function', LoggingConfig::getExecutionMode());
    }

    public function testGoogleCloudFunctionViaProject(): void
    {
        $this->setEnv('GOOGLE_CLOUD_PROJECT', 'proj');
        $this->assertSame('google_cloud_function', LoggingConfig::getExecutionMode());
    }

    public function testAzureFunctionViaEnvironment(): void
    {
        $this->setEnv('AZURE_FUNCTIONS_ENVIRONMENT', 'Production');
        $this->assertSame('azure_function', LoggingConfig::getExecutionMode());
    }

    public function testAzureFunctionViaWorkerRuntime(): void
    {
        $this->setEnv('FUNCTIONS_WORKER_RUNTIME', 'php');
        $this->assertSame('azure_function', LoggingConfig::getExecutionMode());
    }

    public function testAzureFunctionViaWebJobsStorage(): void
    {
        $this->setEnv('AzureWebJobsStorage', 'DefaultEndpointsProtocol=https');
        $this->assertSame('azure_function', LoggingConfig::getExecutionMode());
    }

    /** CGI must beat Lambda — cross-language precedence contract. */
    public function testCgiBeatsLambda(): void
    {
        $this->setEnv('GATEWAY_INTERFACE', 'CGI/1.1');
        $this->setEnv('AWS_LAMBDA_FUNCTION_NAME', 'my-fn');
        $this->assertSame('cgi', LoggingConfig::getExecutionMode());
    }

    public function testLambdaBeatsGoogleCloud(): void
    {
        $this->setEnv('AWS_LAMBDA_FUNCTION_NAME', 'my-fn');
        $this->setEnv('FUNCTION_TARGET', 'h');
        $this->assertSame('lambda', LoggingConfig::getExecutionMode());
    }

    public function testGoogleCloudBeatsAzure(): void
    {
        $this->setEnv('FUNCTION_TARGET', 'h');
        $this->setEnv('AZURE_FUNCTIONS_ENVIRONMENT', 'Production');
        $this->assertSame('google_cloud_function', LoggingConfig::getExecutionMode());
    }

    // ------------------------------------------------------------------
    // isServerlessMode.
    // ------------------------------------------------------------------

    public function testServerIsNotServerless(): void
    {
        $this->assertFalse(LoggingConfig::isServerlessMode());
    }

    public function testLambdaIsServerless(): void
    {
        $this->setEnv('AWS_LAMBDA_FUNCTION_NAME', 'my-fn');
        $this->assertTrue(LoggingConfig::isServerlessMode());
    }

    public function testCgiIsServerless(): void
    {
        // CGI is short-lived per request — counts as serverless.
        $this->setEnv('GATEWAY_INTERFACE', 'CGI/1.1');
        $this->assertTrue(LoggingConfig::isServerlessMode());
    }

    public function testAzureIsServerless(): void
    {
        $this->setEnv('AZURE_FUNCTIONS_ENVIRONMENT', 'Production');
        $this->assertTrue(LoggingConfig::isServerlessMode());
    }
}
