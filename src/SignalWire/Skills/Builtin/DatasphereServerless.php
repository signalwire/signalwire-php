<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class DatasphereServerless extends SkillBase
{
    public function getName(): string
    {
        return 'datasphere_serverless';
    }

    public function getDescription(): string
    {
        return 'Search knowledge using SignalWire DataSphere with serverless DataMap execution';
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

        $authString = base64_encode($projectId . ':' . $token);

        $bodyPayload = [
            'document_id' => $documentId,
            'query_string' => '${args.query}',
            'count' => $count,
            'distance' => $distance,
        ];

        if (!empty($this->params['tags'])) {
            $bodyPayload['tags'] = $this->params['tags'];
        }

        if (!empty($this->params['language'])) {
            $bodyPayload['language'] = $this->params['language'];
        }

        $funcDef = [
            'function' => $toolName,
            'purpose' => 'Search the knowledge base for information on any topic and return relevant results',
            'argument' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'The search query to find relevant knowledge',
                    ],
                ],
                'required' => ['query'],
            ],
            'data_map' => [
                'webhooks' => [
                    [
                        'url' => 'https://' . $spaceName . '/api/datasphere/documents/search',
                        'method' => 'POST',
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'Authorization' => 'Basic ' . $authString,
                        ],
                        'body' => $bodyPayload,
                        'foreach' => [
                            'input_key' => 'chunks',
                            'output_key' => 'formatted_results',
                            'template' => '${this.document_id}: ${this.text}',
                        ],
                        'output' => [
                            'response' => 'I found results for "${args.query}":\n\n${formatted_results}',
                            'action' => [['say_it' => true]],
                        ],
                        'error_output' => [
                            'response' => $this->params['no_results_message']
                                ?? 'No results found in the knowledge base for the given query.',
                            'action' => [['say_it' => true]],
                        ],
                    ],
                ],
            ],
        ];

        if (!empty($this->swaigFields)) {
            $funcDef = array_merge($funcDef, $this->swaigFields);
        }

        $this->agent->registerSwaigFunction($funcDef);
    }

    public function getGlobalData(): array
    {
        return [
            'datasphere_serverless_enabled' => true,
            'document_id' => $this->params['document_id'] ?? '',
            'knowledge_provider' => 'SignalWire DataSphere (Serverless)',
        ];
    }

    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        return [
            [
                'title' => 'Knowledge Search Capability (Serverless)',
                'body' => 'You have access to a knowledge base powered by SignalWire DataSphere (serverless mode).',
                'bullets' => [
                    'Use the search tool to look up information in the knowledge base.',
                    'Always search the knowledge base before saying you do not know something.',
                    'Provide accurate answers based on the search results.',
                ],
            ],
        ];
    }
}
