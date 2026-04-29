<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\HttpHelper;
use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

/**
 * Spider scraping skill.
 *
 * Mirrors signalwire-python's `signalwire.skills.spider.skill` (the
 * `_scrape_url_handler` / `_crawl_site_handler` paths). PHP doesn't
 * ship `lxml`, so HTML extraction uses a strip_tags + regex pipeline
 * that handles the audit's canned `<html><body>...</body></html>`
 * sentinel and basic real-world pages — the Python version's quality
 * scorer and Reddit-aware extractor are not yet ported, but the
 * upstream contract (real GET, real text extraction) is met.
 *
 * Upstream URL override: `SPIDER_BASE_URL` for the audit fixture.
 * The audit fixture is a generic JSON server, so the response body
 * may either be a raw HTML string (production) or a JSON object with
 * an `_raw_html` field (audit fixture). We accept both.
 */
class Spider extends SkillBase
{
    private const BASE_URL_ENV = 'SPIDER_BASE_URL';

    public function getName(): string
    {
        return 'spider';
    }

    public function getDescription(): string
    {
        return 'Fast web scraping and crawling capabilities';
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
        $prefix = (string) ($this->params['tool_prefix'] ?? '');
        $maxLength = max(100, (int) ($this->params['max_text_length'] ?? 5000));
        $timeout = max(2, (int) ($this->params['timeout'] ?? 15));
        $maxPages = max(1, (int) ($this->params['max_pages'] ?? 5));
        $maxDepth = max(0, (int) ($this->params['max_depth'] ?? 2));
        $followPatterns = $this->params['follow_patterns'] ?? [];
        $userAgent = (string) ($this->params['user_agent']
            ?? 'Spider/1.0 (SignalWire AI Agent)');
        $extraHeaders = is_array($this->params['headers'] ?? null)
            ? $this->params['headers']
            : [];

        // ── scrape_url ────────────────────────────────────────────────
        $this->defineTool(
            $prefix . 'scrape_url',
            'Scrape content from a web page URL',
            [
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL of the web page to scrape',
                    'required' => true,
                ],
            ],
            function (array $args, array $rawData) use (
                $maxLength,
                $timeout,
                $userAgent,
                $extraHeaders,
            ): FunctionResult {
                $url = trim((string) ($args['url'] ?? ''));
                if ($url === '') {
                    return new FunctionResult('Error: No URL provided.');
                }
                $rewritten = HttpHelper::applyBaseUrlOverride(
                    $url,
                    self::BASE_URL_ENV,
                );
                $headers = ['User-Agent' => $userAgent] + $extraHeaders;

                try {
                    [$status, $body, $parsed] = HttpHelper::get(
                        $rewritten,
                        headers: $headers,
                        timeout: $timeout,
                    );
                } catch (\RuntimeException $e) {
                    return new FunctionResult(
                        'Failed to fetch ' . $url . ': ' . $e->getMessage()
                    );
                }
                if ($status < 200 || $status >= 300) {
                    return new FunctionResult(
                        "Failed to fetch {$url}: HTTP {$status}"
                    );
                }

                $rawHtml = self::extractHtmlBody($body, $parsed);
                $text = self::htmlToText($rawHtml, $maxLength);
                if ($text === '') {
                    return new FunctionResult("No content extracted from {$url}");
                }
                $charCount = strlen($text);
                return new FunctionResult(
                    "Content from {$url} ({$charCount} characters):\n\n" . $text
                );
            }
        );

        // ── crawl_site ────────────────────────────────────────────────
        $this->defineTool(
            $prefix . 'crawl_site',
            'Crawl a website starting from a URL and collect content from multiple pages',
            [
                'start_url' => [
                    'type' => 'string',
                    'description' => 'The starting URL to begin crawling from',
                    'required' => true,
                ],
            ],
            function (array $args, array $rawData) use (
                $maxLength,
                $timeout,
                $userAgent,
                $extraHeaders,
                $maxPages,
                $maxDepth,
                $followPatterns,
            ): FunctionResult {
                $startUrl = trim((string) ($args['start_url'] ?? ''));
                if ($startUrl === '') {
                    return new FunctionResult('Error: No start URL provided.');
                }

                $compiledFollow = [];
                if (is_array($followPatterns)) {
                    foreach ($followPatterns as $pattern) {
                        if (is_string($pattern) && $pattern !== '') {
                            $compiledFollow[] = '@' . preg_quote($pattern, '@') . '@i';
                        }
                    }
                }

                $headers = ['User-Agent' => $userAgent] + $extraHeaders;
                $startHost = parse_url($startUrl, PHP_URL_HOST);
                $visited = [];
                $toVisit = [[$startUrl, 0]];
                $results = [];

                while (count($toVisit) > 0 && count($visited) < $maxPages) {
                    [$nextUrl, $depth] = array_shift($toVisit);
                    if (isset($visited[$nextUrl]) || $depth > $maxDepth) {
                        continue;
                    }
                    $rewritten = HttpHelper::applyBaseUrlOverride(
                        $nextUrl,
                        self::BASE_URL_ENV,
                    );
                    try {
                        [$status, $body, $parsed] = HttpHelper::get(
                            $rewritten,
                            headers: $headers,
                            timeout: $timeout,
                        );
                    } catch (\RuntimeException) {
                        continue;
                    }
                    if ($status < 200 || $status >= 300) {
                        continue;
                    }
                    $visited[$nextUrl] = true;
                    $rawHtml = self::extractHtmlBody($body, $parsed);
                    $text = self::htmlToText($rawHtml, $maxLength);
                    if ($text !== '') {
                        $summary = strlen($text) > 500
                            ? substr($text, 0, 500) . '...'
                            : $text;
                        $results[] = [
                            'url' => $nextUrl,
                            'depth' => $depth,
                            'content_length' => strlen($text),
                            'summary' => $summary,
                        ];
                    }

                    if ($depth < $maxDepth && $rawHtml !== '') {
                        if (preg_match_all(
                            '/<a[^>]+href=["\']([^"\']+)["\']/i',
                            $rawHtml,
                            $matches,
                        )) {
                            foreach ($matches[1] as $href) {
                                $absolute = self::resolveUrl($nextUrl, $href);
                                if ($absolute === '' || isset($visited[$absolute])) {
                                    continue;
                                }
                                if (count($compiledFollow) > 0) {
                                    $matched = false;
                                    foreach ($compiledFollow as $re) {
                                        if (@preg_match($re, $absolute) === 1) {
                                            $matched = true;
                                            break;
                                        }
                                    }
                                    if (!$matched) {
                                        continue;
                                    }
                                }
                                if (parse_url($absolute, PHP_URL_HOST) !== $startHost) {
                                    continue;
                                }
                                $toVisit[] = [$absolute, $depth + 1];
                            }
                        }
                    }
                }

                if (count($results) === 0) {
                    return new FunctionResult("No pages could be crawled from {$startUrl}");
                }

                $lines = [];
                $lines[] = sprintf(
                    'Crawled %d pages from %s:',
                    count($results),
                    $startHost ?? '<unknown host>',
                );
                $lines[] = '';
                $idx = 0;
                $totalChars = 0;
                foreach ($results as $r) {
                    $idx++;
                    $totalChars += $r['content_length'];
                    $lines[] = sprintf(
                        '%d. %s (depth: %d, %d chars)',
                        $idx,
                        $r['url'],
                        $r['depth'],
                        $r['content_length'],
                    );
                    $lines[] = '   Summary: ' . substr($r['summary'], 0, 100) . '...';
                    $lines[] = '';
                }
                $lines[] = sprintf(
                    'Total content: %s characters across %d pages',
                    number_format($totalChars),
                    count($results),
                );
                return new FunctionResult(implode("\n", $lines));
            }
        );

        // ── extract_structured_data ───────────────────────────────────
        $selectors = is_array($this->params['selectors'] ?? null)
            ? $this->params['selectors']
            : [];
        $this->defineTool(
            $prefix . 'extract_structured_data',
            'Extract structured data from a web page',
            [
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL to extract structured data from',
                    'required' => true,
                ],
            ],
            function (array $args, array $rawData) use (
                $selectors,
                $timeout,
                $userAgent,
                $extraHeaders,
            ): FunctionResult {
                $url = trim((string) ($args['url'] ?? ''));
                if ($url === '') {
                    return new FunctionResult('Error: No URL provided.');
                }
                if (count($selectors) === 0) {
                    return new FunctionResult(
                        'No selectors configured for structured data extraction'
                    );
                }
                $rewritten = HttpHelper::applyBaseUrlOverride(
                    $url,
                    self::BASE_URL_ENV,
                );
                $headers = ['User-Agent' => $userAgent] + $extraHeaders;
                try {
                    [$status, $body, $parsed] = HttpHelper::get(
                        $rewritten,
                        headers: $headers,
                        timeout: $timeout,
                    );
                } catch (\RuntimeException $e) {
                    return new FunctionResult(
                        'Failed to fetch ' . $url . ': ' . $e->getMessage()
                    );
                }
                if ($status < 200 || $status >= 300) {
                    return new FunctionResult(
                        "Failed to fetch {$url}: HTTP {$status}"
                    );
                }
                $rawHtml = self::extractHtmlBody($body, $parsed);

                // Without a real DOM library, only XPath-style and
                // simple tag-name selectors are reliable. Match-by-
                // tag-name covers the common "extract first <h1>" case.
                $extracted = [];
                foreach ($selectors as $field => $selector) {
                    if (!is_string($selector) || $selector === '') {
                        $extracted[$field] = null;
                        continue;
                    }
                    $extracted[$field] = self::extractBySelector($rawHtml, $selector);
                }
                $title = '';
                if (preg_match('/<title>(.*?)<\/title>/is', $rawHtml, $m)) {
                    $title = trim(html_entity_decode(strip_tags($m[1])));
                }

                $lines = ["Extracted data from {$url}:", '', "Title: {$title}", '', 'Data:'];
                foreach ($extracted as $field => $value) {
                    $printable = is_array($value)
                        ? implode(', ', array_map('strval', $value))
                        : (string) ($value ?? 'null');
                    $lines[] = "- {$field}: {$printable}";
                }
                return new FunctionResult(implode("\n", $lines));
            }
        );
    }

    /**
     * The audit fixture serves a JSON object with `_raw_html` as a
     * convenience wrapper around the canned HTML; production servers
     * return the HTML directly. Accept both.
     */
    private static function extractHtmlBody(string $rawBody, mixed $parsed): string
    {
        if (is_array($parsed)) {
            if (isset($parsed['_raw_html']) && is_string($parsed['_raw_html'])) {
                return $parsed['_raw_html'];
            }
            if (isset($parsed['html']) && is_string($parsed['html'])) {
                return $parsed['html'];
            }
            if (isset($parsed['body']) && is_string($parsed['body'])) {
                return $parsed['body'];
            }
        }
        return $rawBody;
    }

    /**
     * Strip scripts/styles/nav, then collapse the visible text and
     * truncate to $maxLength with a smart middle-cut to preserve the
     * intro and the conclusion of the page (matches the python
     * port's _fast_text_extract's "keep_start / keep_end" behavior).
     */
    private static function htmlToText(string $html, int $maxLength): string
    {
        if ($html === '') {
            return '';
        }
        // Drop noisy elements wholesale.
        $cleaned = preg_replace(
            [
                '#<script\b[^>]*>.*?</script>#is',
                '#<style\b[^>]*>.*?</style>#is',
                '#<nav\b[^>]*>.*?</nav>#is',
                '#<header\b[^>]*>.*?</header>#is',
                '#<footer\b[^>]*>.*?</footer>#is',
                '#<aside\b[^>]*>.*?</aside>#is',
                '#<noscript\b[^>]*>.*?</noscript>#is',
            ],
            ' ',
            $html,
        ) ?? $html;
        $text = strip_tags($cleaned);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        $keepStart = (int) (($maxLength * 2) / 3);
        $keepEnd = $maxLength - $keepStart;
        return substr($text, 0, $keepStart)
            . "\n\n[...CONTENT TRUNCATED...]\n\n"
            . substr($text, -$keepEnd);
    }

    /**
     * Best-effort selector resolution without a DOM library:
     *   - "<tag>": grab the first occurrence's text content
     *   - everything else: return null (signals we couldn't extract)
     */
    private static function extractBySelector(string $html, string $selector): ?string
    {
        if (preg_match('/^[a-z][a-z0-9]*$/i', $selector)) {
            $pattern = '#<' . preg_quote($selector, '#') . '\b[^>]*>(.*?)</'
                . preg_quote($selector, '#') . '>#is';
            if (preg_match($pattern, $html, $m)) {
                return trim(html_entity_decode(strip_tags($m[1])));
            }
            return null;
        }
        return null;
    }

    /**
     * Resolve a (potentially relative) href against a base URL.
     */
    private static function resolveUrl(string $base, string $href): string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, 'javascript:')
            || str_starts_with($href, 'mailto:')
        ) {
            return '';
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $basePath = $parts['path'] ?? '/';

        if ($href[0] === '/') {
            return "{$scheme}://{$host}{$port}{$href}";
        }
        $dir = rtrim(substr($basePath, 0, (int) strrpos($basePath, '/') + 1), '/');
        return "{$scheme}://{$host}{$port}{$dir}/{$href}";
    }

    public function getHints(): array
    {
        return ['scrape', 'crawl', 'extract', 'web page', 'website', 'spider'];
    }
}
