<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\HttpClient;
use SignalWire\REST\CrudResource;
use SignalWire\REST\RestClient;
use SignalWire\REST\SignalWireRestError;
use SignalWire\REST\Namespaces\Fabric;
use SignalWire\REST\Namespaces\Calling;
use SignalWire\REST\Namespaces\Compat;

/**
 * Unit tests for the SignalWire PHP REST client.
 *
 * Covers: client construction, namespace initialisation, CrudResource path
 * construction, SignalWireRestError formatting, Fabric sub-resources, and
 * Calling namespace presence.
 */
class RestClientTest extends TestCase
{
    // =================================================================
    // HttpClient
    // =================================================================

    #[Test]
    public function httpClientConstructionSetsFields(): void
    {
        $http = new HttpClient('proj-123', 'tok-abc', 'https://example.signalwire.com');

        $this->assertSame('proj-123', $http->getProjectId());
        $this->assertSame('tok-abc', $http->getToken());
        $this->assertSame('https://example.signalwire.com', $http->getBaseUrl());
    }

    #[Test]
    public function httpClientAuthHeader(): void
    {
        $http = new HttpClient('user', 'pass', 'https://test.host');
        $expected = 'Basic ' . base64_encode('user:pass');

        $this->assertSame($expected, $http->getAuthHeader());
    }

    #[Test]
    public function httpClientTrailingSlashStripped(): void
    {
        $http = new HttpClient('p', 't', 'https://example.com/');

        $this->assertSame('https://example.com', $http->getBaseUrl());
    }

    // =================================================================
    // SignalWireRestError
    // =================================================================

    #[Test]
    public function restErrorStoresProperties(): void
    {
        $err = new SignalWireRestError('Not Found', 404, '{"error":"not_found"}');

        $this->assertSame(404, $err->getStatusCode());
        $this->assertSame('{"error":"not_found"}', $err->getResponseBody());
        $this->assertSame('Not Found', $err->getMessage());
        $this->assertSame(404, $err->getCode());
    }

    #[Test]
    public function restErrorDefaults(): void
    {
        $err = new SignalWireRestError('fail');

        $this->assertSame(0, $err->getStatusCode());
        $this->assertSame('', $err->getResponseBody());
    }

    #[Test]
    public function restErrorToString(): void
    {
        $err = new SignalWireRestError('GET /api/test returned 404', 404, 'Not Found');
        $str = (string) $err;

        $this->assertStringContainsString('404', $str);
        $this->assertStringContainsString('Not Found', $str);
    }

    #[Test]
    public function restErrorIsRuntimeException(): void
    {
        $err = new SignalWireRestError('oops', 500, 'body');

        $this->assertInstanceOf(\RuntimeException::class, $err);
    }

    // =================================================================
    // CrudResource
    // =================================================================

    #[Test]
    public function crudResourceStoresPath(): void
    {
        $http = new HttpClient('p', 't', 'https://h');
        $crud = new CrudResource($http, '/api/relay/rest/phone_numbers');

        $this->assertSame('/api/relay/rest/phone_numbers', $crud->getBasePath());
    }

    #[Test]
    public function crudResourceStoresClient(): void
    {
        $http = new HttpClient('p', 't', 'https://h');
        $crud = new CrudResource($http, '/api/test');

        $this->assertSame($http, $crud->getClient());
    }

    #[Test]
    public function crudResourceHasAllMethods(): void
    {
        $http = new HttpClient('p', 't', 'https://h');
        $crud = new CrudResource($http, '/api/test');

        $this->assertTrue(method_exists($crud, 'list'));
        $this->assertTrue(method_exists($crud, 'create'));
        $this->assertTrue(method_exists($crud, 'get'));
        $this->assertTrue(method_exists($crud, 'update'));
        $this->assertTrue(method_exists($crud, 'delete'));
    }

    // =================================================================
    // RestClient construction -- explicit
    // =================================================================

    #[Test]
    public function clientConstructionExplicit(): void
    {
        $client = new RestClient('proj-test', 'tok-test', 'test.signalwire.com');

        $this->assertSame('proj-test', $client->getProjectId());
        $this->assertSame('tok-test', $client->getToken());
        $this->assertSame('test.signalwire.com', $client->getSpace());
        $this->assertSame('https://test.signalwire.com', $client->getBaseUrl());
    }

    #[Test]
    public function clientHttpClientCorrectlyWired(): void
    {
        $client = new RestClient('p', 't', 'h.signalwire.com');
        $http = $client->getHttp();

        $this->assertInstanceOf(HttpClient::class, $http);
        $this->assertSame('p', $http->getProjectId());
        $this->assertSame('t', $http->getToken());
        $this->assertSame('https://h.signalwire.com', $http->getBaseUrl());
    }

    // =================================================================
    // RestClient construction -- env vars
    // =================================================================

    #[Test]
    public function clientConstructionFromEnvVars(): void
    {
        putenv('SIGNALWIRE_PROJECT_ID=env-proj');
        putenv('SIGNALWIRE_API_TOKEN=env-tok');
        putenv('SIGNALWIRE_SPACE=env.signalwire.com');

        try {
            $client = new RestClient();

            $this->assertSame('env-proj', $client->getProjectId());
            $this->assertSame('env-tok', $client->getToken());
            $this->assertSame('env.signalwire.com', $client->getSpace());
        } finally {
            putenv('SIGNALWIRE_PROJECT_ID');
            putenv('SIGNALWIRE_API_TOKEN');
            putenv('SIGNALWIRE_SPACE');
        }
    }

    #[Test]
    public function clientExplicitOverridesEnv(): void
    {
        putenv('SIGNALWIRE_PROJECT_ID=env-proj');
        putenv('SIGNALWIRE_API_TOKEN=env-tok');
        putenv('SIGNALWIRE_SPACE=env.signalwire.com');

        try {
            $client = new RestClient('explicit-proj', 'explicit-tok', 'explicit.space.com');

            $this->assertSame('explicit-proj', $client->getProjectId());
            $this->assertSame('explicit-tok', $client->getToken());
            $this->assertSame('explicit.space.com', $client->getSpace());
        } finally {
            putenv('SIGNALWIRE_PROJECT_ID');
            putenv('SIGNALWIRE_API_TOKEN');
            putenv('SIGNALWIRE_SPACE');
        }
    }

    #[Test]
    public function clientMissingProjectIdThrows(): void
    {
        putenv('SIGNALWIRE_PROJECT_ID');
        putenv('SIGNALWIRE_API_TOKEN');
        putenv('SIGNALWIRE_SPACE');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/projectId/');

        new RestClient('', 'tok', 'space');
    }

    #[Test]
    public function clientMissingTokenThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/token/');

        new RestClient('proj', '', 'space');
    }

    #[Test]
    public function clientMissingSpaceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/space/');

        new RestClient('proj', 'tok', '');
    }

    // =================================================================
    // All namespaces initialised (non-null)
    // =================================================================

    #[Test]
    public function allNamespacesAreAccessible(): void
    {
        $client = new RestClient('p', 't', 'h.signalwire.com');

        // Fabric
        $this->assertInstanceOf(Fabric::class, $client->fabric());

        // Calling
        $this->assertInstanceOf(Calling::class, $client->calling());

        // CrudResource namespaces
        $this->assertInstanceOf(CrudResource::class, $client->phoneNumbers());
        $this->assertInstanceOf(CrudResource::class, $client->datasphere());
        $this->assertInstanceOf(CrudResource::class, $client->video());
        $this->assertInstanceOf(Compat::class, $client->compat());
        $this->assertInstanceOf(CrudResource::class, $client->addresses());
        $this->assertInstanceOf(CrudResource::class, $client->queues());
        $this->assertInstanceOf(CrudResource::class, $client->recordings());
        $this->assertInstanceOf(CrudResource::class, $client->numberGroups());
        $this->assertInstanceOf(CrudResource::class, $client->verifiedCallers());
        $this->assertInstanceOf(CrudResource::class, $client->sipProfile());
        $this->assertInstanceOf(CrudResource::class, $client->lookup());
        $this->assertInstanceOf(CrudResource::class, $client->shortCodes());
        $this->assertInstanceOf(CrudResource::class, $client->importedNumbers());
        $this->assertInstanceOf(CrudResource::class, $client->mfa());
        $this->assertInstanceOf(CrudResource::class, $client->registry());
        $this->assertInstanceOf(CrudResource::class, $client->logs());
        $this->assertInstanceOf(CrudResource::class, $client->project());
        $this->assertInstanceOf(CrudResource::class, $client->pubsub());
        $this->assertInstanceOf(CrudResource::class, $client->chat());
    }

    // =================================================================
    // Namespace base paths
    // =================================================================

    #[Test]
    public function namespaceBasePathsAreCorrect(): void
    {
        $client = new RestClient('proj-id', 't', 'space.signalwire.com');

        $this->assertSame('/api/relay/rest/phone_numbers', $client->phoneNumbers()->getBasePath());
        $this->assertSame('/api/datasphere/documents', $client->datasphere()->getBasePath());
        $this->assertSame('/api/video/rooms', $client->video()->getBasePath());
        $this->assertSame('/api/laml/2010-04-01/Accounts/proj-id', $client->compat()->getAccountBase());
        $this->assertSame('/api/relay/rest/addresses', $client->addresses()->getBasePath());
        $this->assertSame('/api/fabric/resources/queues', $client->queues()->getBasePath());
        $this->assertSame('/api/relay/rest/recordings', $client->recordings()->getBasePath());
        $this->assertSame('/api/relay/rest/number_groups', $client->numberGroups()->getBasePath());
        $this->assertSame('/api/relay/rest/verified_callers', $client->verifiedCallers()->getBasePath());
        $this->assertSame('/api/relay/rest/sip_profiles', $client->sipProfile()->getBasePath());
        $this->assertSame('/api/relay/rest/lookup/phone_number', $client->lookup()->getBasePath());
        $this->assertSame('/api/relay/rest/short_codes', $client->shortCodes()->getBasePath());
        $this->assertSame('/api/relay/rest/imported_phone_numbers', $client->importedNumbers()->getBasePath());
        $this->assertSame('/api/relay/rest/mfa', $client->mfa()->getBasePath());
        $this->assertSame('/api/relay/rest/registry', $client->registry()->getBasePath());
        $this->assertSame('/api/relay/rest/logs', $client->logs()->getBasePath());
        $this->assertSame('/api/relay/rest/project', $client->project()->getBasePath());
        $this->assertSame('/api/relay/rest/pubsub', $client->pubsub()->getBasePath());
        $this->assertSame('/api/relay/rest/chat', $client->chat()->getBasePath());
    }

    // =================================================================
    // Lazy initialisation returns same instance
    // =================================================================

    #[Test]
    public function namespacesAreLazyAndCached(): void
    {
        $client = new RestClient('p', 't', 'h');

        $fabric1 = $client->fabric();
        $fabric2 = $client->fabric();
        $this->assertSame($fabric1, $fabric2, 'fabric() returns the same instance');

        $calling1 = $client->calling();
        $calling2 = $client->calling();
        $this->assertSame($calling1, $calling2, 'calling() returns the same instance');

        $pn1 = $client->phoneNumbers();
        $pn2 = $client->phoneNumbers();
        $this->assertSame($pn1, $pn2, 'phoneNumbers() returns the same instance');
    }

    // =================================================================
    // Fabric sub-resources
    // =================================================================

    #[Test]
    public function fabricSubResourcesExist(): void
    {
        $client = new RestClient('p', 't', 'h');
        $fabric = $client->fabric();

        $this->assertInstanceOf(CrudResource::class, $fabric->subscribers());
        $this->assertInstanceOf(CrudResource::class, $fabric->sipEndpoints());
        $this->assertInstanceOf(CrudResource::class, $fabric->addresses());
        $this->assertInstanceOf(CrudResource::class, $fabric->callFlows());
        $this->assertInstanceOf(CrudResource::class, $fabric->swmlScripts());
        $this->assertInstanceOf(CrudResource::class, $fabric->conversations());
        $this->assertInstanceOf(CrudResource::class, $fabric->conferenceRooms());
        $this->assertInstanceOf(CrudResource::class, $fabric->dialPlans());
        $this->assertInstanceOf(CrudResource::class, $fabric->freeclimbApps());
        $this->assertInstanceOf(CrudResource::class, $fabric->callQueues());
        $this->assertInstanceOf(CrudResource::class, $fabric->aiAgents());
        $this->assertInstanceOf(CrudResource::class, $fabric->sipProfiles());
        $this->assertInstanceOf(CrudResource::class, $fabric->phoneNumbers());
    }

    #[Test]
    public function fabricSubResourcePathsAreCorrect(): void
    {
        $client = new RestClient('p', 't', 'h');
        $fabric = $client->fabric();

        $this->assertSame('/api/fabric/resources/subscribers', $fabric->subscribers()->getBasePath());
        $this->assertSame('/api/fabric/resources/sip_endpoints', $fabric->sipEndpoints()->getBasePath());
        $this->assertSame('/api/fabric/resources/addresses', $fabric->addresses()->getBasePath());
        $this->assertSame('/api/fabric/resources/call_flows', $fabric->callFlows()->getBasePath());
        $this->assertSame('/api/fabric/resources/swml_scripts', $fabric->swmlScripts()->getBasePath());
        $this->assertSame('/api/fabric/resources/conversations', $fabric->conversations()->getBasePath());
        $this->assertSame('/api/fabric/resources/conference_rooms', $fabric->conferenceRooms()->getBasePath());
        $this->assertSame('/api/fabric/resources/dial_plans', $fabric->dialPlans()->getBasePath());
        $this->assertSame('/api/fabric/resources/freeclimb_apps', $fabric->freeclimbApps()->getBasePath());
        $this->assertSame('/api/fabric/resources/call_queues', $fabric->callQueues()->getBasePath());
        $this->assertSame('/api/fabric/resources/ai_agents', $fabric->aiAgents()->getBasePath());
        $this->assertSame('/api/fabric/resources/sip_profiles', $fabric->sipProfiles()->getBasePath());
        $this->assertSame('/api/fabric/resources/phone_numbers', $fabric->phoneNumbers()->getBasePath());
    }

    #[Test]
    public function fabricSubResourcesAreCachedInstances(): void
    {
        $client = new RestClient('p', 't', 'h');
        $fabric = $client->fabric();

        $this->assertSame($fabric->subscribers(), $fabric->subscribers());
        $this->assertSame($fabric->aiAgents(), $fabric->aiAgents());
    }

    #[Test]
    public function fabricSharesHttpClient(): void
    {
        $client = new RestClient('p', 't', 'h');
        $fabric = $client->fabric();

        $this->assertSame($client->getHttp(), $fabric->getClient());
        $this->assertSame($client->getHttp(), $fabric->subscribers()->getClient());
    }

    // =================================================================
    // Calling namespace
    // =================================================================

    #[Test]
    public function callingNamespaceExists(): void
    {
        $client = new RestClient('proj-123', 't', 'h');
        $calling = $client->calling();

        $this->assertInstanceOf(Calling::class, $calling);
        $this->assertSame('proj-123', $calling->getProjectId());
        $this->assertSame('/api/calling/calls', $calling->getBasePath());
    }

    #[Test]
    public function callingHasAll37Methods(): void
    {
        $client = new RestClient('p', 't', 'h');
        $calling = $client->calling();

        $expectedMethods = [
            // Call lifecycle (5)
            'dial', 'update', 'end', 'transfer', 'disconnect',
            // Play (5)
            'play', 'playPause', 'playResume', 'playStop', 'playVolume',
            // Record (4)
            'record', 'recordPause', 'recordResume', 'recordStop',
            // Collect (3)
            'collect', 'collectStop', 'collectStartInputTimers',
            // Detect (2)
            'detect', 'detectStop',
            // Tap (2)
            'tap', 'tapStop',
            // Stream (2)
            'stream', 'streamStop',
            // Denoise (2)
            'denoise', 'denoiseStop',
            // Transcribe (2)
            'transcribe', 'transcribeStop',
            // AI (4)
            'aiMessage', 'aiHold', 'aiUnhold', 'aiStop',
            // Live transcribe/translate (2)
            'liveTranscribe', 'liveTranslate',
            // Fax (2)
            'sendFaxStop', 'receiveFaxStop',
            // SIP (1)
            'refer',
            // Custom events (1)
            'userEvent',
        ];

        $this->assertCount(37, $expectedMethods, 'Sanity check: expected list has 37 entries');

        foreach ($expectedMethods as $method) {
            $this->assertTrue(
                method_exists($calling, $method),
                "Calling namespace missing method: {$method}"
            );
        }
    }

    #[Test]
    public function callingSharesHttpClient(): void
    {
        $client = new RestClient('p', 't', 'h');
        $calling = $client->calling();

        $this->assertSame($client->getHttp(), $calling->getClient());
    }

    // =================================================================
    // Compat namespace includes projectId in path
    // =================================================================

    #[Test]
    public function compatPathIncludesProjectId(): void
    {
        $client = new RestClient('my-proj', 't', 'h');

        $this->assertStringContainsString(
            'my-proj',
            $client->compat()->getAccountBase()
        );
        $this->assertSame(
            '/api/laml/2010-04-01/Accounts/my-proj',
            $client->compat()->getAccountBase()
        );
    }
}
