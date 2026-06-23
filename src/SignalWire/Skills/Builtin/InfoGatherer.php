<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

class InfoGatherer extends SkillBase
{
    public function getName(): string
    {
        return 'info_gatherer';
    }

    /**
     * Narrow the genuinely-mixed questions param to a list of question
     * objects. Per the Python reference each question is a dict with
     * 'key_name'/'question_text' (and optional 'confirm'/'prompt_add');
     * non-array entries the platform might send are dropped.
     *
     * @param array<mixed> $questions
     * @return list<array<string,mixed>>
     */
    private function normalizeQuestions(array $questions): array
    {
        $out = [];
        foreach ($questions as $q) {
            if (is_array($q)) {
                $out[] = $q;
            }
        }
        return $out;
    }

    /**
     * Narrow a genuinely-mixed value (question field / SWAIG arg) to a
     * string. Numeric scalars are stringified; anything else → $default.
     */
    private static function asString(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return $default;
    }

    public function getDescription(): string
    {
        return 'Gather answers to a configurable list of questions';
    }

    public function supportsMultipleInstances(): bool
    {
        return true;
    }

    public function setup(): bool
    {
        if (empty($this->params['questions']) || !is_array($this->params['questions'])) {
            return false;
        }

        return true;
    }

    public function registerTools(): void
    {
        $prefix = $this->paramString('prefix');
        $questions = $this->normalizeQuestions($this->paramArray('questions'));
        $completionMessage = $this->paramString('completion_message', 'All questions have been answered. Thank you!');
        $namespace = $this->getInstanceKey();

        $startToolName = $prefix !== '' ? $prefix . '_start_questions' : 'start_questions';
        $submitToolName = $prefix !== '' ? $prefix . '_submit_answer' : 'submit_answer';

        $this->defineTool(
            $startToolName,
            'Start the question gathering process and get the first question',
            [],
            function (array $args, array $rawData) use ($questions, $namespace): FunctionResult {
                $result = new FunctionResult();

                if (count($questions) === 0) {
                    $result->setResponse('No questions configured.');
                    return $result;
                }

                $firstQuestion = self::asString($questions[0]['question_text'] ?? null, 'No question text.');

                $result->setResponse('Starting questions. First question: ' . $firstQuestion);
                $result->updateGlobalData([
                    $namespace => [
                        'questions' => $questions,
                        'question_index' => 0,
                        'answers' => [],
                    ],
                ]);

                return $result;
            }
        );

        $this->defineTool(
            $submitToolName,
            'Submit an answer to the current question',
            [
                'answer' => [
                    'type' => 'string',
                    'description' => 'The answer to the current question',
                ],
                'confirmed_by_user' => [
                    'type' => 'boolean',
                    'description' => 'Whether the user has confirmed this answer is correct',
                ],
            ],
            function (array $args, array $rawData) use ($questions, $namespace, $completionMessage): FunctionResult {
                $result = new FunctionResult();
                $answer = self::asString($args['answer'] ?? null);
                $confirmed = (bool) ($args['confirmed_by_user'] ?? false);

                // In a stateful environment, question_index and answers would come from global data.
                // For this stub, we use the questions array directly.
                $totalQuestions = count($questions);

                // The current question index would normally come from global data
                // For the handler, we track state via global data actions
                $currentIndex = 0; // Will be managed by global data in runtime

                if ($answer === '') {
                    $result->setResponse('Please provide an answer.');
                    return $result;
                }

                $currentQuestion = $questions[$currentIndex] ?? [];
                $needsConfirm = (bool) ($currentQuestion['confirm'] ?? false);

                if ($needsConfirm && !$confirmed) {
                    $questionText = self::asString($currentQuestion['question_text'] ?? null);
                    $result->setResponse(
                        'You answered "' . $answer . '" for: ' . $questionText
                        . '. Can you confirm this is correct?'
                    );
                    return $result;
                }

                $nextIndex = $currentIndex + 1;

                if ($nextIndex >= $totalQuestions) {
                    $result->setResponse($completionMessage);
                    $result->updateGlobalData([
                        $namespace => [
                            'questions' => $questions,
                            'question_index' => $nextIndex,
                            'answers' => [
                                [
                                    'key' => self::asString($currentQuestion['key_name'] ?? null, 'q_' . $currentIndex),
                                    'answer' => $answer,
                                ],
                            ],
                            'completed' => true,
                        ],
                    ]);
                } else {
                    $nextQuestion = self::asString($questions[$nextIndex]['question_text'] ?? null, 'No question text.');
                    $result->setResponse('Answer recorded. Next question: ' . $nextQuestion);
                    $result->updateGlobalData([
                        $namespace => [
                            'questions' => $questions,
                            'question_index' => $nextIndex,
                            'answers' => [
                                [
                                    'key' => self::asString($currentQuestion['key_name'] ?? null, 'q_' . $currentIndex),
                                    'answer' => $answer,
                                ],
                            ],
                        ],
                    ]);
                }

                return $result;
            }
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function getGlobalData(): array
    {
        $namespace = $this->getInstanceKey();
        $questions = $this->normalizeQuestions($this->paramArray('questions'));

        return [
            $namespace => [
                'questions' => $questions,
                'question_index' => 0,
                'answers' => [],
            ],
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

        $instanceKey = $this->getInstanceKey();
        $questions = $this->normalizeQuestions($this->paramArray('questions'));
        $bullets = [
            'Call start_questions to begin the question flow.',
            'Submit each answer using submit_answer with the user\'s response.',
            'Questions that require confirmation will ask the user to verify their answer.',
        ];

        foreach ($questions as $q) {
            $promptAdd = self::asString($q['prompt_add'] ?? null);
            if ($promptAdd !== '') {
                $bullets[] = $promptAdd;
            }
        }

        return [
            [
                'title' => 'Info Gatherer (' . $instanceKey . ')',
                'body' => 'You need to gather information from the user by asking a series of questions.',
                'bullets' => $bullets,
            ],
        ];
    }
}
