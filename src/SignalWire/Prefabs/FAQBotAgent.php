<?php

declare(strict_types=1);

namespace SignalWire\Prefabs;

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

class FAQBotAgent extends AgentBase
{
    /** @var list<array{question: string, answer: string}> */
    protected array $faqs;

    protected bool $suggestRelated;
    protected string $persona;

    /**
     * @param string $name Agent name
     * @param list<array{question: string, answer: string}> $faqs Question/answer pairs
     * @param string $route
     * @param string|null $host
     * @param int|null $port
     * @param string|null $basicAuthUser
     * @param string|null $basicAuthPassword
     * @param bool $autoAnswer
     * @param bool $recordCall
     * @param bool $usePom
     * @param string|null $persona
     * @param bool $suggestRelated
     */
    public function __construct(
        string $name,
        array $faqs,
        string $route = '/faq',
        ?string $host = null,
        ?int $port = null,
        ?string $basicAuthUser = null,
        ?string $basicAuthPassword = null,
        bool $autoAnswer = true,
        bool $recordCall = false,
        bool $usePom = true,
        ?string $persona = null,
        bool $suggestRelated = true,
    ) {
        $this->suggestRelated = $suggestRelated;
        $this->persona        = $persona
            ?? 'You are a helpful FAQ bot that provides accurate answers to common questions.';

        $name = $name !== '' ? $name : 'faq_bot';

        parent::__construct(
            name: $name,
            route: $route,
            host: $host,
            port: $port,
            basicAuthUser: $basicAuthUser,
            basicAuthPassword: $basicAuthPassword,
            autoAnswer: $autoAnswer,
            recordCall: $recordCall,
            usePom: $usePom,
        );

        $this->faqs  = $faqs;
        $this->usePom = true;

        // Global data
        $this->setGlobalData([
            'faqs'            => $this->faqs,
            'suggest_related' => $this->suggestRelated,
        ]);

        // Persona section
        $this->promptAddSection('Personality', $this->persona);

        // Build FAQ knowledge section
        $faqBullets = [];
        foreach ($this->faqs as $faq) {
            $faqBullets[] = "Q: {$faq['question']} A: {$faq['answer']}";
        }
        $this->promptAddSection(
            'FAQ Knowledge Base',
            'You have knowledge of the following frequently asked questions.',
            $faqBullets,
        );

        // Optional related suggestions section
        if ($this->suggestRelated) {
            $this->promptAddSection(
                'Related Questions',
                'When appropriate, suggest related questions the user might also be interested in.',
            );
        }

        // Tool: search_faqs (keyword scoring)
        $capturedFaqs    = $this->faqs;
        $capturedSuggest = $this->suggestRelated;
        $this->defineTool(
            name: 'search_faqs',
            description: 'Search the FAQ knowledge base by keyword matching and return the best answer',
            parameters: [
                'query' => ['type' => 'string', 'description' => 'The question or keywords to search'],
            ],
            handler: function (array $args, array $rawData) use ($capturedFaqs, $capturedSuggest): FunctionResult {
                $query = strtolower(trim($args['query'] ?? ''));

                if ($query === '') {
                    return new FunctionResult('Please provide a search query.');
                }

                $keywords = preg_split('/\s+/', $query);

                // Score each FAQ by keyword matches
                $scored = [];
                foreach ($capturedFaqs as $index => $faq) {
                    $questionLower = strtolower($faq['question']);
                    $score = 0;

                    // Exact substring match gets highest score
                    if (str_contains($questionLower, $query)) {
                        $score += 10;
                    }

                    // Individual keyword matches
                    foreach ($keywords as $keyword) {
                        if ($keyword !== '' && str_contains($questionLower, $keyword)) {
                            $score++;
                        }
                    }

                    if ($score > 0) {
                        $scored[] = ['index' => $index, 'score' => $score, 'faq' => $faq];
                    }
                }

                if (empty($scored)) {
                    return new FunctionResult("No FAQ found matching: {$args['query']}");
                }

                // Sort by score descending
                usort($scored, fn(array $a, array $b): int => $b['score'] <=> $a['score']);

                $best = $scored[0]['faq'];
                $response = $best['answer'];

                // Suggest related questions if enabled
                if ($capturedSuggest && count($scored) > 1) {
                    $related = array_slice($scored, 1, 3);
                    $suggestions = array_map(
                        fn(array $item): string => $item['faq']['question'],
                        $related,
                    );
                    $response .= "\n\nRelated questions: " . implode('; ', $suggestions);
                }

                return new FunctionResult($response);
            },
        );
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    public function getFaqs(): array
    {
        return $this->faqs;
    }

    public function getSuggestRelated(): bool
    {
        return $this->suggestRelated;
    }
}
