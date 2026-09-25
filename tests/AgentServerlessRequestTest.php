<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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

        $response = $agent->handleServerlessRequest($request, null, mode: 'azure_function');
        $this->assertIsArray($response);
        $this->assertArrayHasKey('status', $response);
        $this->assertSame(200, $response['status']);
    }

    public function testInvalidModeRaises(): void
    {
        $this->expectException(\ValueError::class);
        $this->agent()->handleServerlessRequest(mode: 'not-a-mode');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Mode vocabulary — parity with the reference
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Every mode string the Python reference's `handle_serverless_request`
     * dispatches on MUST be accepted here.
     *
     * This is a REGRESSION test for a real caller-visible defect: PHP used to
     * spell the two cloud-function modes `gcf` and `azure`, so a caller who
     * followed the reference and wrote `mode: 'azure_function'` got a
     * \ValueError instead of a response. Nothing caught it, because no test
     * ever passed a reference-spelled mode. Now one does.
     *
     * `server` is excluded: it starts the blocking built-in server.
     *
     * @return array<string, array{string}>
     */
    public static function referenceModes(): array
    {
        return [
            'cgi'                   => ['cgi'],
            'lambda'                => ['lambda'],
            'google_cloud_function' => ['google_cloud_function'],
            'azure_function'        => ['azure_function'],
        ];
    }

    #[DataProvider('referenceModes')]
    public function testReferenceSpelledModeIsAccepted(string $mode): void
    {
        $agent = $this->agent();
        $event = [
            'httpMethod' => 'GET',
            'method' => 'GET',
            'path' => '/',
            'url' => 'https://example.test/',
            'headers' => $this->authHeaders(),
        ];

        // CGI and GCF write to the output stream rather than returning; the
        // point of this test is only that the mode RESOLVES and DISPATCHES
        // (no \ValueError), so capture and discard whatever they emit.
        \ob_start();
        try {
            $agent->handleServerlessRequest($event, new \stdClass(), mode: $mode);
        } finally {
            \ob_end_clean();
        }

        $this->addToAssertionCount(1);
    }

    /**
     * The pre-parity PHP-only spellings must be GONE, not aliased: keeping
     * them would leave PHP with mode surface the reference does not have.
     *
     * @return array<string, array{string}>
     */
    public static function retiredModes(): array
    {
        return [
            'gcf'   => ['gcf'],
            'azure' => ['azure'],
        ];
    }

    #[DataProvider('retiredModes')]
    public function testRetiredPhpOnlyModeSpellingIsRejected(string $mode): void
    {
        $this->expectException(\ValueError::class);
        $this->agent()->handleServerlessRequest(mode: $mode);
    }
}
