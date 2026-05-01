<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\REST\BaseResource;
use SignalWire\REST\CrudWithAddresses;
use SignalWire\REST\HttpClient;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests for the cross-language REST base classes
 * BaseResource and CrudWithAddresses introduced for Python parity
 * (signalwire.rest._base.BaseResource / .CrudWithAddresses).
 */
class BaseResourceTest extends TestCase
{
    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = new RestClient('test_proj', 'test_tok', $this->mock->url());
    }

    private function http(): HttpClient
    {
        // RestClient's protected http client is what every namespace uses;
        // grab one via reflection so direct base-class tests aren't tied to
        // a particular namespace surface.
        $rc = new \ReflectionObject($this->client);
        $prop = $rc->getProperty('http');
        $prop->setAccessible(true);
        return $prop->getValue($this->client);
    }

    // ──────────────────────────────────────────────────────────────────
    // BaseResource — minimal http+base_path wrapper
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function baseResourceConstructsWithHttpAndBasePath(): void
    {
        $br = new BaseResource($this->http(), '/api/relay/rest/addresses');
        $this->assertSame('/api/relay/rest/addresses', $br->getBasePath());
        $this->assertSame($this->http(), $br->getHttp());
    }

    // ──────────────────────────────────────────────────────────────────
    // CrudWithAddresses — list_addresses / list / get / create / etc.
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function crudWithAddressesListAddressesCallsAddressesSubpath(): void
    {
        $resource = new CrudWithAddresses(
            $this->http(),
            '/api/fabric/resources/subscribers'
        );

        // Exercise list_addresses on a known resource_id; the mock harness
        // accepts unknown paths by returning a synthetic 200/empty payload
        // so we can assert the path the SDK constructed.
        $resource->listAddresses('sub-123', ['page_size' => '5']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(
            '/api/fabric/resources/subscribers/sub-123/addresses',
            $j->path,
            'list_addresses must hit basePath/{id}/addresses'
        );
        $this->assertSame(['5'], $j->queryParams['page_size'] ?? null);
    }

    #[Test]
    public function crudWithAddressesInheritsCrudList(): void
    {
        $resource = new CrudWithAddresses(
            $this->http(),
            '/api/fabric/resources/subscribers'
        );
        $resource->list(['page_size' => '20']);

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/fabric/resources/subscribers', $j->path);
        $this->assertSame(['20'], $j->queryParams['page_size'] ?? null);
    }

    #[Test]
    public function crudWithAddressesInheritsCrudGet(): void
    {
        $resource = new CrudWithAddresses(
            $this->http(),
            '/api/fabric/resources/subscribers'
        );
        $resource->get('sub-456');

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame('/api/fabric/resources/subscribers/sub-456', $j->path);
    }

    #[Test]
    public function crudWithAddressesIsACrudResource(): void
    {
        // Class-hierarchy assertion — CrudWithAddresses must extend
        // CrudResource, which extends BaseResource. Mirrors Python's
        // signalwire.rest._base hierarchy so inheritance chains line up.
        $this->assertTrue(
            is_subclass_of(CrudWithAddresses::class, \SignalWire\REST\CrudResource::class)
        );
        $this->assertTrue(
            is_subclass_of(\SignalWire\REST\CrudResource::class, BaseResource::class)
        );
    }
}
