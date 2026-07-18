<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_registry_mock.py.
 *
 * Covers the 10DLC Campaign Registry namespace: ``brands``, ``campaigns``,
 * ``orders``, ``numbers``.  All endpoints sit under
 * ``/api/relay/rest/registry/beta``.
 */
class RegistryMockTest extends TestCase
{
    private const REG_BASE = '/api/relay/rest/registry/beta';

    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        [$this->client, $this->mock] = MockTest::scopedClient();
    }

    // ----- brands ----------------------------------------------------

    #[Test]
    public function brandsListReturnsArray(): void
    {
        $body = $this->client->registry()->brands()->list();

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(self::REG_BASE . '/brands', $j->path);
        $this->assertNotNull($j->matchedRoute, 'spec gap: brand list');
    }

    #[Test]
    public function brandsGetUsesIdInPath(): void
    {
        $body = $this->client->registry()->brands()->get('brand-77');

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(self::REG_BASE . '/brands/brand-77', $j->path);
    }

    #[Test]
    public function brandsListCampaignsUsesBrandSubpath(): void
    {
        $body = $this->client->registry()->brands()->listCampaigns('brand-1');

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(self::REG_BASE . '/brands/brand-1/campaigns', $j->path);
        $this->assertNotNull($j->matchedRoute);
    }

    #[Test]
    public function brandsCreateCampaignPostsToBrandSubpath(): void
    {
        // createCampaign takes the full CreateManagedCampaignRequest body; the
        // spec field is 'sms_use_case' (not 'usecase').
        $body = $this->client->registry()->brands()->createCampaign(
            'brand-2',
            ['sms_use_case' => 'LOW_VOLUME', 'description' => 'MFA']
        );

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(self::REG_BASE . '/brands/brand-2/campaigns', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame('LOW_VOLUME', $bm['sms_use_case'] ?? null);
        $this->assertSame('MFA', $bm['description'] ?? null);
    }

    // ----- campaigns -------------------------------------------------

    #[Test]
    public function campaignsGetUsesIdInPath(): void
    {
        $body = $this->client->registry()->campaigns()->get('camp-1');

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(self::REG_BASE . '/campaigns/camp-1', $j->path);
    }

    #[Test]
    public function campaignsUpdateUsesPut(): void
    {
        // RegistryCampaigns.update uses PUT (not PATCH).
        // UpdateCampaignRequest exposes only 'name'.
        $body = $this->client->registry()->campaigns()->update(
            'camp-2',
            name: 'Updated Campaign'
        );

        $j = $this->mock->journal()->last();
        $this->assertSame('PUT', $j->method);
        $this->assertSame(self::REG_BASE . '/campaigns/camp-2', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame('Updated Campaign', $bm['name'] ?? null);
    }

    #[Test]
    public function campaignsListNumbersUsesNumbersSubpath(): void
    {
        $body = $this->client->registry()->campaigns()->listNumbers('camp-3');

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(self::REG_BASE . '/campaigns/camp-3/numbers', $j->path);
        $this->assertNotNull($j->matchedRoute);
    }

    #[Test]
    public function campaignsCreateOrderPostsToOrdersSubpath(): void
    {
        // CreateOrderRequest's field is 'phone_numbers' (typed param phoneNumbers).
        $body = $this->client->registry()->campaigns()->createOrder(
            'camp-4',
            phoneNumbers: ['pn-1', 'pn-2']
        );

        $j = $this->mock->journal()->last();
        $this->assertSame('POST', $j->method);
        $this->assertSame(self::REG_BASE . '/campaigns/camp-4/orders', $j->path);
        $bm = $j->bodyMap();
        $this->assertNotNull($bm);
        $this->assertSame(['pn-1', 'pn-2'], $bm['phone_numbers'] ?? null);
    }

    // ----- orders ----------------------------------------------------

    #[Test]
    public function ordersGetUsesIdInPath(): void
    {
        $body = $this->client->registry()->orders()->get('order-1');

        $j = $this->mock->journal()->last();
        $this->assertSame('GET', $j->method);
        $this->assertSame(self::REG_BASE . '/orders/order-1', $j->path);
        $this->assertNotNull($j->matchedRoute, 'spec gap: order retrieve');
    }

    // ----- numbers ---------------------------------------------------

    #[Test]
    public function numbersDeleteUsesIdInPath(): void
    {
        // SDK turns 204/empty into [] so we still get an array back.
        $body = $this->client->registry()->numbers()->delete('num-1');

        $j = $this->mock->journal()->last();
        $this->assertSame('DELETE', $j->method);
        $this->assertSame(self::REG_BASE . '/numbers/num-1', $j->path);
        $this->assertNotNull($j->matchedRoute);
    }
}
