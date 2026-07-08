<?php

declare(strict_types=1);

namespace SignalWire\Prefabs;

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

class InfoGathererAgent extends AgentBase
{
    /** @var list<array{key_name: string, question_text: string, confirm?: bool}> */
    protected array $questions;

    /**
     * Static questions supplied at construction, or null when the agent runs
     * in dynamic (callback-driven) mode.
     *
     * @var list<array{key_name: string, question_text: string, confirm?: bool}>|null
     */
    protected ?array $staticQuestions;

    /**
     * Dynamic-question callback: fn(array $queryParams, array $bodyParams,
     * array $headers): list<array{key_name:string,question_text:string,confirm?:bool}>.
     *
     * @var callable|null
     */
    protected $questionCallback = null;

    /**
     * @param string $name Agent name
     * @param list<array{key_name: string, question_text: string, confirm?: bool}> $questions
     * @param string $route
     * @param string|null $host
     * @param int|null $port
     * @param string|null $basicAuthUser
     * @param string|null $basicAuthPassword
     * @param bool $autoAnswer
     * @param bool $recordCall
     * @param bool $usePom
     */
    public function __construct(
        string $name,
        ?array $questions = null,
        string $route = '/info_gatherer',
        ?string $host = null,
        ?int $port = null,
        ?string $basicAuthUser = null,
        ?string $basicAuthPassword = null,
        bool $autoAnswer = true,
        bool $recordCall = false,
        bool $usePom = true,
    ) {
        $name = $name !== '' ? $name : 'info_gatherer';

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

        // null (Python `questions=None`) => dynamic mode, same as an empty list.
        $questions = $questions ?? [];
        $this->questions = $questions;
        // Non-empty question list => static mode; empty => dynamic (callback).
        $this->staticQuestions = $questions !== [] ? $questions : null;
        $this->usePom    = true;

        // Global data tracks question index and answers.
        $this->setGlobalData([
            'questions'      => $this->questions,
            'question_index' => 0,
            'answers'        => [],
        ]);

        // Prompt section.
        $this->promptAddSection(
            'Information Gathering',
            'You are an information-gathering assistant. Your job is to ask the user a series of questions and collect their answers.',
            [
                'Ask questions one at a time in order',
                'Wait for the user to answer before asking the next question',
                'Confirm answers when the question requires confirmation',
                'Use start_questions to begin and submit_answer for each response',
            ],
        );

        // Tool: start_questions — dispatches to the named handler method.
        $this->defineTool(
            name: 'start_questions',
            description: 'Start the question sequence by retrieving the first question',
            parameters: [],
            handler: fn (array $args, array $rawData): FunctionResult => $this->startQuestions($args, $rawData),
        );

        // Tool: submit_answer — dispatches to the named handler method.
        $this->defineTool(
            name: 'submit_answer',
            description: 'Submit an answer to the current question and move to the next one',
            parameters: [
                'answer' => ['type' => 'string', 'description' => "The user's answer to the current question"],
            ],
            handler: fn (array $args, array $rawData): FunctionResult => $this->submitAnswer($args, $rawData),
        );
    }

    /**
     * Set a callback for dynamic question configuration.
     *
     * Mirrors Python `InfoGathererAgent.set_question_callback`. The callback
     * receives (query_params, body_params, headers) and returns a list of
     * question dicts (each with key_name, question_text, and optional confirm).
     *
     * @param callable $callback fn(array,array,array): list<array<string,mixed>>
     */
    public function setQuestionCallback(callable $callback): void
    {
        $this->questionCallback = $callback;
    }

    /**
     * Validate that questions are in the correct format.
     *
     * Mirrors Python `InfoGathererAgent._validate_questions`.
     *
     * @param array<int,mixed> $questions
     * @throws \InvalidArgumentException on malformed questions.
     */
    private function validateQuestions(array $questions): void
    {
        if ($questions === []) {
            throw new \InvalidArgumentException('At least one question is required');
        }
        foreach ($questions as $i => $question) {
            $n = $i + 1;
            if (!is_array($question)) {
                throw new \InvalidArgumentException("Question {$n} must be an array");
            }
            if (!array_key_exists('key_name', $question)) {
                throw new \InvalidArgumentException("Question {$n} is missing 'key_name' field");
            }
            if (!array_key_exists('question_text', $question)) {
                throw new \InvalidArgumentException("Question {$n} is missing 'question_text' field");
            }
        }
    }

    /**
     * Handle dynamic configuration using the callback function.
     *
     * Mirrors Python `InfoGathererAgent.on_swml_request`: when running in
     * dynamic mode (no static questions), invoke the registered callback to
     * produce the question set and seed global_data. Returns null in static
     * mode (no modifications).
     *
     * @param array<string, mixed>|null $requestData
     * @param string|null               $callbackPath
     * @return array<string, mixed>|null
     */
    public function onSwmlRequest(?array $requestData = null, ?string $callbackPath = null): ?array
    {
        // Only process in dynamic mode (no static questions).
        if ($this->staticQuestions !== null) {
            return null;
        }

        // No callback set => provide a basic fallback question set.
        if ($this->questionCallback === null) {
            $fallback = [
                ['key_name' => 'name', 'question_text' => 'What is your name?'],
                ['key_name' => 'message', 'question_text' => 'How can I help you today?'],
            ];
            return [
                'global_data' => [
                    'questions'      => $fallback,
                    'question_index' => 0,
                    'answers'        => [],
                ],
            ];
        }

        $queryParams = [];
        $bodyParams  = $requestData ?? [];
        $headers     = [];

        try {
            $questions = ($this->questionCallback)($queryParams, $bodyParams, $headers);
            $this->validateQuestions($questions);
            return [
                'global_data' => [
                    'questions'      => $questions,
                    'question_index' => 0,
                    'answers'        => [],
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Error in question callback: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate the instruction text for asking a question.
     *
     * Mirrors Python `InfoGathererAgent._generate_question_instruction`.
     */
    private function generateQuestionInstruction(
        string $questionText,
        bool $needsConfirmation,
        bool $isFirstQuestion = false
    ): string {
        if ($isFirstQuestion) {
            $instruction = "Ask the user to answer the following question: {$questionText}\n\n";
        } else {
            $instruction = "Previous Answer recorded. Now ask the user to answer the following question: {$questionText}\n\n";
        }

        $instruction .= 'Make sure the answer fits the scope and context of the question before submitting it. ';

        if ($needsConfirmation) {
            $instruction .= 'Insist that the user confirms the answer as many times as needed until they say it is correct.';
        } else {
            $instruction .= "You don't need the user to confirm the answer to this question.";
        }

        return $instruction;
    }

    /**
     * Start the question sequence by retrieving the first question.
     *
     * Mirrors Python `InfoGathererAgent.start_questions`.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $rawData
     */
    public function startQuestions(array $args, array $rawData): FunctionResult
    {
        $globalData = is_array($rawData['global_data'] ?? null) ? $rawData['global_data'] : [];
        $questions = is_array($globalData['questions'] ?? null) ? array_values($globalData['questions']) : [];
        $questionIndex = is_int($globalData['question_index'] ?? null) ? $globalData['question_index'] : 0;

        if ($questions === [] || $questionIndex >= count($questions)) {
            return new FunctionResult("I don't have any questions to ask.");
        }

        $currentQuestion = is_array($questions[$questionIndex]) ? $questions[$questionIndex] : [];
        $questionText = is_string($currentQuestion['question_text'] ?? null) ? $currentQuestion['question_text'] : '';
        $needsConfirmation = (bool)($currentQuestion['confirm'] ?? false);

        $instruction = $this->generateQuestionInstruction(
            questionText: $questionText,
            needsConfirmation: $needsConfirmation,
            isFirstQuestion: true,
        );

        $result = new FunctionResult($instruction);
        $result->replaceInHistory('Welcome! Let me ask you a few questions.');
        return $result;
    }

    /**
     * Submit an answer to the current question and move to the next one.
     *
     * Mirrors Python `InfoGathererAgent.submit_answer`: stores the answer in
     * global_data, increments the index, and returns the next question or a
     * completion message.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $rawData
     */
    public function submitAnswer(array $args, array $rawData): FunctionResult
    {
        $answer = is_string($args['answer'] ?? null) ? $args['answer'] : '';

        $globalData = is_array($rawData['global_data'] ?? null) ? $rawData['global_data'] : [];
        $questions = is_array($globalData['questions'] ?? null) ? array_values($globalData['questions']) : [];
        $questionIndex = is_int($globalData['question_index'] ?? null) ? $globalData['question_index'] : 0;
        $answers = is_array($globalData['answers'] ?? null) ? array_values($globalData['answers']) : [];

        if ($questionIndex >= count($questions)) {
            return new FunctionResult('All questions have already been answered.');
        }

        $currentQuestion = is_array($questions[$questionIndex]) ? $questions[$questionIndex] : [];
        $keyName = is_string($currentQuestion['key_name'] ?? null) ? $currentQuestion['key_name'] : '';

        $newAnswers = [...$answers, ['key_name' => $keyName, 'answer' => $answer]];
        $newQuestionIndex = $questionIndex + 1;

        if ($newQuestionIndex < count($questions)) {
            $nextQuestion = is_array($questions[$newQuestionIndex]) ? $questions[$newQuestionIndex] : [];
            $nextText = is_string($nextQuestion['question_text'] ?? null) ? $nextQuestion['question_text'] : '';
            $needsConfirmation = (bool)($nextQuestion['confirm'] ?? false);

            $instruction = $this->generateQuestionInstruction(
                questionText: $nextText,
                needsConfirmation: $needsConfirmation,
                isFirstQuestion: false,
            );

            $result = new FunctionResult($instruction);
            $result->replaceInHistory();
            $result->updateGlobalData([
                'answers'        => $newAnswers,
                'question_index' => $newQuestionIndex,
            ]);
            return $result;
        }

        // No more questions — completion message + global data update.
        $result = new FunctionResult(
            "Thank you! All questions have been answered. You can now summarize the information collected or ask if there's anything else the user would like to discuss."
        );
        $result->replaceInHistory();
        $result->updateGlobalData([
            'answers'        => $newAnswers,
            'question_index' => $newQuestionIndex,
        ]);
        return $result;
    }

    /**
     * @return list<array{key_name: string, question_text: string, confirm?: bool}>
     */
    public function getQuestions(): array
    {
        return $this->questions;
    }
}
