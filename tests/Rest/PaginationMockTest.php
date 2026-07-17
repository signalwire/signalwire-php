<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\REST\PaginatedIterator;
use SignalWire\REST\RestClient;

/**
 * Mock-backed unit tests translated from
 * signalwire-python/tests/unit/rest/test_pagination_mock.py.
 *
 * The iterator wraps any ``HttpClient::get`` and walks paged responses
 * following the ``links.next`` cursor.  We test it end-to-end by:
 *
 *   1. Staging two FIFO scenarios on a known mock endpoint — the first
 *      has a ``links.next`` cursor, the second is the terminal page.
 *   2. Iterating over a real ``PaginatedIterator`` wired to the SDK's
 *      HttpClient pointed at the mock.
 *   3. Asserting on the items collected and on the journal entries that
 *      correspond to the two HTTP fetches.
 */
class PaginationMockTest extends TestCase
{
    private const FABRIC_ADDRESSES_PATH = '/api/fabric/addresses';
    private const FABRIC_ADDRESSES_ENDPOINT_ID = 'fabric.list_fabric_addresses';

    private RestClient $client;
    private Harness $mock;

    protected function setUp(): void
    {
        [$this->client, $this->mock] = MockTest::scopedClient();
    }

    #[Test]
    public function constructorRecordsHttpPathParamsDataKey(): void
    {
        $it = new PaginatedIterator(
            $this->client->getHttp(),
            self::FABRIC_ADDRESSES_PATH,
            ['page_size' => 2],
            'data',
        );
        $this->assertSame($this->client->getHttp(), $it->getHttp());
        $this->assertSame(self::FABRIC_ADDRESSES_PATH, $it->getPath());
        $this->assertSame(['page_size' => 2], $it->getParams());
        $this->assertSame('data', $it->getDataKey());
        $this->assertSame(0, $it->getIndex());
        $this->assertSame([], $it->getItems());
        $this->assertFalse($it->isDone());
        // No HTTP yet.
        $this->assertSame([], $this->mock->journal()->all());
    }

    // wire-regression-pin: fabric.list_fabric_addresses' spec has
    // `parameters: []` while the server returns a links.next cursor URL the
    // generic PaginatedIterator replays as ?cursor= — a parked spec gap (same
    // family as recordings' page_size), NOT fixed here. Excluded from the
    // STRICT-MOCKS wire-truth selector in rest_coverage_gate; still runs under
    // the plain TEST gate, which is where this pagination behavior belongs.
    #[Test]
    #[Group('wire-regression-pin')]
    public function nextPagesThroughAllItems(): void
    {
        // Page 1 — has a next cursor.
        $this->mock->scenarios()->set(
            self::FABRIC_ADDRESSES_ENDPOINT_ID,
            200,
            [
                'data' => [
                    ['id' => 'addr-1', 'name' => 'first'],
                    ['id' => 'addr-2', 'name' => 'second'],
                ],
                'links' => [
                    'next' => 'http://example.com/api/fabric/addresses?cursor=page2',
                ],
            ]
        );
        // Page 2 — terminal (no next).
        $this->mock->scenarios()->set(
            self::FABRIC_ADDRESSES_ENDPOINT_ID,
            200,
            [
                'data' => [
                    ['id' => 'addr-3', 'name' => 'third'],
                ],
                'links' => new \stdClass(),
            ]
        );

        // Reset journal so only our paginated fetches are recorded — but
        // reset() also clears scenarios, so re-stage them afterwards.
        $this->mock->reset();
        $this->mock->scenarios()->set(
            self::FABRIC_ADDRESSES_ENDPOINT_ID,
            200,
            [
                'data' => [
                    ['id' => 'addr-1', 'name' => 'first'],
                    ['id' => 'addr-2', 'name' => 'second'],
                ],
                'links' => [
                    'next' => 'http://example.com/api/fabric/addresses?cursor=page2',
                ],
            ]
        );
        $this->mock->scenarios()->set(
            self::FABRIC_ADDRESSES_ENDPOINT_ID,
            200,
            [
                'data' => [
                    ['id' => 'addr-3', 'name' => 'third'],
                ],
                'links' => new \stdClass(),
            ]
        );

        $it = new PaginatedIterator(
            $this->client->getHttp(),
            self::FABRIC_ADDRESSES_PATH,
            null,
            'data'
        );
        $collected = [];
        foreach ($it as $row) {
            $collected[] = $row;
        }
        // All three items, in order.
        $ids = array_map(fn ($r) => $r['id'], $collected);
        $this->assertSame(['addr-1', 'addr-2', 'addr-3'], $ids);

        // Journal must have exactly two GETs at the same path.
        $entries = $this->mock->journal()->all();
        $gets = array_values(array_filter(
            $entries,
            fn ($e) => $e->path === self::FABRIC_ADDRESSES_PATH
        ));
        $this->assertCount(2, $gets, 'expected 2 paginated GETs at addresses path');
        // The second fetch carries the ``cursor=page2`` param parsed from
        // the first response's ``links.next``.
        $this->assertSame(['page2'], $gets[1]->queryParams['cursor'] ?? null);
    }

    // wire-regression-pin: same parked fabric-pagination cursor gap as above.
    #[Test]
    #[Group('wire-regression-pin')]
    public function resourcePaginateWalksAllPagesFollowingCursor(): void
    {
        // Exercises ReadResource::paginate() (php idiom of Python's
        // ReadResource.paginate()) end-to-end through the real resource layer:
        // fabric().addresses()->paginate() must build a PaginatedIterator wired
        // to the resource's base path and follow links.next across two pages.
        $this->mock->scenarios()->set(
            self::FABRIC_ADDRESSES_ENDPOINT_ID,
            200,
            [
                'data' => [
                    ['id' => 'addr-1', 'name' => 'first'],
                    ['id' => 'addr-2', 'name' => 'second'],
                ],
                'links' => [
                    'next' => 'http://example.com/api/fabric/addresses?cursor=page2',
                ],
            ]
        );
        $this->mock->scenarios()->set(
            self::FABRIC_ADDRESSES_ENDPOINT_ID,
            200,
            [
                'data' => [
                    ['id' => 'addr-3', 'name' => 'third'],
                ],
                'links' => new \stdClass(),
            ]
        );

        $iterator = $this->client->fabric()->addresses()->paginate();

        $collected = [];
        foreach ($iterator as $row) {
            $collected[] = $row;
        }
        // paginate() followed the cursor and yielded every item across both pages.
        $ids = array_map(fn ($r) => $r['id'], $collected);
        $this->assertSame(['addr-1', 'addr-2', 'addr-3'], $ids);

        // Exactly two GETs against the addresses collection path.
        $gets = array_values(array_filter(
            $this->mock->journal()->all(),
            fn ($e) => $e->path === self::FABRIC_ADDRESSES_PATH
        ));
        $this->assertCount(2, $gets, 'paginate() should have fetched two pages');
        // The second fetch carries the cursor parsed from the first page's next link.
        $this->assertSame(['page2'], $gets[1]->queryParams['cursor'] ?? null);
    }

    // wire-regression-pin: same parked fabric-pagination gap (page_size, not cursor).
    #[Test]
    #[Group('wire-regression-pin')]
    public function resourcePaginateForwardsInitialParams(): void
    {
        // paginate($params) must seed the first request's query string.
        $this->mock->scenarios()->set(
            self::FABRIC_ADDRESSES_ENDPOINT_ID,
            200,
            [
                'data' => [['id' => 'addr-1']],
                'links' => new \stdClass(),
            ]
        );

        $iterator = $this->client->fabric()->addresses()->paginate(['page_size' => 2]);
        iterator_to_array($iterator, false);

        $gets = array_values(array_filter(
            $this->mock->journal()->all(),
            fn ($e) => $e->path === self::FABRIC_ADDRESSES_PATH
        ));
        $this->assertCount(1, $gets);
        $this->assertSame(['2'], $gets[0]->queryParams['page_size'] ?? null);
    }

    #[Test]
    public function valIsFalseWhenNoMoreItems(): void
    {
        // One terminal page.
        $this->mock->scenarios()->set(
            self::FABRIC_ADDRESSES_ENDPOINT_ID,
            200,
            [
                'data' => [['id' => 'only-one']],
                'links' => new \stdClass(),
            ]
        );
        $it = new PaginatedIterator(
            $this->client->getHttp(),
            self::FABRIC_ADDRESSES_PATH,
            null,
            'data'
        );
        // Walk one item then assert exhaustion.
        $it->rewind();
        $this->assertTrue($it->valid());
        $this->assertSame(['id' => 'only-one'], $it->current());
        $it->next();
        // After consuming the only item, valid() returns false.
        $this->assertFalse($it->valid());
    }

    #[Test]
    public function singleEmptyPageStopsImmediately(): void
    {
        // Stage an empty page (no data, no next).
        $this->mock->scenarios()->set(
            self::FABRIC_ADDRESSES_ENDPOINT_ID,
            200,
            [
                'data' => [],
                'links' => new \stdClass(),
            ]
        );
        $it = new PaginatedIterator(
            $this->client->getHttp(),
            self::FABRIC_ADDRESSES_PATH,
            null,
            'data'
        );
        $items = iterator_to_array($it, false);
        $this->assertSame([], $items);
        // Exactly one GET went out.
        $entries = $this->mock->journal()->all();
        $gets = array_values(array_filter(
            $entries,
            fn ($e) => $e->path === self::FABRIC_ADDRESSES_PATH
        ));
        $this->assertCount(1, $gets);
    }
}
