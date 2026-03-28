<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class NativeVectorSearch extends SkillBase
{
    public function getName(): string
    {
        return 'native_vector_search';
    }

    public function getDescription(): string
    {
        return 'Search document indexes using vector similarity and keyword search (local or remote)';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    public function setup(): bool
    {
        return true;
    }

    public function registerTools(): void
    {
        $toolName = $this->getToolName('search_knowledge');
        $toolDescription = $this->params['description'] ?? 'Search the local knowledge base for information';
        $defaultCount = max(1, (int) ($this->params['count'] ?? 5));

        $this->defineTool(
            $toolName,
            $toolDescription,
            [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query to find relevant information',
                    'required' => true,
                ],
                'count' => [
                    'type' => 'integer',
                    'description' => 'Number of results to return',
                    'default' => $defaultCount,
                ],
            ],
            function (array $args, array $rawData) use ($defaultCount): FunctionResult {
                $result = new FunctionResult();
                $query = $args['query'] ?? '';
                $count = (int) ($args['count'] ?? $defaultCount);

                if ($query === '') {
                    $result->setResponse('Error: No search query provided.');
                    return $result;
                }

                $remoteUrl = $this->params['remote_url'] ?? '';
                $indexName = $this->params['index_name'] ?? '';

                // Stub: network/remote mode only
                // In production, this would call the remote search endpoint or local vector search
                if ($remoteUrl !== '') {
                    $result->setResponse(
                        'Vector search results for "' . $query . '": '
                        . 'Searched remote endpoint "' . $remoteUrl . '" '
                        . 'with count=' . $count . '. '
                        . 'In production, this would return vector similarity search results.'
                    );
                } else {
                    $result->setResponse(
                        'Vector search results for "' . $query . '": '
                        . 'Searched index "' . $indexName . '" '
                        . 'with count=' . $count . '. '
                        . 'In production, this would return vector similarity search results.'
                    );
                }

                return $result;
            }
        );
    }

    public function getHints(): array
    {
        $hints = ['search', 'find', 'look up', 'documentation', 'knowledge base'];
        $customHints = $this->params['hints'] ?? [];

        if (is_array($customHints)) {
            foreach ($customHints as $hint) {
                if (is_string($hint) && !in_array($hint, $hints, true)) {
                    $hints[] = $hint;
                }
            }
        }

        return $hints;
    }
}
