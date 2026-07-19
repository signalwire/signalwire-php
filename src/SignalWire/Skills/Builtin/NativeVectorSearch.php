<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\HttpHelper;
use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

/**
 * Native vector search skill — network/remote mode only.
 *
 * Mirrors signalwire-python's
 * `signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill`,
 * specifically the `_search_remote` branch:
 *
 *   POST {remote_url}/search
 *   Auth: Optional Basic (parsed from remote_url's user:pass@ if set)
 *   Body: { query, index_name, count, similarity_threshold, tags }
 *   Response: { results: [{ content, score, metadata }] }
 *
 * The Python skill also supports a local SQLite/pgvector backend via
 * `signalwire.search`. PHP doesn't ship that backend; in remote mode
 * the SDK is just a thin HTTP client, which the audit verifies. Local
 * backend is recorded in PORT_OMISSIONS.md.
 */
class NativeVectorSearch extends SkillBase
{
    /** The name. */
    public function getName(): string
    {
        return 'native_vector_search';
    }

    /**
     * Narrow a genuinely-mixed value (remote search JSON) to a string.
     * Numeric scalars are stringified; anything else → ''.
     */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Narrow a genuinely-mixed value (remote search JSON) to a float.
     * Numeric values are coerced; anything else → 0.0.
     */
    private static function asFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** The description. */
    public function getDescription(): string
    {
        return 'Search document indexes using vector similarity and keyword search (local or remote)';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    /**
     * Unique instance key for this skill instance.
     *
     * Mirrors Python `NativeVectorSearchSkill.get_instance_key` (skill.py:234):
     * the tool name and index file together differentiate instances.
     */
    public function getInstanceKey(): string
    {
        $toolName = $this->getToolName('search_knowledge');
        $indexFile = $this->paramString('index_file', 'default');
        if ($indexFile === '') {
            $indexFile = 'default';
        }
        return 'native_vector_search_' . $toolName . '_' . $indexFile;
    }

    /**
     * Data to add to the agent's global context.
     *
     * Mirrors Python `NativeVectorSearchSkill.get_global_data` (skill.py:879),
     * which enriches with search-engine stats when an engine is loaded. The
     * PHP skill runs in network mode (no in-process engine), so there are no
     * local stats to add and this returns an empty map.
     *
     * @return array<string,mixed>
     */
    public function getGlobalData(): array
    {
        return [];
    }

    /**
     * Prompt sections for the agent.
     *
     * Mirrors Python `NativeVectorSearchSkill.get_prompt_sections`
     * (skill.py:892): the skill adds its prompt section during register_tools
     * (once the agent is set), so this returns an empty list.
     *
     * @return list<array{title: string, body?: string, bullets?: list<string>}>
     */
    public function getPromptSections(): array
    {
        return [];
    }

    /**
     * Release resources when the skill is unloaded.
     *
     * Mirrors Python `NativeVectorSearchSkill.cleanup` (skill.py:914), which
     * removes any temp directories it created during indexing. The PHP skill
     * creates no temp state (network mode), so cleanup is a no-op.
     */
    public function cleanup(): void
    {
    }

    /**
     * Parameter schema for the Native Vector Search skill.
     *
     * Mirrors Python `NativeVectorSearchSkill.get_parameter_schema`
     * (skill.py:38): advertises every configuration parameter across the
     * network / pgvector / SQLite modes.
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        $schema = parent::getParameterSchema();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $schema['properties'] = array_merge($properties, [
            'index_file' => [
                'type' => 'string',
                'description' => 'Path to .swsearch index file (SQLite backend only). Use this for local file-based search',
                'required' => false,
            ],
            'build_index' => [
                'type' => 'boolean',
                'description' => 'Whether to build index from source files',
                'default' => false,
                'required' => false,
            ],
            'source_dir' => [
                'type' => 'string',
                'description' => 'Directory containing documents to index (required if build_index=True)',
                'required' => false,
            ],
            'remote_url' => [
                'type' => 'string',
                'description' => 'URL of remote search server for network mode (e.g., http://localhost:8001). Use this instead of index_file or pgvector for centralized search',
                'required' => false,
            ],
            'index_name' => [
                'type' => 'string',
                'description' => 'Name of index on remote server (network mode only, used with remote_url)',
                'default' => 'default',
                'required' => false,
            ],
            'count' => [
                'type' => 'integer',
                'description' => 'Number of search results to return',
                'default' => 5,
                'required' => false,
                'minimum' => 1,
                'maximum' => 20,
            ],
            'similarity_threshold' => [
                'type' => 'number',
                'description' => 'Minimum similarity score for results (0.0 = no limit, 1.0 = exact match)',
                'default' => 0.0,
                'required' => false,
                'minimum' => 0.0,
                'maximum' => 1.0,
            ],
            'tags' => [
                'type' => 'array',
                'description' => 'Tags to filter search results',
                'default' => [],
                'required' => false,
                'items' => ['type' => 'string'],
            ],
            'global_tags' => [
                'type' => 'array',
                'description' => 'Tags to apply to all indexed documents',
                'default' => [],
                'required' => false,
                'items' => ['type' => 'string'],
            ],
            'file_types' => [
                'type' => 'array',
                'description' => 'File extensions to include when building index',
                'default' => ['md', 'txt', 'pdf', 'docx', 'html'],
                'required' => false,
                'items' => ['type' => 'string'],
            ],
            'exclude_patterns' => [
                'type' => 'array',
                'description' => 'Patterns to exclude when building index',
                // NB: the glob values embed a slash-star sequence, which the
                // surface enumerator's naive block-comment stripper would treat
                // as a comment opener and desync brace-depth. Build the
                // identical string values via concatenation so the source text
                // carries no slash-star token. Runtime values are byte-identical
                // to Python's node_modules / dot-git / dist / build glob set.
                'default' => [
                    '**' . '/node_modules/' . '**',
                    '**' . '/.git/' . '**',
                    '**' . '/dist/' . '**',
                    '**' . '/build/' . '**',
                ],
                'required' => false,
                'items' => ['type' => 'string'],
            ],
            'no_results_message' => [
                'type' => 'string',
                'description' => 'Message when no results are found',
                'default' => "No information found for '{query}'",
                'required' => false,
            ],
            'response_prefix' => [
                'type' => 'string',
                'description' => 'Prefix to add to search results',
                'default' => '',
                'required' => false,
            ],
            'response_postfix' => [
                'type' => 'string',
                'description' => 'Postfix to add to search results',
                'default' => '',
                'required' => false,
            ],
            'max_content_length' => [
                'type' => 'integer',
                'description' => 'Maximum total response size in characters (distributed across all results)',
                'default' => 32768,
                'required' => false,
                'minimum' => 1000,
            ],
            'response_format_callback' => [
                'type' => 'callable',
                'description' => 'Optional callback function to format/transform the response. Called with (response, agent, query, results, args). Must return a string.',
                'required' => false,
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Tool description',
                'default' => 'Search the knowledge base for information',
                'required' => false,
            ],
            'hints' => [
                'type' => 'array',
                'description' => 'Speech recognition hints',
                'default' => [],
                'required' => false,
                'items' => ['type' => 'string'],
            ],
            'nlp_backend' => [
                'type' => 'string',
                'description' => 'NLP backend for query processing',
                'default' => 'basic',
                'required' => false,
                'enum' => ['basic', 'spacy', 'nltk'],
            ],
            'query_nlp_backend' => [
                'type' => 'string',
                'description' => 'NLP backend for query expansion',
                'required' => false,
                'enum' => ['basic', 'spacy', 'nltk'],
            ],
            'index_nlp_backend' => [
                'type' => 'string',
                'description' => 'NLP backend for indexing',
                'required' => false,
                'enum' => ['basic', 'spacy', 'nltk'],
            ],
            'backend' => [
                'type' => 'string',
                'description' => "Storage backend for local database mode: 'sqlite' for file-based or 'pgvector' for PostgreSQL. Ignored if remote_url is set",
                'default' => 'sqlite',
                'required' => false,
                'enum' => ['sqlite', 'pgvector'],
            ],
            'connection_string' => [
                'type' => 'string',
                'description' => "PostgreSQL connection string (pgvector backend only, e.g., 'postgresql://user:pass@localhost:5432/dbname'). Required when backend='pgvector'",
                'required' => false,
            ],
            'collection_name' => [
                'type' => 'string',
                'description' => 'Collection/table name in PostgreSQL (pgvector backend only). Required when backend=\'pgvector\'',
                'required' => false,
            ],
            'verbose' => [
                'type' => 'boolean',
                'description' => 'Enable verbose logging',
                'default' => false,
                'required' => false,
            ],
            'keyword_weight' => [
                'type' => 'number',
                'description' => 'Manual keyword weight (0.0-1.0). Overrides automatic weight detection',
                'default' => null,
                'required' => false,
                'minimum' => 0.0,
                'maximum' => 1.0,
            ],
            'model_name' => [
                'type' => 'string',
                'description' => "Embedding model to use. Options: 'mini' (fastest, 384 dims), 'base' (balanced, 768 dims), 'large' (same as base). Or specify full model name like 'sentence-transformers/all-MiniLM-L6-v2'",
                'default' => 'mini',
                'required' => false,
            ],
            'overwrite' => [
                'type' => 'boolean',
                'description' => 'Overwrite existing pgvector collection when building index (pgvector backend only)',
                'default' => false,
                'required' => false,
            ],
        ]);
        return $schema;
    }

    public function setup(): bool
    {
        // Either remote_url is set (network mode, supported), or
        // index_file is set (local mode, NOT supported in PHP).
        $remoteUrl = $this->paramString('remote_url');
        $indexFile = $this->paramString('index_file');
        return $remoteUrl !== '' || $indexFile !== '';
    }

    public function registerTools(): void
    {
        $toolName = $this->getToolName('search_knowledge');
        $toolDescription = $this->paramString('description', 'Search the local knowledge base for information');
        $defaultCount = max(1, min(20, $this->paramInt('count', 5)));
        $similarityThreshold = $this->paramFloat('similarity_threshold', 0.0);
        $tags = $this->paramArray('tags');
        $maxContentLength = max(1000, $this->paramInt('max_content_length', 32768));
        $noResultsMessage = $this->paramString('no_results_message', "No information found for '{query}'");
        $responsePrefix = $this->paramString('response_prefix');
        $responsePostfix = $this->paramString('response_postfix');
        $remoteUrl = $this->paramString('remote_url');
        $indexName = $this->paramString('index_name', 'default');
        $timeout = max(2, $this->paramInt('timeout', 30));

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
            function (array $args, array $rawData) use (
                $defaultCount,
                $similarityThreshold,
                $tags,
                $maxContentLength,
                $noResultsMessage,
                $responsePrefix,
                $responsePostfix,
                $remoteUrl,
                $indexName,
                $timeout,
            ): FunctionResult {
                $queryArg = $args['query'] ?? '';
                $query = trim(is_string($queryArg) ? $queryArg : '');
                $countArg = $args['count'] ?? null;
                $count = max(1, is_numeric($countArg) ? (int) $countArg : $defaultCount);

                if ($query === '') {
                    return new FunctionResult('Please provide a search query.');
                }

                if ($remoteUrl === '') {
                    return new FunctionResult(
                        'Search functionality not available: this PHP build only '
                        . 'supports remote mode (set remote_url). Local SQLite / '
                        . 'pgvector backends are documented as omissions.'
                    );
                }

                // Parse credentials embedded in remote_url, if any.
                [$baseUrl, $basicAuth] = $this->parseRemoteUrl($remoteUrl);
                $endpoint = rtrim($baseUrl, '/') . '/search';

                try {
                    [$status, $rawBody, $parsed] = HttpHelper::postJson(
                        $endpoint,
                        body: [
                            'query' => $query,
                            'index_name' => $indexName,
                            'count' => $count,
                            'similarity_threshold' => $similarityThreshold,
                            'tags' => $tags,
                        ],
                        basicAuth: $basicAuth,
                        timeout: $timeout,
                    );
                } catch (\RuntimeException $e) {
                    return new FunctionResult(
                        'The search service is temporarily unavailable: '
                        . $e->getMessage()
                    );
                }

                if ($status < 200 || $status >= 300) {
                    return new FunctionResult(
                        "Remote search returned HTTP {$status}"
                    );
                }
                if (!is_array($parsed)) {
                    $msg = str_replace('{query}', $query, $noResultsMessage);
                    return new FunctionResult($msg);
                }
                $results = $parsed['results'] ?? [];
                if (!is_array($results) || count($results) === 0) {
                    $msg = str_replace('{query}', $query, $noResultsMessage);
                    if ($responsePrefix !== '') {
                        $msg = $responsePrefix . ' ' . $msg;
                    }
                    if ($responsePostfix !== '') {
                        $msg = $msg . ' ' . $responsePostfix;
                    }
                    return new FunctionResult($msg);
                }

                return new FunctionResult($this->formatResults(
                    $query,
                    $results,
                    $maxContentLength,
                    $responsePrefix,
                    $responsePostfix,
                ));
            }
        );
    }

    /**
     * @return array{0:string, 1: array{0:string,1:string}|null}
     */
    private function parseRemoteUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            return [$url, null];
        }
        $auth = null;
        if (isset($parts['user'])) {
            $auth = [
                (string) $parts['user'],
                (string) ($parts['pass'] ?? ''),
            ];
        }
        $base = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        if (isset($parts['path'])) {
            $base .= $parts['path'];
        }
        return [$base, $auth];
    }

    /**
     * Format the remote search results into a single response string,
     * mirroring the Python skill's per-result truncation behaviour.
     *
     * @param array<mixed> $results
     */
    private function formatResults(
        string $query,
        array $results,
        int $maxContentLength,
        string $responsePrefix,
        string $responsePostfix,
    ): string {
        $count = count($results);
        $estimatedOverheadPerResult = 300;
        $prefixPostfixOverhead = strlen($responsePrefix) + strlen($responsePostfix) + 100;
        $available = $maxContentLength - ($count * $estimatedOverheadPerResult) - $prefixPostfixOverhead;
        $perResultLimit = $count > 0
            ? max(500, intdiv($available, max(1, $count)))
            : 1000;

        $lines = [];
        if ($responsePrefix !== '') {
            $lines[] = $responsePrefix;
        }
        $lines[] = sprintf('Found %d relevant results for \'%s\':', $count, $query);
        $lines[] = '';

        $i = 0;
        foreach ($results as $result) {
            $i++;
            if (!is_array($result)) {
                continue;
            }
            $content = self::asString($result['content'] ?? null);
            $score = self::asFloat($result['score'] ?? null);
            $metadataRaw = $result['metadata'] ?? null;
            $metadata = is_array($metadataRaw) ? $metadataRaw : [];
            $filename = self::asString($metadata['filename'] ?? null);
            $section = self::asString($metadata['section'] ?? null);

            if (strlen($content) > $perResultLimit) {
                $content = substr($content, 0, $perResultLimit) . '...';
            }

            $line = "**Result {$i}**";
            if ($filename !== '') {
                $line .= " (from {$filename}";
                if ($section !== '') {
                    $line .= ", section: {$section}";
                }
                $line .= sprintf(', relevance: %.2f)', $score);
            } else {
                $line .= sprintf(' (relevance: %.2f)', $score);
            }
            $line .= "\n" . $content;
            $lines[] = $line;
        }
        if ($responsePostfix !== '') {
            $lines[] = $responsePostfix;
        }
        return implode("\n\n", $lines);
    }

    /**
     * @return list<string>
     */
    public function getHints(): array
    {
        $hints = ['search', 'find', 'look up', 'documentation', 'knowledge base'];
        $custom = $this->params['hints'] ?? [];
        if (is_array($custom)) {
            foreach ($custom as $hint) {
                if (is_string($hint) && !in_array($hint, $hints, true)) {
                    $hints[] = $hint;
                }
            }
        }
        return $hints;
    }
}
