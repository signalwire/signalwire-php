<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;

class DatasphereServerless extends SkillBase
{
    /** The name. */
    public function getName(): string
    {
        return 'datasphere_serverless';
    }

    /** The description. */
    public function getDescription(): string
    {
        return 'Search knowledge using SignalWire DataSphere with serverless DataMap execution';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    /**
     * Speech-recognition hints for this skill.
     *
     * Mirrors Python `DataSphereServerlessSkill.get_hints` (skill.py:242): no
     * hints provided.
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
     * Mirrors Python `DataSphereServerlessSkill.get_instance_key`
     * (skill.py:115): the tool name (default 'search_knowledge') differentiates
     * instances.
     */
    public function getInstanceKey(): string
    {
        return 'datasphere_serverless_' . $this->getToolName('search_knowledge');
    }

    /**
     * Parameter schema for the DataSphere Serverless skill.
     *
     * Mirrors Python `DataSphereServerlessSkill.get_parameter_schema`
     * (skill.py:37): the same search-parameter block as the standard
     * DataSphere skill.
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        $schema = parent::getParameterSchema();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $schema['properties'] = array_merge($properties, [
            'space_name' => [
                'type' => 'string',
                'description' => "SignalWire space name (e.g., 'mycompany' from mycompany.signalwire.com)",
                'required' => true,
            ],
            'project_id' => [
                'type' => 'string',
                'description' => 'SignalWire project ID',
                'required' => true,
                'env_var' => 'SIGNALWIRE_PROJECT_ID',
            ],
            'token' => [
                'type' => 'string',
                'description' => 'SignalWire API token',
                'required' => true,
                'hidden' => true,
                'env_var' => 'SIGNALWIRE_API_TOKEN',
            ],
            'document_id' => [
                'type' => 'string',
                'description' => 'DataSphere document ID to search within',
                'required' => true,
            ],
            'count' => [
                'type' => 'integer',
                'description' => 'Number of search results to return',
                'default' => 1,
                'required' => false,
                'minimum' => 1,
                'maximum' => 10,
            ],
            'distance' => [
                'type' => 'number',
                'description' => 'Maximum distance threshold for results (lower is more relevant)',
                'default' => 3.0,
                'required' => false,
                'minimum' => 0.0,
                'maximum' => 10.0,
            ],
            'tags' => [
                'type' => 'array',
                'description' => 'Tags to filter search results',
                'required' => false,
                'items' => ['type' => 'string'],
            ],
            'language' => [
                'type' => 'string',
                'description' => "Language code for query expansion (e.g., 'en', 'es')",
                'required' => false,
            ],
            'pos_to_expand' => [
                'type' => 'array',
                'description' => 'Parts of speech to expand with synonyms',
                'required' => false,
                'items' => ['type' => 'string', 'enum' => ['NOUN', 'VERB', 'ADJ', 'ADV']],
            ],
            'max_synonyms' => [
                'type' => 'integer',
                'description' => 'Maximum number of synonyms to use for query expansion',
                'required' => false,
                'minimum' => 1,
                'maximum' => 10,
            ],
            'no_results_message' => [
                'type' => 'string',
                'description' => 'Message to return when no results are found',
                'default' => "I couldn't find any relevant information for '{query}' in the knowledge base. Try rephrasing your question or asking about a different topic.",
                'required' => false,
            ],
        ]);
        return $schema;
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
        $spaceName = $this->paramString('space_name');
        $projectId = $this->paramString('project_id');
        $token = $this->paramString('token');
        $documentId = $this->paramString('document_id');
        $count = max(1, min(10, $this->paramInt('count', 1)));
        $distance = $this->paramFloat('distance', 3.0);

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

    /**
     * @return array<string,mixed>
     */
    public function getGlobalData(): array
    {
        return [
            'datasphere_serverless_enabled' => true,
            'document_id' => $this->params['document_id'] ?? '',
            'knowledge_provider' => 'SignalWire DataSphere (Serverless)',
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
