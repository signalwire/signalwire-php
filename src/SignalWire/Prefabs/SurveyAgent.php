<?php

declare(strict_types=1);

namespace SignalWire\Prefabs;

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

class SurveyAgent extends AgentBase
{
    /** @var string */
    protected string $surveyName;

    /** @var list<array{id: string, text: string, type: string, required?: bool, scale?: int, choices?: list<string>}> */
    protected array $surveyQuestions;

    protected string $introduction;
    protected string $conclusion;
    protected string $brandName;
    protected int $maxRetries;

    /**
     * @param string $name Agent name
     * @param list<array{id: string, text: string, type: string, required?: bool, scale?: int, choices?: list<string>}> $questions
     * @param string $route
     * @param string|null $host
     * @param int|null $port
     * @param string|null $basicAuthUser
     * @param string|null $basicAuthPassword
     * @param bool $autoAnswer
     * @param bool $recordCall
     * @param bool $usePom
     * @param string|null $surveyName
     * @param string $introduction
     * @param string $conclusion
     * @param string $brandName
     * @param int $maxRetries
     */
    public function __construct(
        string $name,
        array $questions,
        string $route = '/survey',
        ?string $host = null,
        ?int $port = null,
        ?string $basicAuthUser = null,
        ?string $basicAuthPassword = null,
        bool $autoAnswer = true,
        bool $recordCall = false,
        bool $usePom = true,
        ?string $surveyName = null,
        string $introduction = '',
        string $conclusion = '',
        string $brandName = '',
        int $maxRetries = 2,
    ) {
        $this->surveyName     = $surveyName ?? ($name !== '' ? $name : 'Survey');
        $this->introduction   = $introduction;
        $this->conclusion     = $conclusion;
        $this->brandName      = $brandName;
        $this->maxRetries     = $maxRetries;
        $this->surveyQuestions = $questions;

        $name = $name !== '' ? $name : 'survey';

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

        $this->usePom = true;

        // Global data
        $this->setGlobalData([
            'survey_name'    => $this->surveyName,
            'questions'      => $this->surveyQuestions,
            'question_index' => 0,
            'answers'        => new \stdClass(),
            'completed'      => false,
        ]);

        // Introduction section
        $intro = $this->introduction !== '' ? $this->introduction : "Welcome to the {$this->surveyName}.";
        $this->promptAddSection(
            'Survey Introduction',
            $intro,
            [
                'Introduce the survey to the user',
                'Ask each question in sequence',
                'Validate responses based on question type',
                'Thank the user when complete',
            ],
        );

        // Build question descriptions for prompt
        $qBullets = [];
        foreach ($this->surveyQuestions as $q) {
            $desc = "Q: {$q['text']} (type: {$q['type']})";
            if (!empty($q['required'])) {
                $desc .= ' [required]';
            }
            $qBullets[] = $desc;
        }
        $this->promptAddSection('Survey Questions', '', $qBullets);

        // Tool: validate_response — dispatches to the named handler method.
        $this->defineTool(
            name: 'validate_response',
            description: 'Validate if a response meets the requirements for a specific question',
            parameters: [
                'question_id' => ['type' => 'string', 'description' => 'ID of the question'],
                'response'    => ['type' => 'string', 'description' => 'The response to validate'],
            ],
            handler: fn (array $args, array $rawData): FunctionResult => $this->validateResponse($args, $rawData),
        );

        // Tool: log_response — dispatches to the named handler method.
        $this->defineTool(
            name: 'log_response',
            description: 'Log a validated response to a survey question',
            parameters: [
                'question_id' => ['type' => 'string', 'description' => 'ID of the question'],
                'response'    => ['type' => 'string', 'description' => 'The validated response'],
            ],
            handler: fn (array $args, array $rawData): FunctionResult => $this->logResponse($args, $rawData),
        );
    }

    /**
     * Validate a survey response against the question's type constraints.
     *
     * Mirrors Python `SurveyAgent.validate_response`.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $rawData
     */
    public function validateResponse(array $args, array $rawData): FunctionResult
    {
        $questionId = is_string($args['question_id'] ?? null) ? $args['question_id'] : '';
        $response   = is_string($args['response'] ?? null) ? $args['response'] : '';

        $question = null;
        foreach ($this->surveyQuestions as $q) {
            if ($q['id'] === $questionId) {
                $question = $q;
                break;
            }
        }

        if ($question === null) {
            return new FunctionResult("Error: Question with ID '{$questionId}' not found.");
        }

        $message = "Response to '{$questionId}' is valid.";

        switch ($question['type']) {
            case 'rating':
                $scale = $question['scale'] ?? 5;
                $trimmed = trim($response);
                if ($trimmed === '' || !ctype_digit(ltrim($trimmed, '-'))) {
                    $message = "Invalid rating. Please provide a number between 1 and {$scale}.";
                } else {
                    $rating = (int) $trimmed;
                    if ($rating < 1 || $rating > $scale) {
                        $message = "Invalid rating. Please provide a number between 1 and {$scale}.";
                    }
                }
                break;

            case 'multiple_choice':
                $choices = $question['choices'] ?? [];
                $lowerResponse = strtolower(trim($response));
                $match = false;
                foreach ($choices as $choice) {
                    if (strtolower($choice) === $lowerResponse) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) {
                    $choiceList = implode(', ', $choices);
                    $message = "Invalid choice. Please select one of: {$choiceList}.";
                }
                break;

            case 'yes_no':
                $normalized = strtolower(trim($response));
                if (!in_array($normalized, ['yes', 'no', 'y', 'n'], true)) {
                    $message = "Please answer with 'yes' or 'no'.";
                }
                break;

            case 'open_ended':
                $required = $question['required'] ?? true;
                if (trim($response) === '' && $required) {
                    $message = 'A response is required for this question.';
                }
                break;
        }

        return new FunctionResult($message);
    }

    /**
     * Log a validated response to a survey question.
     *
     * Mirrors Python `SurveyAgent.log_response`: in a real deployment this
     * would persist the response; here it acknowledges receipt.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $rawData
     */
    public function logResponse(array $args, array $rawData): FunctionResult
    {
        $questionId = is_string($args['question_id'] ?? null) ? $args['question_id'] : '';

        $questionText = '';
        foreach ($this->surveyQuestions as $q) {
            if ($q['id'] === $questionId) {
                $questionText = $q['text'];
                break;
            }
        }

        return new FunctionResult("Response to '{$questionText}' has been recorded.");
    }

    /**
     * Process the survey results summary.
     *
     * Mirrors Python `SurveyAgent.on_summary`: logs survey completion.
     * Subclasses can override to store responses or trigger follow-ups.
     *
     * @param array<string,mixed>|string|null $summary
     * @param array<string,mixed>|null        $rawData
     */
    public function onSummary(array|string|null $summary, ?array $rawData = null): void
    {
        if ($summary === null || $summary === '') {
            return;
        }
        if (is_array($summary)) {
            $this->logger->info('Survey completed: ' . json_encode($summary, JSON_PRETTY_PRINT));
        } else {
            $this->logger->info("Survey summary (unstructured): {$summary}");
        }
    }

    /**
     * @return list<array>
     */
    public function getSurveyQuestions(): array
    {
        return $this->surveyQuestions;
    }

    public function getSurveyName(): string
    {
        return $this->surveyName;
    }
}
