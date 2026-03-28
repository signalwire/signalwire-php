<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class Spider extends SkillBase
{
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
        $prefix = $this->params['tool_prefix'] ?? '';

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
            function (array $args, array $rawData): FunctionResult {
                $result = new FunctionResult();
                $url = $args['url'] ?? '';

                if ($url === '') {
                    $result->setResponse('Error: No URL provided.');
                    return $result;
                }

                $maxLength = $this->params['max_text_length'] ?? 5000;
                $extractType = $this->params['extract_type'] ?? 'clean_text';

                // Stub: in production, this would fetch and parse HTML
                $result->setResponse(
                    'Scraped content from "' . $url . '" '
                    . '(extract type: ' . $extractType . ', max length: ' . $maxLength . '). '
                    . 'In production, this would return the parsed text content of the page.'
                );

                return $result;
            }
        );

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
            function (array $args, array $rawData): FunctionResult {
                $result = new FunctionResult();
                $startUrl = $args['start_url'] ?? '';

                if ($startUrl === '') {
                    $result->setResponse('Error: No start URL provided.');
                    return $result;
                }

                $maxPages = $this->params['max_pages'] ?? 10;
                $maxDepth = $this->params['max_depth'] ?? 3;

                // Stub: in production, this would crawl the site
                $result->setResponse(
                    'Crawled site starting from "' . $startUrl . '" '
                    . '(max pages: ' . $maxPages . ', max depth: ' . $maxDepth . '). '
                    . 'In production, this would return collected content from multiple pages.'
                );

                return $result;
            }
        );

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
            function (array $args, array $rawData): FunctionResult {
                $result = new FunctionResult();
                $url = $args['url'] ?? '';

                if ($url === '') {
                    $result->setResponse('Error: No URL provided.');
                    return $result;
                }

                $selectors = $this->params['selectors'] ?? [];

                // Stub: in production, this would extract structured data using selectors
                $result->setResponse(
                    'Extracted structured data from "' . $url . '". '
                    . 'In production, this would return structured data extracted using CSS selectors or schema.org markup.'
                );

                return $result;
            }
        );
    }

    public function getHints(): array
    {
        return ['scrape', 'crawl', 'extract', 'web page', 'website', 'spider'];
    }
}
