<?php

declare(strict_types=1);

namespace SignalWire\Tests\Rest;

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

    #[Test]
    public function nextPagesThroughAllItems(): void
    {
        // Page 1 — has a next page. The server's links.next carries the real
        // wire param the fabric list endpoint round-trips: ``page_token`` (a
        // cursor token that starts with PA/PB), NOT a ``cursor`` param (which
        // no SignalWire REST endpoint accepts).
        $this->mock->scenarios()->set(
            self::FABRIC_ADDRESSES_ENDPOINT_ID,
            200,
            [
                'data' => [
                    ['id' => 'addr-1', 'name' => 'first'],
                    ['id' => 'addr-2', 'name' => 'second'],
                ],
                'links' => [
                    'next' => 'http://example.com/api/fabric/addresses?page_token=PA_page2',
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
                    'next' => 'http://example.com/api/fabric/addresses?page_token=PA_page2',
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
        // The second fetch carries the ``page_token`` param parsed from the
        // first response's ``links.next`` — the real wire token the server
        // round-trips.
        $this->assertSame(['PA_page2'], $gets[1]->queryParams['page_token'] ?? null);
    }

    #[Test]
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
                    'next' => 'http://example.com/api/fabric/addresses?page_token=PA_page2',
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
        // The second fetch carries the page_token parsed from the first page's next link.
        $this->assertSame(['PA_page2'], $gets[1]->queryParams['page_token'] ?? null);
    }

    #[Test]
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
