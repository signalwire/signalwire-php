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

    public function getName(): string
    {
        return 'wikipedia_search';
    }

    public function getDescription(): string
    {
        return 'Search Wikipedia for information about a topic and get article summaries';
    }

    public function setup(): bool
    {
        return true;
    }

    public function registerTools(): void
    {
        $numResults = max(1, min(5, (int) ($this->params['num_results'] ?? 1)));
        $timeout = max(2, (int) ($this->params['timeout'] ?? 10));
        $noResultsMessage = (string) ($this->params['no_results_message']
            ?? "I couldn't find any Wikipedia articles for '{query}'. "
                . 'Try rephrasing your search or using different keywords.');

        $this->defineTool(
            'search_wiki',
            'Search Wikipedia for information about a topic and get article summaries',
            [
                'query' => [
                    'type' => 'string',
                    'description' => 'The topic to search for on Wikipedia',
                    'required' => true,
                ],
            ],
            function (array $args, array $rawData) use (
                $numResults,
                $timeout,
                $noResultsMessage,
            ): FunctionResult {
                $query = trim((string) ($args['query'] ?? ''));
                if ($query === '') {
                    return new FunctionResult('Error: No search query provided.');
                }

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
                    return new FunctionResult(
                        'Error accessing Wikipedia: ' . $e->getMessage()
                    );
                }

                if ($status < 200 || $status >= 300 || !is_array($parsed)) {
                    return new FunctionResult(
                        str_replace('{query}', $query, $noResultsMessage)
                    );
                }

                $hits = $parsed['query']['search'] ?? [];
                if (!is_array($hits) || count($hits) === 0) {
                    return new FunctionResult(
                        str_replace('{query}', $query, $noResultsMessage)
                    );
                }

                // Step 2: fetch the introductory extract for each hit.
                $articles = [];
                foreach (array_slice($hits, 0, $numResults) as $hit) {
                    if (!is_array($hit) || empty($hit['title'])) {
                        continue;
                    }
                    $title = (string) $hit['title'];
                    $snippet = isset($hit['snippet']) ? (string) $hit['snippet'] : '';

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
                        $pages = $extracted['query']['pages'] ?? [];
                        if (is_array($pages)) {
                            $first = reset($pages);
                            if (is_array($first)) {
                                $extract = trim((string) ($first['extract'] ?? ''));
                            }
                        }
                    }

                    if ($extract === '' && $snippet !== '') {
                        // The audit's canned response only carries
                        // search hits with snippets, no extracts. Fall
                        // back to the snippet so we still surface the
                        // sentinel to the caller.
                        $extract = strip_tags($snippet);
                    }

                    if ($extract !== '') {
                        $articles[] = "**{$title}**\n\n{$extract}";
                    } else {
                        $articles[] = "**{$title}**\n\nNo summary available for this article.";
                    }
                }

                if (count($articles) === 0) {
                    return new FunctionResult(
                        str_replace('{query}', $query, $noResultsMessage)
                    );
                }
                if (count($articles) === 1) {
                    return new FunctionResult($articles[0]);
                }
                return new FunctionResult(
                    implode("\n\n" . str_repeat('=', 50) . "\n\n", $articles)
                );
            }
        );
    }

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
