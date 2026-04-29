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
        $spaceName = (string) ($this->params['space_name'] ?? '');
        $projectId = (string) ($this->params['project_id'] ?? '');
        $token = (string) ($this->params['token'] ?? '');
        $documentId = (string) ($this->params['document_id'] ?? '');
        $count = max(1, min(10, (int) ($this->params['count'] ?? 1)));
        $distance = (float) ($this->params['distance'] ?? 3.0);
        $timeout = max(2, (int) ($this->params['timeout'] ?? 30));
        $tags = $this->params['tags'] ?? null;
        $language = $this->params['language'] ?? null;
        $posToExpand = $this->params['pos_to_expand'] ?? null;
        $maxSynonyms = $this->params['max_synonyms'] ?? null;
        $noResultsMessage = (string) ($this->params['no_results_message']
            ?? "I couldn't find any relevant information for '{query}' in the knowledge base. "
                . 'Try rephrasing your question or asking about a different topic.');

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
     * @param array<int,mixed> $chunks
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
