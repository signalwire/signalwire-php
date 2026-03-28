<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class Datasphere extends SkillBase
{
    public function getName(): string
    {
        return 'datasphere';
    }

    public function getDescription(): string
    {
        return 'Search knowledge using SignalWire DataSphere RAG stack';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    public function setup(): bool
    {
        $required = ['space_name', 'project_id', 'token', 'document_id'];

        foreach ($required as $key) {
            if (empty($this->params[$key])) {
                return false;
            }
        }

        return true;
    }

    public function registerTools(): void
    {
        $toolName = $this->getToolName('search_knowledge');
        $spaceName = $this->params['space_name'] ?? '';
        $projectId = $this->params['project_id'] ?? '';
        $token = $this->params['token'] ?? '';
        $documentId = $this->params['document_id'] ?? '';
        $count = max(1, min(10, (int) ($this->params['count'] ?? 1)));
        $distance = (float) ($this->params['distance'] ?? 3.0);

        $this->defineTool(
            $toolName,
            'Search the knowledge base for information on any topic and return relevant results',
            [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query to find relevant knowledge',
                    'required' => true,
                ],
            ],
            function (array $args, array $rawData) use ($spaceName, $projectId, $token, $documentId, $count, $distance): FunctionResult {
                $result = new FunctionResult();
                $query = $args['query'] ?? '';

                if ($query === '') {
                    $result->setResponse('Error: No search query provided.');
                    return $result;
                }

                $noResultsMessage = $this->params['no_results_message']
                    ?? 'No results found in the knowledge base for the given query.';

                // Stub: in production, this would POST to DataSphere API
                // POST https://{spaceName}/api/datasphere/documents/search
                // Auth: Basic {projectId}:{token}
                // Body: { document_id, query_string: query, count, distance, tags, language, ... }
                $result->setResponse(
                    'DataSphere search results for "' . $query . '": '
                    . 'Searched document "' . $documentId . '" in space "' . $spaceName . '" '
                    . 'with count=' . $count . ' and distance=' . $distance . '. '
                    . 'In production, this would return matching knowledge base chunks.'
                );

                return $result;
            }
        );
    }

    public function getGlobalData(): array
    {
        return [
            'datasphere_enabled' => true,
            'document_id' => $this->params['document_id'] ?? '',
            'knowledge_provider' => 'SignalWire DataSphere',
        ];
    }

    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        return [
            [
                'title' => 'Knowledge Search Capability',
                'body' => 'You have access to a knowledge base powered by SignalWire DataSphere.',
                'bullets' => [
                    'Use the search tool to look up information in the knowledge base.',
                    'Always search the knowledge base before saying you do not know something.',
                    'Provide accurate answers based on the search results.',
                ],
            ],
        ];
    }
}
