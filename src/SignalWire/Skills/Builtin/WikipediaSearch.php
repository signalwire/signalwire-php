<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\HttpHelper;
use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

/**
 * Wikipedia search skill backed by the public MediaWiki action API.
 *
 * Mirrors signalwire-python's
 * `signalwire.skills.wikipedia_search.skill.WikipediaSearchSkill.search_wiki`:
 *
 *   1. GET en.wikipedia.org/w/api.php?action=query&list=search&srsearch=...
 *   2. For each top result, GET ?action=query&prop=extracts&exintro&...
 *      to fetch the article intro extract.
 *
 * Upstream URL override: `WIKIPEDIA_BASE_URL` for the audit fixture.
 * The path component (`/w/api.php`) is preserved.
 */
class WikipediaSearch extends SkillBase
{
    private const SEARCH_ENDPOINT = 'https://en.wikipedia.org/w/api.php';
    private const BASE_URL_ENV = 'WIKIPEDIA_BASE_URL';

    /** The name. */
    public function getName(): string
    {
        return 'wikipedia_search';
    }

    /** The description. */
    public function getDescription(): string
    {
        return 'Search Wikipedia for information about a topic and get article summaries';
    }

    public function setup(): bool
    {
        return true;
    }

    /**
     * Speech-recognition hints for this skill.
     *
     * Mirrors Python `WikipediaSearchSkill.get_hints` (skill.py:212): no hints
     * provided.
     *
     * @return list<string>
     */
    public function getHints(): array
    {
        return [];
    }

    /**
     * Parameter schema for the Wikipedia search skill.
     *
     * Mirrors Python `WikipediaSearchSkill.get_parameter_schema` (skill.py:44):
     * merges the base schema with num_results + no_results_message.
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        $schema = parent::getParameterSchema();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $schema['properties'] = array_merge($properties, [
            'num_results' => [
                'type' => 'integer',
                'description' => 'Maximum number of Wikipedia articles to return',
                'default' => 1,
                'required' => false,
                'minimum' => 1,
                'maximum' => 5,
            ],
            'no_results_message' => [
                'type' => 'string',
                'description' => 'Custom message when no Wikipedia articles are found',
                'default' => "I couldn't find any Wikipedia articles for '{query}'. Try rephrasing your search or using different keywords.",
                'required' => false,
            ],
        ]);
        return $schema;
    }

    /**
     * Search Wikipedia for articles matching the query and return the formatted
     * article content (or an error / no-results message) as a string.
     *
     * Mirrors Python `WikipediaSearchSkill.search_wiki` (skill.py:121): a
     * two-step API call (search, then per-hit extract) returning a plain
     * string. The SWAIG tool handler wraps this in a {@see FunctionResult}.
     */
    public function searchWiki(string $query): string
    {
        $query = trim($query);
        if ($query === '') {
            return 'Error: No search query provided.';
        }

        $numResults = max(1, min(5, $this->paramInt('num_results', 1)));
        $timeout = max(2, $this->paramInt('timeout', 10));
        $noResultsMessageRaw = $this->params['no_results_message'] ?? null;
        $noResultsMessage = is_string($noResultsMessageRaw)
            ? $noResultsMessageRaw
            : "I couldn't find any Wikipedia articles for '{query}'. "
                . 'Try rephrasing your search or using different keywords.';

        $endpoint = HttpHelper::applyBaseUrlOverride(
            self::SEARCH_ENDPOINT,
            self::BASE_URL_ENV,
        );

        // Step 1: search for matching articles.
        try {
            [$status, $rawBody, $parsed] = HttpHelper::get(
                $endpoint,
                query: [
                    'action' => 'query',
                    'list' => 'search',
                    'format' => 'json',
                    'srsearch' => $query,
                    'srlimit' => $numResults,
                ],
                timeout: $timeout,
            );
        } catch (\RuntimeException $e) {
            return 'Error accessing Wikipedia: ' . $e->getMessage();
        }

        if ($status < 200 || $status >= 300 || !is_array($parsed)) {
            return str_replace('{query}', $query, $noResultsMessage);
        }

        $queryBlock = $parsed['query'] ?? null;
        $hits = is_array($queryBlock) ? ($queryBlock['search'] ?? []) : [];
        if (!is_array($hits) || count($hits) === 0) {
            return str_replace('{query}', $query, $noResultsMessage);
        }

        // Step 2: fetch the introductory extract for each hit.
        $articles = [];
        foreach (array_slice($hits, 0, $numResults) as $hit) {
            if (!is_array($hit) || empty($hit['title']) || !is_string($hit['title'])) {
                continue;
            }
            $title = $hit['title'];
            $snippetRaw = $hit['snippet'] ?? null;
            $snippet = is_string($snippetRaw) ? $snippetRaw : '';

            try {
                [$exStatus, , $extracted] = HttpHelper::get(
                    $endpoint,
                    query: [
                        'action' => 'query',
                        'prop' => 'extracts',
                        'exintro' => '1',
                        'explaintext' => '1',
                        'format' => 'json',
                        'titles' => $title,
                    ],
                    timeout: $timeout,
                );
            } catch (\RuntimeException) {
                $exStatus = 0;
                $extracted = null;
            }

            $extract = '';
            if ($exStatus >= 200 && $exStatus < 300 && is_array($extracted)) {
                $extractedQuery = $extracted['query'] ?? null;
                $pages = is_array($extractedQuery) ? ($extractedQuery['pages'] ?? []) : [];
                if (is_array($pages)) {
                    $first = reset($pages);
                    if (is_array($first)) {
                        $extractRaw = $first['extract'] ?? '';
                        $extract = is_string($extractRaw) ? trim($extractRaw) : '';
                    }
                }
            }

            if ($extract === '' && $snippet !== '') {
                // The audit's canned response only carries search hits with
                // snippets, no extracts. Fall back to the snippet so we still
                // surface the sentinel to the caller.
                $extract = strip_tags($snippet);
            }

            if ($extract !== '') {
                $articles[] = "**{$title}**\n\n{$extract}";
            } else {
                $articles[] = "**{$title}**\n\nNo summary available for this article.";
            }
        }

        if (count($articles) === 0) {
            return str_replace('{query}', $query, $noResultsMessage);
        }
        if (count($articles) === 1) {
            return $articles[0];
        }
        return implode("\n\n" . str_repeat('=', 50) . "\n\n", $articles);
    }

    public function registerTools(): void
    {
        $this->defineTool(
            'search_wiki',
            'Search Wikipedia for information about a topic and get article summaries',
            [
                'query' => [
                    'type' => 'string',
                    'description' => 'The topic to search for on Wikipedia',
                ],
            ],
            function (array $args, array $rawData): FunctionResult {
                $query = trim((string) ($args['query'] ?? ''));
                return new FunctionResult($this->searchWiki($query));
            }
        );
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
                'title' => 'Wikipedia Search',
                'body' => 'You can search Wikipedia for information on any topic.',
                'bullets' => [
                    'Use search_wiki to look up articles on Wikipedia.',
                    'Returns article summaries for the requested topic.',
                    'Useful for factual information, historical data, and general knowledge.',
                ],
            ],
        ];
    }
}
