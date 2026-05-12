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

    /** @var string[] Valid question types */
    private const VALID_TYPES = ['rating', 'multiple_choice', 'yes_no', 'open_ended'];

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

        // Tool: validate_response
        $capturedQuestions = $this->surveyQuestions;
        $this->defineTool(
            name: 'validate_response',
            description: 'Validate a survey response against the question type constraints',
            parameters: [
                'question_id' => ['type' => 'string', 'description' => 'ID of the question'],
                'answer'      => ['type' => 'string', 'description' => 'The response to validate'],
            ],
            handler: function (array $args, array $rawData) use ($capturedQuestions): FunctionResult {
                $questionId = $args['question_id'] ?? '';
                $answer     = $args['answer'] ?? '';

                // Find the question
                $question = null;
                foreach ($capturedQuestions as $q) {
                    if ($q['id'] === $questionId) {
                        $question = $q;
                        break;
                    }
                }

                if ($question === null) {
                    return new FunctionResult("Unknown question ID: {$questionId}");
                }

                $type = $question['type'] ?? 'open_ended';

                switch ($type) {
                    case 'rating':
                        $scale = $question['scale'] ?? 5;
                        $val = (int) $answer;
                        if ($val < 1 || $val > $scale) {
                            return new FunctionResult("Invalid rating. Please provide a number between 1 and {$scale}.");
                        }
                        return new FunctionResult("Valid rating: {$val}/{$scale}");

                    case 'multiple_choice':
                        $choices = $question['choices'] ?? [];
                        $lowerAnswer = strtolower(trim($answer));
                        foreach ($choices as $choice) {
                            if (strtolower(trim($choice)) === $lowerAnswer) {
                                return new FunctionResult("Valid choice: {$choice}");
                            }
                        }
                        $choiceList = implode(', ', $choices);
                        return new FunctionResult("Invalid choice. Valid options are: {$choiceList}");

                    case 'yes_no':
                        $normalized = strtolower(trim($answer));
                        if (in_array($normalized, ['yes', 'no', 'y', 'n'], true)) {
                            return new FunctionResult("Valid response: {$normalized}");
                        }
                        return new FunctionResult('Please respond with yes or no.');

                    case 'open_ended':
                    default:
                        if (trim($answer) === '') {
                            return new FunctionResult('Please provide a non-empty response.');
                        }
                        return new FunctionResult("Response accepted: {$answer}");
                }
            },
        );

        // Tool: log_response
        $this->defineTool(
            name: 'log_response',
            description: 'Log a validated survey response',
            parameters: [
                'question_id' => ['type' => 'string', 'description' => 'ID of the question'],
                'answer'      => ['type' => 'string', 'description' => 'The validated answer'],
            ],
            handler: function (array $args, array $rawData): FunctionResult {
                $questionId = $args['question_id'] ?? '';
                $answer     = $args['answer'] ?? '';
                return new FunctionResult("Survey answer for {$questionId}: {$answer}");
            },
        );
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
