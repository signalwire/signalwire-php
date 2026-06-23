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
