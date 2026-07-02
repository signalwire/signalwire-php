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
use SignalWire\Agent\AgentBase;

/**
 * Behavioral parity test for AgentBase::handleServerlessRequest — the
 * ServerlessMixin.handle_serverless_request capability on the flattened
 * AgentBase. Mirrors the Python reference (core/mixins/serverless_mixin.py):
 * dispatch by execution mode and return a platform-appropriate response.
 */
class AgentServerlessRequestTest extends TestCase
{
    private function agent(): AgentBase
    {
        // Fixed basic-auth so the request carries valid credentials.
        return new AgentBase(name: 'sless', basicAuthUser: 'u', basicAuthPassword: 'p');
    }

    /** @return array<string,string> */
    private function authHeaders(): array
    {
        return ['Authorization' => 'Basic ' . base64_encode('u:p')];
    }

    public function testLambdaModeReturnsApiGatewayResponse(): void
    {
        $agent = $this->agent();
        $event = [
            'httpMethod' => 'GET',
            'path' => '/',
            'headers' => $this->authHeaders(),
        ];

        $response = $agent->handleServerlessRequest($event, new \stdClass(), mode: 'lambda');

        $this->assertIsArray($response);
        $this->assertArrayHasKey('statusCode', $response);
        $this->assertSame(200, $response['statusCode']);
        // The SWML document is returned as the body.
        $this->assertIsString($response['body']);
        $this->assertStringContainsString('sections', $response['body']);
    }

    public function testLambdaModeUnauthenticatedIsRejected(): void
    {
        $agent = $this->agent();
        $event = [
            'httpMethod' => 'GET',
            'path' => '/',
            'headers' => [], // no auth
        ];

        $response = $agent->handleServerlessRequest($event, new \stdClass(), mode: 'lambda');
        $this->assertIsArray($response);
        $this->assertSame(401, $response['statusCode']);
    }

    public function testAzureModeReturnsAzureResponse(): void
    {
        $agent = $this->agent();
        $request = [
            'method' => 'GET',
            'url' => '/',
            'headers' => $this->authHeaders(),
        ];

        $response = $agent->handleServerlessRequest($request, null, mode: 'azure');
        $this->assertIsArray($response);
        $this->assertArrayHasKey('status', $response);
        $this->assertSame(200, $response['status']);
    }

    public function testInvalidModeRaises(): void
    {
        $this->expectException(\ValueError::class);
        $this->agent()->handleServerlessRequest(mode: 'not-a-mode');
    }
}
