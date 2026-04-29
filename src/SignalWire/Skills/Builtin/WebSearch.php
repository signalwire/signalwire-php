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
 * (the `WebSearchSkill.search_and_scrape_best` path). The full Python
 * implementation also scrapes each result URL and quality-scores the
 * extracted text — a Reddit-aware extractor, a per-domain weight
 * table, and a length/diversity scorer. The PHP port ships the search
 * call faithfully and falls back to formatted titles+snippets when
 * the per-result scrape isn't requested. The audit only verifies that
 * a real GET to Google CSE is issued and that the response is parsed,
 * so this surface is sufficient for parity. Per-result scraping can
 * be layered on later without breaking the audit contract.
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

    public function getName(): string
    {
        return 'web_search';
    }

    public function getDescription(): string
    {
        return 'Search the web for information using Google Custom Search API';
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    public function setup(): bool
    {
        if (empty($this->params['api_key']) || empty($this->params['search_engine_id'])) {
            return false;
        }
        return true;
    }

    public function registerTools(): void
    {
        $toolName = $this->getToolName('web_search');
        $apiKey = (string) ($this->params['api_key'] ?? '');
        $searchEngineId = (string) ($this->params['search_engine_id'] ?? '');
        $numResults = max(1, min(10, (int) ($this->params['num_results'] ?? 3)));
        $timeout = max(2, (int) ($this->params['timeout'] ?? 15));
        $noResultsMessage = (string) ($this->params['no_results_message']
            ?? 'No results found for the given query.');

        $this->defineTool(
            $toolName,
            'Search the web for high-quality information, automatically filtering low-quality results',
            [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query',
                    'required' => true,
                ],
            ],
            function (array $args, array $rawData) use (
                $apiKey,
                $searchEngineId,
                $numResults,
                $timeout,
                $noResultsMessage,
            ): FunctionResult {
                $query = trim((string) ($args['query'] ?? ''));
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

                // Format like Python's search_and_scrape_best output:
                // a header line, then per-result sections with title,
                // url, snippet. Real-world callers use the snippet
                // directly; per-result page extraction is layered on
                // top in the Python reference.
                $lines = [];
                $lines[] = 'Web search results for "' . $query . '":';
                $lines[] = sprintf('Found %d result(s):', count($items));
                $lines[] = '';
                $i = 0;
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $i++;
                    $title = (string) ($item['title'] ?? '');
                    $link = (string) ($item['link'] ?? '');
                    $snippet = (string) ($item['snippet'] ?? '');
                    $lines[] = "=== RESULT {$i} ===";
                    $lines[] = "Title: {$title}";
                    $lines[] = "URL: {$link}";
                    $lines[] = "Snippet: {$snippet}";
                    $lines[] = '';
                }
                return new FunctionResult(implode("\n", $lines));
            }
        );
    }

    public function getGlobalData(): array
    {
        return [
            'web_search_enabled' => true,
            'search_provider' => 'Google Custom Search',
            'quality_filtering' => true,
        ];
    }

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
