<?php

declare(strict_types=1);

namespace SignalWire\REST;

/**
 * Iterates items across paginated API responses.
 *
 * Mirrors Python ``signalwire.rest._pagination.PaginatedIterator``: walks
 * pages by following the ``links.next`` cursor and extracting query params
 * from the URL.
 *
 * Usage:
 *   foreach (new PaginatedIterator($http, '/api/path', ['k' => 'v']) as $item) {
 *       // ...
 *   }
 *
 * @implements \Iterator<int,array<string,mixed>>
 */
class PaginatedIterator implements \Iterator
{
    private HttpClient $http;
    private string $path;
    /** @var array<string,mixed> */
    private array $params;
    private string $dataKey;

    /** @var list<array<string,mixed>> */
    private array $items = [];
    private int $index = 0;
    private bool $done = false;

    /**
     * Cycle guard: ``links.next`` cursors already followed. A server that keeps
     * returning the SAME ``links.next`` would otherwise loop forever (the
     * empty-page fix terminates ONLY on an ABSENT next link, so a repeating next
     * became an infinite loop). Seeing a repeat terminates iteration. Mirrors
     * the python reference ``_seen_next`` (rest/_pagination.py).
     *
     * @var array<string,true>
     */
    private array $seenNext = [];

    /**
     * @param array<string,mixed>|null $params Initial query-string parameters.
     */
    public function __construct(
        HttpClient $http,
        string $path,
        ?array $params = null,
        string $dataKey = 'data'
    ) {
        $this->http = $http;
        $this->path = $path;
        $this->params = $params ?? [];
        $this->dataKey = $dataKey;
    }

    /** The http. */
    public function getHttp(): HttpClient
    {
        return $this->http;
    }

    /** The path. */
    public function getPath(): string
    {
        return $this->path;
    }

    /** @return array<string,mixed> */
    public function getParams(): array
    {
        return $this->params;
    }

    /** The data key. */
    public function getDataKey(): string
    {
        return $this->dataKey;
    }

    /** Whether the done. */
    public function isDone(): bool
    {
        return $this->done;
    }

    /** The index. */
    public function getIndex(): int
    {
        return $this->index;
    }

    /** @return list<array<string,mixed>> */
    public function getItems(): array
    {
        return $this->items;
    }

    // -----------------------------------------------------------------
    // Iterator protocol
    // -----------------------------------------------------------------

    public function rewind(): void
    {
        // The Python iterator is single-pass; we only fetch on first valid().
    }

    public function valid(): bool
    {
        while ($this->index >= count($this->items)) {
            if ($this->done) {
                return false;
            }
            $this->fetchNext();
        }
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function current(): array
    {
        return $this->items[$this->index];
    }

    public function key(): int
    {
        return $this->index;
    }

    public function next(): void
    {
        $this->index++;
    }

    /**
     * Fetch and merge one page of items, advancing the cursor.
     */
    private function fetchNext(): void
    {
        $resp = $this->http->get($this->path, $this->coerceParams($this->params));
        $data = $resp[$this->dataKey] ?? [];
        if (is_array($data)) {
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $item = [];
                foreach ($row as $key => $value) {
                    $item[(string) $key] = $value;
                }
                $this->items[] = $item;
            }
        }

        $links = $resp['links'] ?? [];
        $nextUrl = is_array($links) ? ($links['next'] ?? null) : null;
        // Termination is driven ONLY by the absence of a next link, NOT by an
        // empty ``data`` array on this page. A page can legitimately carry a
        // ``links.next`` (more pages exist) while returning zero items on THIS
        // page — a filtered page that matched nothing here. The old
        // ``next_url && count(data) > 0`` condition stopped on such a page and
        // silently dropped every subsequent page; iterate while a next link
        // exists, empty page or not. Mirrors python rest/_pagination.py.
        if (is_string($nextUrl) && $nextUrl !== '') {
            // Cycle guard: a ``links.next`` we have already followed means the
            // server is looping (a repeating cursor) — terminate instead of
            // re-fetching the same page forever.
            if (isset($this->seenNext[$nextUrl])) {
                $this->done = true;
                return;
            }
            $this->seenNext[$nextUrl] = true;
            // Parse cursor/page token from next URL.
            $parsed = parse_url($nextUrl);
            $query = is_string($parsed['query'] ?? null) ? $parsed['query'] : '';
            $parts = [];
            parse_str($query, $parts);
            /** @var array<string,mixed> $normalized */
            $normalized = [];
            foreach ($parts as $k => $v) {
                $normalized[(string) $k] = $v;
            }
            $this->params = $normalized;
        } else {
            $this->done = true;
        }
    }

    /**
     * HttpClient::get expects array<string,string>; cast scalar values to
     * string and pass arrays through unchanged.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function coerceParams(array $params): array
    {
        return $params;
    }
}
