<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\REST\CrudResource;
use SignalWire\REST\HttpClient;
use SignalWire\REST\Namespaces\Generated\Calling;
use SignalWire\REST\Namespaces\Generated\FabricNamespace as Fabric;
use SignalWire\REST\RestClient;
use SignalWire\REST\SignalWireRestError;
use SignalWire\REST\SignalWireRestTransportError;

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

        // SignalWireRestError extends RuntimeException (a compile-time
        // guarantee), so throwing it is catchable as \RuntimeException.
        $caught = null;
        try {
            throw $err;
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertSame($err, $caught);
    }

    // =================================================================
    // SignalWireRestTransportError (transport-level failure family)
    // =================================================================

    #[Test]
    public function transportErrorIsRestErrorFamily(): void
    {
        // A member of the SignalWireRestError family (subclass) — so a caller
        // catching that one type handles a transport failure too (plan 1.3b);
        // the family relationship is proven at runtime by
        // connectionRefusedRaisesTypedTransportError below, which catches a real
        // throw as the parent type. Here we pin the transport error's FIELDS.
        $err = new SignalWireRestTransportError('conn refused', 'https://h/api', 'GET');

        // No HTTP response reached, so no status code (0 == the php idiom for
        // "no status"); body is empty; url/method are carried.
        $this->assertSame(0, $err->getStatusCode());
        $this->assertSame('', $err->getBody());
        $this->assertSame('https://h/api', $err->getUrl());
        $this->assertSame('GET', $err->getMethod());
        $this->assertStringContainsString('conn refused', $err->getMessage());
    }

    #[Test]
    public function connectionRefusedRaisesTypedTransportError(): void
    {
        // Point the client at a DEAD port (bound then released, nothing
        // listening) so the connection is refused. A correct client raises its
        // TYPED transport error — a member of the SignalWireRestError family —
        // NOT a bare cURL/transport exception.
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($sock, 'could not bind a probe socket');
        $name = stream_socket_get_name($sock, false);
        fclose($sock); // release it — now nothing is listening on $deadPort
        $this->assertIsString($name);
        $deadPort = (int) substr($name, strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $deadPort);

        $http = new HttpClient('proj', 'tok', "http://127.0.0.1:{$deadPort}");

        $caught = null;
        try {
            $http->get('/api/fabric/addresses');
        } catch (SignalWireRestError $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            SignalWireRestTransportError::class,
            $caught,
            'connection refused must raise the typed SignalWireRestTransportError, '
            . 'not a bare exception'
        );
        $this->assertSame(0, $caught->getStatusCode());
        $this->assertSame('GET', $caught->getMethod());
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

        foreach (['list', 'create', 'get', 'update', 'delete'] as $method) {
            $this->assertTrue(
                method_exists($crud, $method),
                "CrudResource is missing the '{$method}' method"
            );
        }
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

        $this->assertSame('p', $http->getProjectId());
        $this->assertSame('t', $http->getToken());
        $this->assertSame('https://h.signalwire.com', $http->getBaseUrl());
    }

    // =================================================================
    // RestClient construction -- bare loopback host uses http:// (mirrors the
    // python reference `_is_loopback_host`: a bare 127.0.0.1[:port] / localhost
    // is a local mock/dev server that speaks plain HTTP, so the client targets
    // http:// for it. Lets a shipped example/doc run verbatim against the local
    // mock via SIGNALWIRE_SPACE=127.0.0.1:<port> without an explicit scheme.
    // A real space (<name>.signalwire.com) is never loopback → still https://.)
    // =================================================================

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function loopbackHostCases(): array
    {
        return [
            ['127.0.0.1',        'http://127.0.0.1'],
            ['127.0.0.1:8080',   'http://127.0.0.1:8080'],
            ['localhost',        'http://localhost'],
            ['localhost:3000',   'http://localhost:3000'],
        ];
    }

    #[Test]
    #[DataProvider('loopbackHostCases')]
    public function bareLoopbackHostUsesHttp(string $host, string $expectedBaseUrl): void
    {
        $client = new RestClient('p', 't', $host);
        $this->assertSame($expectedBaseUrl, $client->getBaseUrl());
        // The wired HttpClient carries the same http:// base URL.
        $this->assertSame($expectedBaseUrl, $client->getHttp()->getBaseUrl());
    }

    // =================================================================
    // Property-style namespace/resource access (the stripe/twilio + python
    // attribute idiom): $client->phoneNumbers === $client->phoneNumbers(),
    // and nested chains $client->fabric->aiAgents work via __get delegation.
    // =================================================================

    #[Test]
    public function propertyAccessDelegatesToAccessorMethod(): void
    {
        $client = new RestClient('p', 't', '127.0.0.1:8080');
        // Flat resource: property read returns the same lazy instance as the method.
        $this->assertSame($client->phoneNumbers(), $client->phoneNumbers);
        // Namespace container: property read returns the same lazy instance.
        $this->assertSame($client->fabric(), $client->fabric);
    }

    #[Test]
    public function nestedPropertyChainWorks(): void
    {
        $client = new RestClient('p', 't', '127.0.0.1:8080');
        // $client->fabric->aiAgents mirrors python's client.fabric.ai_agents.
        $this->assertSame(
            $client->fabric()->aiAgents(),
            $client->fabric->aiAgents,
        );
    }

    #[Test]
    public function unknownPropertyThrows(): void
    {
        $client = new RestClient('p', 't', '127.0.0.1:8080');
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line property.notFound (intentional: exercising __get's throw) */
        $client->definitelyNotAResource; // @phpstan-ignore-line
    }

    #[Test]
    public function realSpaceHostStillUsesHttps(): void
    {
        // A production space that merely CONTAINS a digit octet is not loopback.
        $client = new RestClient('p', 't', 'acme.signalwire.com');
        $this->assertSame('https://acme.signalwire.com', $client->getBaseUrl());
    }

    #[Test]
    public function explicitSchemeIsAlwaysHonored(): void
    {
        // An explicit https:// on a loopback host is honored verbatim (a caller
        // who WANTS TLS against a local TLS-terminating proxy).
        $client = new RestClient('p', 't', 'https://127.0.0.1:8443');
        $this->assertSame('https://127.0.0.1:8443', $client->getBaseUrl());
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

        // Every accessor is wired and returns a distinct namespace object.
        // (Concrete types are pinned by their declared return types; the
        // surface evolves as sub-resources are added, so we assert wiring +
        // distinctness here and path correctness in
        // ``namespaceBasePathsAreCorrect``.)
        $namespaces = [
            $client->fabric(),
            $client->calling(),
            $client->phoneNumbers(),
            $client->datasphere(),
            $client->video(),
            $client->addresses(),
            $client->queues(),
            $client->recordings(),
            $client->numberGroups(),
            $client->verifiedCallers(),
            $client->sipProfile(),
            $client->lookup(),
            $client->shortCodes(),
            $client->importedNumbers(),
            $client->mfa(),
            $client->registry(),
            $client->logs(),
            $client->project(),
            $client->pubsub(),
            $client->chat(),
        ];

        $ids = array_map('spl_object_id', $namespaces);
        $this->assertSame($ids, array_values(array_unique($ids)), 'each namespace is a distinct object');
    }

    // =================================================================
    // Namespace base paths
    // =================================================================

    #[Test]
    public function namespaceBasePathsAreCorrect(): void
    {
        $client = new RestClient('proj-id', 't', 'space.signalwire.com');

        // Direct CrudResource namespaces still expose getBasePath() at the
        // top level.
        $this->assertSame('/api/relay/rest/phone_numbers', $client->phoneNumbers()->getBasePath());
        $this->assertSame('/api/relay/rest/addresses', $client->addresses()->getBasePath());
        $this->assertSame('/api/relay/rest/queues', $client->queues()->getBasePath());
        $this->assertSame('/api/relay/rest/recordings', $client->recordings()->getBasePath());
        $this->assertSame('/api/relay/rest/number_groups', $client->numberGroups()->getBasePath());
        $this->assertSame('/api/relay/rest/verified_caller_ids', $client->verifiedCallers()->getBasePath());
        $this->assertSame('/api/relay/rest/sip_profile', $client->sipProfile()->getBasePath());
        $this->assertSame('/api/relay/rest/lookup', $client->lookup()->getBasePath());
        $this->assertSame('/api/relay/rest/short_codes', $client->shortCodes()->getBasePath());
        $this->assertSame('/api/relay/rest/imported_phone_numbers', $client->importedNumbers()->getBasePath());
        $this->assertSame('/api/relay/rest/mfa', $client->mfa()->getBasePath());
        $this->assertSame('/api/pubsub/tokens', $client->pubsub()->getBasePath());
        $this->assertSame('/api/chat/tokens', $client->chat()->getBasePath());

        // The "namespace" wrappers below mirror Python's per-resource
        // class layout — base paths live on the sub-resources.
        $this->assertSame('/api/datasphere/documents', $client->datasphere()->documents()->getBasePath());
        $this->assertSame('/api/video/rooms', $client->video()->rooms()->getBasePath());
        $this->assertSame('/api/relay/rest/registry/beta/brands', $client->registry()->brands()->getBasePath());
        $this->assertSame('/api/messaging/logs', $client->logs()->messages()->getBasePath());
        $this->assertSame('/api/project/tokens', $client->project()->tokens()->getBasePath());
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

        // Most sub-resources are CrudResource subclasses (some bespoke,
        // e.g. FabricSubscribers, FabricCallFlows, FabricCxmlApplications);
        // the special ones (read-only fabric addresses, generic resources,
        // token endpoints) have their own classes. Concrete types are pinned
        // by their declared return types — here we assert every accessor is
        // wired and returns a distinct object.
        $subResources = [
            $fabric->subscribers(),
            $fabric->sipEndpoints(),
            $fabric->callFlows(),
            $fabric->swmlScripts(),
            $fabric->conferenceRooms(),
            $fabric->aiAgents(),
            $fabric->addresses(),
            $fabric->resources(),
            $fabric->tokens(),
        ];

        $ids = array_map('spl_object_id', $subResources);
        $this->assertSame($ids, array_values(array_unique($ids)), 'each fabric sub-resource is a distinct object');
    }

    #[Test]
    public function fabricSubResourcePathsAreCorrect(): void
    {
        $client = new RestClient('p', 't', 'h');
        $fabric = $client->fabric();

        $this->assertSame('/api/fabric/resources/subscribers', $fabric->subscribers()->getBasePath());
        $this->assertSame('/api/fabric/resources/sip_endpoints', $fabric->sipEndpoints()->getBasePath());
        // Fabric addresses live at /api/fabric/addresses (NOT
        // /api/fabric/resources/addresses) — mirrors Python ``FabricAddresses``.
        $this->assertSame('/api/fabric/addresses', $fabric->addresses()->getBasePath());
        $this->assertSame('/api/fabric/resources/call_flows', $fabric->callFlows()->getBasePath());
        $this->assertSame('/api/fabric/resources/swml_scripts', $fabric->swmlScripts()->getBasePath());
        $this->assertSame('/api/fabric/resources/conference_rooms', $fabric->conferenceRooms()->getBasePath());
        $this->assertSame('/api/fabric/resources/ai_agents', $fabric->aiAgents()->getBasePath());
        // Generic resources collection.
        $this->assertSame('/api/fabric/resources', $fabric->resources()->getBasePath());
        // Tokens namespace (sits under /api/fabric, not /api/fabric/resources).
        $this->assertSame('/api/fabric', $fabric->tokens()->getBasePath());
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

        // The generated FabricNamespace container hangs its sub-resources off
        // the client's HttpClient. Sub-resources expose it via getClient()
        // (ReadResource/CrudResource base); assert the shared instance.
        $this->assertSame($client->getHttp(), $fabric->subscribers()->getClient());
        $this->assertSame($client->getHttp(), $fabric->aiAgents()->getClient());
    }

    // =================================================================
    // Calling namespace
    // =================================================================

    #[Test]
    public function callingNamespaceExists(): void
    {
        $client = new RestClient('proj-123', 't', 'h');
        $calling = $client->calling();

        // The generated command-dispatch Calling resource bakes its collection
        // path from the spec ( /api/calling/calls ). Project scoping is applied
        // by the HttpClient (Authorization header), not the resource path — so
        // Calling no longer carries a projectId of its own.
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

        // 5+5+4+3+2+2+2+2+2+4+2+2+1+1 = 37 methods across the Calling surface.
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

        // The generated command-dispatch Calling resource holds the client's
        // HttpClient privately (it POSTs every command over it). It exposes no
        // getClient() accessor, so assert the shared instance via reflection.
        $prop = new \ReflectionProperty($calling, 'http');
        $prop->setAccessible(true);
        $this->assertSame($client->getHttp(), $prop->getValue($calling));
    }
}
