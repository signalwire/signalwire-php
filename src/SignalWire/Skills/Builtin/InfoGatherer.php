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

    /**
     * Unique instance key for this skill instance.
     *
     * Mirrors Python `InfoGathererSkill.get_instance_key` (skill.py:83): the
     * optional `prefix` param differentiates instances.
     */
    public function getInstanceKey(): string
    {
        $prefix = $this->paramString('prefix');
        if ($prefix !== '') {
            return 'info_gatherer_' . $prefix;
        }
        return 'info_gatherer';
    }

    /**
     * Parameter schema for the info_gatherer skill.
     *
     * Mirrors Python `InfoGathererSkill.get_parameter_schema` (skill.py:34):
     * merges the base schema with questions + prefix + completion_message.
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        $schema = parent::getParameterSchema();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $schema['properties'] = array_merge($properties, [
            'questions' => [
                'type' => 'array',
                'description' => "List of question objects. Each must have 'key_name' (str) and "
                    . "'question_text' (str). Optional 'confirm' (bool) asks the agent "
                    . 'to confirm the answer before proceeding.',
                'required' => true,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'key_name' => ['type' => 'string'],
                        'question_text' => ['type' => 'string'],
                        'confirm' => ['type' => 'boolean'],
                        'prompt_add' => ['type' => 'string'],
                    ],
                ],
            ],
            'prefix' => [
                'type' => 'string',
                'description' => 'Optional prefix for tool names and namespace. When set, tools '
                    . 'are named <prefix>_start_questions / <prefix>_submit_answer and '
                    . "state is stored under 'skill:<prefix>' in global_data.",
                'required' => false,
            ],
            'completion_message' => [
                'type' => 'string',
                'description' => 'Message returned after all questions are answered',
                'default' => 'Thank you! All questions have been answered. You can now '
                    . 'summarize the information collected or ask if there\'s anything '
                    . 'else the user would like to discuss.',
                'required' => false,
            ],
        ]);
        return $schema;
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

        $startToolName = $prefix !== '' ? $prefix . '_start_questions' : 'start_questions';
        $submitToolName = $prefix !== '' ? $prefix . '_submit_answer' : 'submit_answer';

        $this->defineTool(
            $startToolName,
            'Start the question gathering process and get the first question',
            [],
            function (array $args, array $rawData) use ($questions): FunctionResult {
                $result = new FunctionResult();

                if (count($questions) === 0) {
                    $result->setResponse('No questions configured.');
                    return $result;
                }

                $firstQuestion = self::asString($questions[0]['question_text'] ?? null, 'No question text.');

                $result->setResponse('Starting questions. First question: ' . $firstQuestion);
                // Reset the state machine: index 0, no answers recorded yet.
                $this->updateSkillData($result, [
                    'questions' => $questions,
                    'question_index' => 0,
                    'answers' => [],
                ]);

                return $result;
            }
        );

        $this->defineTool(
            $submitToolName,
            'Submit an answer to the current question and move to the next one',
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
            function (array $args, array $rawData) use ($questions, $completionMessage, $startToolName, $submitToolName): FunctionResult {
                $result = new FunctionResult();
                $answer = self::asString($args['answer'] ?? null);
                $confirmed = (bool) ($args['confirmed_by_user'] ?? false);

                // Read the live state machine out of global_data (namespaced),
                // mirroring Python `_handle_submit_answer` (skill.py:214):
                //   state = self.get_skill_data(raw_data)
                //   questions / question_index / answers come from that state.
                // Fall back to the configured questions + index 0 when the
                // caller hasn't seeded state (start_questions not yet run).
                $state = $this->getSkillData($rawData);
                $stateQuestions = isset($state['questions']) && is_array($state['questions'])
                    ? $this->normalizeQuestions($state['questions'])
                    : $questions;
                $indexRaw = $state['question_index'] ?? 0;
                $questionIndex = is_int($indexRaw) ? $indexRaw
                    : (is_numeric($indexRaw) ? (int) $indexRaw : 0);
                $answers = isset($state['answers']) && is_array($state['answers'])
                    ? array_values($state['answers'])
                    : [];

                $totalQuestions = count($stateQuestions);

                if ($answer === '') {
                    $result->setResponse('Please provide an answer.');
                    return $result;
                }

                if ($questionIndex >= $totalQuestions) {
                    $result->setResponse('All questions have already been answered.');
                    return $result;
                }

                $currentQuestion = $stateQuestions[$questionIndex] ?? [];
                $needsConfirm = (bool) ($currentQuestion['confirm'] ?? false);

                // Enforce confirmation without advancing the index or recording
                // the answer — matches Python's early return on unconfirmed.
                if ($needsConfirm && !$confirmed) {
                    $questionText = self::asString($currentQuestion['question_text'] ?? null);
                    $result->setResponse(
                        'You answered "' . $answer . '" for: ' . $questionText
                        . '. Can you confirm this is correct?'
                    );
                    return $result;
                }

                // Append the new answer to the accumulated list and advance.
                $answers[] = [
                    'key_name' => self::asString($currentQuestion['key_name'] ?? null, 'q_' . $questionIndex),
                    'answer' => $answer,
                ];
                $nextIndex = $questionIndex + 1;

                if ($nextIndex >= $totalQuestions) {
                    $result->setResponse($completionMessage);
                    $result->toggleFunctions([
                        $startToolName => false,
                        $submitToolName => false,
                    ]);
                    $this->updateSkillData($result, [
                        'questions' => $stateQuestions,
                        'question_index' => $nextIndex,
                        'answers' => $answers,
                        'completed' => true,
                    ]);
                } else {
                    $nextQuestion = self::asString(
                        $stateQuestions[$nextIndex]['question_text'] ?? null,
                        'No question text.'
                    );
                    $result->setResponse('Answer recorded. Next question: ' . $nextQuestion);
                    $this->updateSkillData($result, [
                        'questions' => $stateQuestions,
                        'question_index' => $nextIndex,
                        'answers' => $answers,
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
        $questions = $this->normalizeQuestions($this->paramArray('questions'));

        // Seed the initial state under the SAME namespaced key that
        // getSkillData()/updateSkillData() read and write ("skill:<prefix>"
        // or "skill:<instanceKey>"), mirroring Python get_global_data
        // (skill.py:129) which uses _get_skill_namespace(). Using the bare
        // instance key here would strand the seed where submit_answer never
        // looks.
        $result = new FunctionResult();
        $this->updateSkillData($result, [
            'questions' => $questions,
            'question_index' => 0,
            'answers' => [],
        ]);

        $actions = $result->toArray()['action'] ?? [];
        if (is_array($actions)) {
            foreach ($actions as $action) {
                if (is_array($action) && isset($action['set_global_data']) && is_array($action['set_global_data'])) {
                    $out = [];
                    foreach ($action['set_global_data'] as $k => $v) {
                        if (is_string($k)) {
                            $out[$k] = $v;
                        }
                    }
                    return $out;
                }
            }
        }

        return [];
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
