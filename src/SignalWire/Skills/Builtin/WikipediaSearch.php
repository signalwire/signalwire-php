<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class WikipediaSearch extends SkillBase
{
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
        $numResults = $this->params['num_results'] ?? 1;
        $numResults = max(1, min(5, (int) $numResults));

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
            function (array $args, array $rawData) use ($numResults): FunctionResult {
                $result = new FunctionResult();
                $query = $args['query'] ?? '';

                if ($query === '') {
                    $result->setResponse('Error: No search query provided.');
                    return $result;
                }

                $noResultsMessage = $this->params['no_results_message']
                    ?? 'No Wikipedia articles found for the given query.';

                // Stub: in production, this would call Wikipedia REST API
                // GET https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch={query}&format=json&srlimit={numResults}
                // Then fetch extracts for each result:
                // GET https://en.wikipedia.org/w/api.php?action=query&prop=extracts&exintro&explaintext&titles={title}&format=json
                $result->setResponse(
                    'Wikipedia search results for "' . $query . '": '
                    . 'Searched Wikipedia API with up to ' . $numResults . ' results. '
                    . 'In production, this would return article summaries from Wikipedia.'
                );

                return $result;
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
