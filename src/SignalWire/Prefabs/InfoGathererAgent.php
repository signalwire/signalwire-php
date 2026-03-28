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
     * @param string $name Agent name
     * @param list<array{key_name: string, question_text: string, confirm?: bool}> $questions
     * @param array $options Additional AgentBase options
     */
    public function __construct(string $name, array $questions, array $options = [])
    {
        $options['name']  = $name !== '' ? $name : 'info_gatherer';
        $options['route'] = $options['route'] ?? '/info_gatherer';

        parent::__construct($options);

        $this->questions = $questions;
        $this->usePom    = true;

        // Global data tracks question index and answers
        $this->setGlobalData([
            'questions'      => $this->questions,
            'question_index' => 0,
            'answers'        => [],
        ]);

        // Prompt section
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

        // Tool: start_questions
        $capturedQuestions = $this->questions;
        $this->defineTool(
            name: 'start_questions',
            description: 'Start the question-gathering process and return the first question',
            parameters: [],
            handler: function (array $args, array $rawData) use ($capturedQuestions): FunctionResult {
                $first = $capturedQuestions[0]['question_text'] ?? 'No questions configured';
                return new FunctionResult($first);
            },
        );

        // Tool: submit_answer
        $this->defineTool(
            name: 'submit_answer',
            description: 'Submit an answer to the current question',
            parameters: [
                'answer'            => ['type' => 'string',  'description' => 'The answer'],
                'confirmed_by_user' => ['type' => 'boolean', 'description' => 'User confirmed this answer'],
            ],
            handler: function (array $args, array $rawData): FunctionResult {
                $answer = $args['answer'] ?? '';
                return new FunctionResult("Answer recorded: {$answer}");
            },
        );
    }

    /**
     * @return list<array{key_name: string, question_text: string, confirm?: bool}>
     */
    public function getQuestions(): array
    {
        return $this->questions;
    }
}
