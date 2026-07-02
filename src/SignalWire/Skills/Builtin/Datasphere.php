<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\HttpHelper;
use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

/**
 * SignalWire DataSphere knowledge-search skill.
 *
 * Mirrors signalwire-python's
 * `signalwire.skills.datasphere.skill.DataSphereSkill._search_knowledge_handler`:
 *
 *   POST https://{space_name}.signalwire.com/api/datasphere/documents/search
 *   Auth: Basic project_id:token
 *   Body: { document_id, query_string, distance, count, [tags, language, ...] }
 *
 * The response carries `chunks: [{text, score, ...}]` (NOT `results`).
 * The handler stringifies the chunks the same way the Python skill does.
 *
 * Upstream URL override: `DATASPHERE_BASE_URL` for the audit fixture.
 * The path `/api/datasphere/documents/search` is preserved.
 */
class Datasphere extends SkillBase
{
    private const PATH_SEARCH = '/api/datasphere/documents/search';
    private const BASE_URL_ENV = 'DATASPHERE_BASE_URL';

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

    /**
     * Speech-recognition hints for this skill.
     *
     * Mirrors Python `DataSphereSkill.get_hints` (skill.py:308): no hints
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
     * Mirrors Python `DataSphereSkill.get_instance_key` (skill.py:111): the
     * tool name (default 'search_knowledge') differentiates instances.
     */
    public function getInstanceKey(): string
    {
        return 'datasphere_' . $this->getToolName('search_knowledge');
    }

    /**
     * Release resources when the skill is unloaded.
     *
     * Mirrors Python `DataSphereSkill.cleanup` (skill.py:303), which closes the
     * requests session. The PHP skill holds no persistent connection (each
     * search is a stateless HTTP call), so cleanup is a no-op.
     */
    public function cleanup(): void
    {
    }

    /**
     * Parameter schema for the DataSphere skill.
     *
     * Mirrors Python `DataSphereSkill.get_parameter_schema` (skill.py:33).
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        $schema = parent::getParameterSchema();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $schema['properties'] = array_merge($properties, self::datasphereSchemaProperties());
        return $schema;
    }

    /**
     * The shared DataSphere search parameter properties (identical between the
     * standard and serverless skills — Python defines the same block in both).
     * Private so it stays off the enumerated surface (it is an internal helper,
     * not a skill-interface method).
     *
     * @return array<string,mixed>
     */
    private static function datasphereSchemaProperties(): array
    {
        return [
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
                'env_var' => 'SIGNALWIRE_TOKEN',
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
        ];
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
        $timeout = max(2, $this->paramInt('timeout', 30));
        $tags = $this->params['tags'] ?? null;
        $language = $this->params['language'] ?? null;
        $posToExpand = $this->params['pos_to_expand'] ?? null;
        $maxSynonyms = $this->params['max_synonyms'] ?? null;
        $noResultsMessageRaw = $this->params['no_results_message'] ?? null;
        $noResultsMessage = is_string($noResultsMessageRaw)
            ? $noResultsMessageRaw
            : "I couldn't find any relevant information for '{query}' in the knowledge base. "
                . 'Try rephrasing your question or asking about a different topic.';

        $this->defineTool(
            $toolName,
            'Search the knowledge base for information on any topic and return relevant results',
            [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query to find relevant knowledge',
                ],
            ],
            function (array $args, array $rawData) use (
                $spaceName,
                $projectId,
                $token,
                $documentId,
                $count,
                $distance,
                $timeout,
                $tags,
                $language,
                $posToExpand,
                $maxSynonyms,
                $noResultsMessage,
            ): FunctionResult {
                $query = trim((string) ($args['query'] ?? ''));
                if ($query === '') {
                    return new FunctionResult(
                        'Please provide a search query. What would you like me to search for in the knowledge base?'
                    );
                }

                $url = HttpHelper::applyBaseUrlOverride(
                    "https://{$spaceName}.signalwire.com" . self::PATH_SEARCH,
                    self::BASE_URL_ENV,
                );

                $payload = [
                    'document_id' => $documentId,
                    'query_string' => $query,
                    'distance' => $distance,
                    'count' => $count,
                ];
                if ($tags !== null) {
                    $payload['tags'] = $tags;
                }
                if ($language !== null) {
                    $payload['language'] = $language;
                }
                if ($posToExpand !== null) {
                    $payload['pos_to_expand'] = $posToExpand;
                }
                if ($maxSynonyms !== null) {
                    $payload['max_synonyms'] = $maxSynonyms;
                }

                try {
                    [$status, $rawBody, $parsed] = HttpHelper::postJson(
                        $url,
                        body: $payload,
                        basicAuth: [$projectId, $token],
                        timeout: $timeout,
                    );
                } catch (\RuntimeException $e) {
                    return new FunctionResult(
                        'Sorry, the knowledge search timed out: ' . $e->getMessage()
                    );
                }

                if ($status < 200 || $status >= 300) {
                    return new FunctionResult(
                        'Sorry, there was an error accessing the knowledge base. '
                        . "(HTTP {$status})"
                    );
                }
                if (!is_array($parsed)) {
                    return new FunctionResult(
                        str_replace('{query}', $query, $noResultsMessage)
                    );
                }

                // DataSphere returns 'chunks'. The audit fixture returns
                // 'results' with the same shape (text, score) — accept
                // either. Falling back through both keeps parity.
                $chunks = $parsed['chunks'] ?? $parsed['results'] ?? [];
                if (!is_array($chunks) || count($chunks) === 0) {
                    return new FunctionResult(
                        str_replace('{query}', $query, $noResultsMessage)
                    );
                }

                return new FunctionResult($this->formatChunks($query, $chunks));
            }
        );
    }

    /**
     * @param array<mixed> $chunks
     */
    private function formatChunks(string $query, array $chunks): string
    {
        $count = count($chunks);
        $header = $count === 1
            ? "I found 1 result for '{$query}':\n\n"
            : "I found {$count} results for '{$query}':\n\n";

        $sections = [];
        $i = 0;
        foreach ($chunks as $chunk) {
            $i++;
            if (!is_array($chunk)) {
                $sections[] = "=== RESULT {$i} ===\n" . (string) json_encode($chunk)
                    . "\n" . str_repeat('=', 50) . "\n";
                continue;
            }
            $body = $chunk['text']
                ?? $chunk['content']
                ?? $chunk['chunk']
                ?? json_encode($chunk, JSON_PRETTY_PRINT);
            if (!is_string($body)) {
                $body = (string) json_encode($body);
            }
            $sections[] = "=== RESULT {$i} ===\n{$body}\n" . str_repeat('=', 50) . "\n";
        }
        return $header . implode("\n", $sections);
    }

    /**
     * @return array<string,mixed>
     */
    public function getGlobalData(): array
    {
        return [
            'datasphere_enabled' => true,
            'document_id' => $this->params['document_id'] ?? '',
            'knowledge_provider' => 'SignalWire DataSphere',
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
