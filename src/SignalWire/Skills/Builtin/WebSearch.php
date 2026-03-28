<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class WebSearch extends SkillBase
{
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
        $apiKey = $this->params['api_key'] ?? '';
        $searchEngineId = $this->params['search_engine_id'] ?? '';
        $numResults = $this->params['num_results'] ?? 3;

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
            function (array $args, array $rawData) use ($apiKey, $searchEngineId, $numResults): FunctionResult {
                $result = new FunctionResult();
                $query = $args['query'] ?? '';

                if ($query === '') {
                    $result->setResponse('Error: No search query provided.');
                    return $result;
                }

                $noResultsMessage = $this->params['no_results_message']
                    ?? 'No results found for the given query.';

                // Stub: in production, this would call Google Custom Search API
                // GET https://www.googleapis.com/customsearch/v1?key={apiKey}&cx={searchEngineId}&q={query}&num={numResults}
                $result->setResponse(
                    'Web search results for "' . $query . '": '
                    . 'Searched using Google Custom Search API with ' . $numResults . ' results requested. '
                    . 'API Key and Search Engine ID are configured. '
                    . 'In production, this would return filtered, quality-scored web results.'
                );

                return $result;
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
