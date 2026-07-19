<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\HttpHelper;
use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

/**
 * Web search skill backed by Google Custom Search.
 *
 * Mirrors signalwire-python's `signalwire.skills.web_search.skill`
 * (the `WebSearchSkill.search_and_scrape_best` path). The Python
 * reference fetches CSE results, then scrapes each result URL and
 * quality-scores the extracted text with a Reddit-aware extractor, a
 * per-domain weight table, and a length/diversity scorer. The PHP port
 * issues the CSE call faithfully and scrapes each result page with a
 * lighter strip_tags/regex extractor (the heavyweight BeautifulSoup
 * scorer and the per-domain quality table remain Python-specific and
 * are tracked in PORT_OMISSIONS.md). When no scraped page clears the
 * quality threshold — or the overall deadline fires — the handler falls
 * back to formatting the CSE titles+snippets so the response is always
 * non-empty.
 *
 * Latency control (Python skill.py commits 51101da + 295745b — the
 * SignalWire kernel times out webhook responses around 55s, so the
 * handler MUST finish under that):
 *   - per_page_timeout (2.0s): cURL CURLOPT_TIMEOUT on each page scrape.
 *   - overall_deadline (10.0s): wall-clock budget for the whole tool
 *     call, tracked from microtime(true); once exceeded, remaining
 *     scrapes are abandoned and we return whatever we have (or the
 *     snippet fallback).
 *   - snippets_only (false): skip page scraping entirely and format the
 *     CSE snippets directly. Sub-second response.
 *   - parallel_scrape (true): accepted to match
 *     Python/Go/TS. PHP is single-threaded and true concurrent HTTP
 *     would require curl_multi (invasive, and parallelism is NOT part of
 *     the latency contract), so scrapes run SEQUENTIALLY with strict
 *     per-page + overall-deadline enforcement. See PORT_ADDITIONS.md.
 *
 * Upstream URL override: `WEB_SEARCH_BASE_URL` (used by
 * audit_skills_dispatch.py to point at a local fixture). When set,
 * the URL is rewritten to the override host while the path
 * `/customsearch/v1` is preserved.
 */
class WebSearch extends SkillBase
{
    private const ENDPOINT = 'https://www.googleapis.com/customsearch/v1';
    private const BASE_URL_ENV = 'WEB_SEARCH_BASE_URL';

    /** The name. */
    public function getName(): string
    {
        return 'web_search';
    }

    /**
     * Narrow a genuinely-mixed value (Google CSE JSON item field) to a
     * string. Numeric scalars are stringified; anything else → ''.
     */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }

    /** The description. */
    public function getDescription(): string
    {
        return 'Search the web for information using Google Custom Search API';
    }

    /** The version. */
    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    /**
     * Speech-recognition hints for this skill.
     *
     * Mirrors Python `WebSearchSkill.get_hints` (skill.py:932): no hints
     * provided.
     *
     * @return list<string>
     */
    public function getHints(): array
    {
        return [];
    }

    /**
     * Unique instance key for this skill instance.
     *
     * Mirrors Python `WebSearchSkill.get_instance_key` (skill.py:785): the
     * search_engine_id and tool_name together differentiate instances.
     */
    public function getInstanceKey(): string
    {
        $searchEngineId = $this->paramString('search_engine_id', 'default');
        $toolName = $this->getToolName('web_search');
        return 'web_search_' . $searchEngineId . '_' . $toolName;
    }

    public function setup(): bool
    {
        if (empty($this->params['api_key']) || empty($this->params['search_engine_id'])) {
            return false;
        }
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        // Merge the base schema (swaig_fields / skip_prompt / tool_name)
        // with web_search's own params. Advertising every param the skill
        // reads keeps them discoverable and guards against the recurring
        // "added a self.params read but forgot the schema entry" drift
        // (Python test_every_setup_param_is_advertised, commit 295745b).
        $schema = parent::getParameterSchema();
        $properties = $schema['properties'] ?? [];
        $schema['properties'] = array_merge(is_array($properties) ? $properties : [], [
            'api_key' => [
                'type' => 'string',
                'description' => 'Google Custom Search API key.',
                'required' => true,
            ],
            'search_engine_id' => [
                'type' => 'string',
                'description' => 'Google Custom Search engine (cx) id.',
                'required' => true,
            ],
            'num_results' => [
                'type' => 'integer',
                'description' => 'Number of results to return (1-10).',
                'default' => 3,
            ],
            'timeout' => [
                'type' => 'integer',
                'description' => 'Timeout (seconds) for the Google CSE API call.',
                'default' => 15,
            ],
            'no_results_message' => [
                'type' => 'string',
                'description' => 'Message shown when no results are found. Use {query} as a placeholder.',
                'default' => 'No results found for the given query.',
            ],
            'response_prefix' => [
                'type' => 'string',
                'description' => 'Optional text prepended to every non-empty search result.',
                'default' => '',
            ],
            'response_postfix' => [
                'type' => 'string',
                'description' => 'Optional text appended to every non-empty search result.',
                'default' => '',
            ],
            // ── Latency-control params (Python skill.py 51101da/295745b) ──
            'per_page_timeout' => [
                'type' => 'number',
                'description' => 'Maximum seconds to wait on a single page scrape.',
                'default' => 2.0,
                'min' => 0.1,
            ],
            'overall_deadline' => [
                'type' => 'number',
                'description' => 'Wall-clock budget in seconds for the whole tool call. In-flight scrapes are abandoned past this so the response beats the kernel webhook timeout.',
                'default' => 10.0,
                'min' => 1.0,
            ],
            'parallel_scrape' => [
                'type' => 'boolean',
                'description' => 'Accepted for parity with the Python/Go/TS skills. PHP runs scrapes sequentially (single-threaded; true parallelism would require curl_multi and is not part of the latency contract).',
                'default' => true,
            ],
            'snippets_only' => [
                'type' => 'boolean',
                'description' => 'Skip page scraping entirely and return Google CSE snippets only. Fastest mode (sub-second).',
                'default' => false,
            ],
        ]);
        return $schema;
    }

    public function registerTools(): void
    {
        $toolName = $this->getToolName('web_search');
        $apiKey = $this->paramString('api_key');
        $searchEngineId = $this->paramString('search_engine_id');
        $numResults = max(1, min(10, $this->paramInt('num_results', 3)));
        $timeout = max(2, $this->paramInt('timeout', 15));
        $noResultsMessage = $this->paramString('no_results_message', 'No results found for the given query.');

        // Optional prefix/postfix wrapped around every non-empty search
        // result. Use these to give the calling agent a mechanical cue
        // (e.g. "tell the user this came from a public web search")
        // without needing prompt-side rules. Mirrors the
        // native_vector_search wrapping pattern (response_format_callback
        // in the Python reference).
        $responsePrefix = $this->paramString('response_prefix');
        $responsePostfix = $this->paramString('response_postfix');

        // Latency-control params (Python skill.py:660-674, commit 51101da).
        //   perPageTimeout: max seconds to wait on a single page scrape;
        //     becomes cURL CURLOPT_TIMEOUT (whole-number floor of >=1s
        //     because cURL's seconds-granularity timeout can't express a
        //     sub-second wait; the page-loop still honors the float for
        //     the overall-deadline math).
        //   overallDeadline: wall-clock budget for the entire tool call.
        //   parallelScrape: accepted for parity only — PHP scrapes
        //     sequentially (see class docblock).
        //   snippetsOnly: skip scraping; format CSE snippets directly.
        $perPageTimeout = max(0.1, $this->paramFloat('per_page_timeout', 2.0));
        $overallDeadline = max(0.0, $this->paramFloat('overall_deadline', 10.0));
        $parallelScrape = $this->paramBool('parallel_scrape', true);
        $snippetsOnly = $this->paramBool('snippets_only', false);
        $minQualityScore = $this->paramFloat('min_quality_score', 0.2);

        $this->defineTool(
            $toolName,
            'Search the web for high-quality information, automatically filtering low-quality results',
            [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query',
                ],
            ],
            function (array $args, array $rawData) use (
                $apiKey,
                $searchEngineId,
                $numResults,
                $timeout,
                $noResultsMessage,
                $responsePrefix,
                $responsePostfix,
                $perPageTimeout,
                $overallDeadline,
                $snippetsOnly,
                $minQualityScore,
            ): FunctionResult {
                // Wall-clock instant past which any not-yet-started scrape
                // is abandoned. Tracked from the moment the handler begins
                // so the CSE call itself counts against the budget, exactly
                // like Python's `deadline_at = time.monotonic() + deadline`.
                $deadlineAt = microtime(true) + $overallDeadline;

                $queryArg = $args['query'] ?? '';
                $query = trim(is_string($queryArg) ? $queryArg : '');
                if ($query === '') {
                    return new FunctionResult('Error: No search query provided.');
                }

                $url = HttpHelper::applyBaseUrlOverride(
                    self::ENDPOINT,
                    self::BASE_URL_ENV,
                );

                try {
                    [$status, $rawBody, $parsed] = HttpHelper::get(
                        $url,
                        headers: [],
                        query: [
                            'key' => $apiKey,
                            'cx'  => $searchEngineId,
                            'q'   => $query,
                            'num' => $numResults,
                        ],
                        timeout: $timeout,
                    );
                } catch (\RuntimeException $e) {
                    return new FunctionResult(
                        'Sorry, I encountered an error while searching: ' . $e->getMessage()
                    );
                }

                if ($status < 200 || $status >= 300) {
                    return new FunctionResult(
                        "Search service returned HTTP {$status}: " . substr($rawBody, 0, 200)
                    );
                }
                if (!is_array($parsed)) {
                    return new FunctionResult($noResultsMessage);
                }

                $items = $parsed['items'] ?? [];
                if (!is_array($items) || count($items) === 0) {
                    return new FunctionResult(
                        str_replace('{query}', $query, $noResultsMessage)
                    );
                }
                // Keep only well-formed item rows for downstream passes.
                $items = array_values(array_filter(
                    $items,
                    static fn ($it): bool => is_array($it),
                ));
                if (count($items) === 0) {
                    return new FunctionResult(
                        str_replace('{query}', $query, $noResultsMessage)
                    );
                }

                // Snippets-only fast path (Python skill.py:453-455). Skip
                // page scraping entirely and format the CSE snippets. Returns
                // in ~1 RTT; good enough for most voice answers and never
                // risks the kernel webhook timeout.
                if ($snippetsOnly) {
                    return $this->wrap(
                        self::formatSnippetResults($query, $items, $numResults),
                        $responsePrefix,
                        $responsePostfix,
                    );
                }

                // Scrape each candidate page, score it, and keep those above
                // the quality threshold. Port of Python's per-result loop in
                // GoogleSearchScraper.search_and_scrape_best (skill.py:457-513).
                // parallel_scrape is accepted but ignored: PHP runs the loop
                // sequentially with strict per-page + overall-deadline
                // enforcement (the deadline is the contracted guarantee).
                $perPageCurlTimeout = max(1, (int) ceil($perPageTimeout));
                $scraped = [];
                foreach ($items as $item) {
                    // Overall-deadline check BEFORE starting each scrape so a
                    // slow earlier page can't drag the whole call past budget.
                    if (microtime(true) >= $deadlineAt) {
                        break;
                    }
                    $candidate = $this->scrapeOne(
                        $item,
                        $query,
                        $perPageCurlTimeout,
                        $minQualityScore,
                    );
                    if ($candidate !== null) {
                        $scraped[] = $candidate;
                    }
                }

                if (count($scraped) === 0) {
                    // Time ran out (overall_deadline fired) or every page was
                    // below the quality threshold / unreachable. Fall back to
                    // snippet-only formatting so we return SOMETHING useful
                    // before the kernel webhook timeout fires, rather than the
                    // empty no-results message (Python skill.py:515-519).
                    return $this->wrap(
                        self::formatSnippetResults($query, $items, $numResults),
                        $responsePrefix,
                        $responsePostfix,
                    );
                }

                // Best-first, capped at num_results.
                usort(
                    $scraped,
                    static fn (array $a, array $b): int => $b['score'] <=> $a['score'],
                );
                $scraped = array_slice($scraped, 0, $numResults);

                $response = self::formatScrapedResults($query, $scraped);
                return $this->wrap($response, $responsePrefix, $responsePostfix);
            }
        );
    }

    /**
     * Fetch + score a single CSE result. Returns an enriched row, or null
     * on parse failure / unreachable page / below-threshold quality.
     * Mirrors Python's `_scrape_one` (skill.py:458-481).
     *
     * @param array<mixed> $item Raw CSE item (title/link/snippet).
     * @return array{title:string,url:string,snippet:string,content:string,score:float}|null
     */
    private function scrapeOne(
        array $item,
        string $query,
        int $perPageTimeout,
        float $minQualityScore,
    ): ?array {
        $link = self::asString($item['link'] ?? null);
        if ($link === '') {
            return null;
        }

        $pageUrl = HttpHelper::applyBaseUrlOverride($link, self::BASE_URL_ENV);
        try {
            [$status, $body, $parsedPage] = HttpHelper::get(
                $pageUrl,
                headers: ['User-Agent' => 'SignalWire-WebSearch/2.0'],
                timeout: $perPageTimeout,
            );
        } catch (\RuntimeException) {
            // Network failure / per_page_timeout — abandon this page.
            return null;
        }
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $pageText = self::extractText($body, $parsedPage);
        if ($pageText === '') {
            return null;
        }

        $score = self::scoreContent($pageText, $query);
        if ($score < $minQualityScore) {
            return null;
        }

        return [
            'title'   => self::asString($item['title'] ?? null),
            'url'     => $link,
            'snippet' => self::asString($item['snippet'] ?? null),
            'content' => $pageText,
            'score'   => $score,
        ];
    }

    /**
     * Format Google CSE snippets without fetching the underlying pages.
     *
     * Used by the snippets_only fast path and as the graceful fallback
     * when page scraping is abandoned by overall_deadline or every page
     * falls below the quality threshold. Always non-empty when the CSE
     * returned anything, so the kernel never sees a webhook timeout.
     * Mirrors `_format_snippet_results` (skill.py:416-437).
     *
     * @param list<array<mixed>> $items
     */
    private static function formatSnippetResults(string $query, array $items, int $numResults): string
    {
        if (count($items) === 0) {
            return "No search results found for query: {$query}";
        }
        $top = array_slice($items, 0, max($numResults, 1));
        $lines = [
            "Snippet-only results for '{$query}' (page content not scraped):",
            '',
        ];
        $i = 0;
        foreach ($top as $item) {
            $i++;
            $title = self::asString($item['title'] ?? null);
            $link = self::asString($item['link'] ?? null);
            $snippet = trim(self::asString($item['snippet'] ?? null));
            $lines[] = "=== RESULT {$i} ===";
            $lines[] = "Title: {$title}";
            $lines[] = "URL: {$link}";
            $lines[] = "Snippet: {$snippet}";
            $lines[] = '';
        }
        return implode("\n", $lines);
    }

    /**
     * Format fully-scraped results — header line, then per-result blocks
     * with title, url, snippet, and a content excerpt. Mirrors the shape
     * of Python's scraped-result formatter (skill.py:520-560) closely
     * enough for the agent to consume.
     *
     * @param list<array{title:string,url:string,snippet:string,content:string,score:float}> $results
     */
    private static function formatScrapedResults(string $query, array $results): string
    {
        $lines = [];
        $lines[] = 'Web search results for "' . $query . '":';
        $lines[] = sprintf('Found %d result(s):', count($results));
        $lines[] = '';
        $i = 0;
        foreach ($results as $r) {
            $i++;
            $excerpt = $r['content'];
            if (strlen($excerpt) > 1000) {
                $excerpt = substr($excerpt, 0, 1000) . '...';
            }
            $lines[] = "=== RESULT {$i} ===";
            $lines[] = 'Title: ' . $r['title'];
            $lines[] = 'URL: ' . $r['url'];
            $lines[] = 'Snippet: ' . $r['snippet'];
            $lines[] = 'Content: ' . $excerpt;
            $lines[] = '';
        }
        return implode("\n", $lines);
    }

    /**
     * Extract readable text from a scraped page. The production path
     * serves HTML; the audit fixture may serve a JSON object carrying the
     * HTML under `_raw_html` / `html` / `body`. Accept both (same shape
     * the Spider skill handles).
     */
    private static function extractText(string $rawBody, mixed $parsed): string
    {
        $html = $rawBody;
        if (is_array($parsed)) {
            foreach (['_raw_html', 'html', 'body'] as $key) {
                if (isset($parsed[$key]) && is_string($parsed[$key])) {
                    $html = $parsed[$key];
                    break;
                }
            }
        }
        if ($html === '') {
            return '';
        }
        $cleaned = preg_replace(
            [
                '#<script\b[^>]*>.*?</script>#is',
                '#<style\b[^>]*>.*?</style>#is',
                '#<nav\b[^>]*>.*?</nav>#is',
                '#<header\b[^>]*>.*?</header>#is',
                '#<footer\b[^>]*>.*?</footer>#is',
                '#<aside\b[^>]*>.*?</aside>#is',
                '#<noscript\b[^>]*>.*?</noscript>#is',
            ],
            ' ',
            $html,
        ) ?? $html;
        $text = strip_tags($cleaned);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Lightweight content-quality score in [0,1]. Combines a length
     * signal with query-term coverage — a pragmatic stand-in for the
     * Python reference's multi-signal scorer (which is tracked as a
     * PORT_OMISSIONS.md divergence). Enough to filter empty / off-topic
     * pages while keeping substantive ones.
     */
    private static function scoreContent(string $text, string $query): float
    {
        $len = strlen($text);
        if ($len === 0) {
            return 0.0;
        }
        // Length signal: saturates around ~1500 chars.
        $lengthScore = min(1.0, $len / 1500.0);

        // Query-term coverage: fraction of query words present in the text.
        $words = array_values(array_filter(
            preg_split('/\s+/', strtolower(trim($query))) ?: [],
            static fn (string $w): bool => strlen($w) >= 3,
        ));
        $relevance = 0.0;
        if (count($words) > 0) {
            $haystack = strtolower($text);
            $found = 0;
            foreach ($words as $w) {
                if (str_contains($haystack, $w)) {
                    $found++;
                }
            }
            $relevance = $found / count($words);
        }

        return round(0.5 * $lengthScore + 0.5 * $relevance, 4);
    }

    /**
     * Wrap a non-empty success response with the configured prefix /
     * postfix. Error / no-results responses are deliberately left
     * unwrapped to match the Python reference.
     */
    private function wrap(string $response, string $prefix, string $postfix): FunctionResult
    {
        if ($prefix !== '') {
            $response = $prefix . "\n\n" . $response;
        }
        if ($postfix !== '') {
            $response = $response . "\n\n" . $postfix;
        }
        return new FunctionResult($response);
    }

    /**
     * @return array<string,mixed>
     */
    public function getGlobalData(): array
    {
        return [
            'web_search_enabled' => true,
            'search_provider' => 'Google Custom Search',
            'quality_filtering' => true,
        ];
    }

    /**
     * @return list<array{title: string, body?: string, bullets?: list<string>}>
     */
    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        return [
            [
                'title' => 'Web Search Capability (Quality Enhanced)',
                'body' => 'You can search the web for information.',
                'bullets' => [
                    'Use the web search tool to find current information on any topic.',
                    'Results are automatically quality-scored and filtered.',
                    'Low-quality or irrelevant results are excluded.',
                ],
            ],
        ];
    }
}
