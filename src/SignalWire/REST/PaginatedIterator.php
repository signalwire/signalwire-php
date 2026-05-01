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

    public function getHttp(): HttpClient
    {
        return $this->http;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /** @return array<string,mixed> */
    public function getParams(): array
    {
        return $this->params;
    }

    public function getDataKey(): string
    {
        return $this->dataKey;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

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
                $this->items[] = $row;
            }
        }

        $links = $resp['links'] ?? [];
        $nextUrl = is_array($links) ? ($links['next'] ?? null) : null;
        if (is_string($nextUrl) && $nextUrl !== '' && is_array($data) && count($data) > 0) {
            // Parse cursor/page token from next URL.
            $parsed = parse_url($nextUrl);
            $query = $parsed['query'] ?? '';
            parse_str(is_string($query) ? $query : '', $parts);
            $this->params = is_array($parts) ? $parts : [];
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
