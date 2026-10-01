<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Prefabs\InfoGathererAgent;

/**
 * Pins the question-set contract the InfoGatherer prefab example must satisfy.
 *
 * Why: examples/prefab_info_gatherer.php declared its questions as
 *
 *     ['question_text' => 'What is your full name?', 'field' => 'full_name']
 *
 * but InfoGathererAgent's contract is `key_name`, not `field`
 * (InfoGathererAgent.php:43, and validateQuestions() at :156 throws
 * "missing 'key_name' field" for a question that lacks it).
 *
 * Nothing threw for the STATIC question set, because validateQuestions() only
 * runs on the dynamic callback path (:205). Instead the defect surfaced at
 * answer-recording time: :303 reads `$currentQuestion['key_name'] ?? null` and
 * falls back to '' when it is absent, so EVERY answer the agent collected was
 * stored under an empty key — three questions in, global_data held three
 * answers all keyed ''. The gathered data was unusable and nothing reported an
 * error.
 */
class ExamplePrefabQuestionsTest extends TestCase
{
    /**
     * @return list<array<string,mixed>>
     */
    private static function exampleQuestions(): array
    {
        require_once dirname(__DIR__) . '/examples/prefab_info_gatherer.php';
        self::assertTrue(function_exists('buildInfoGathererQuestions'));

        /** @var list<array<string,mixed>> $questions */
        $questions = \buildInfoGathererQuestions();

        return $questions;
    }

    public function testEveryExampleQuestionCarriesKeyNameNotField(): void
    {
        $questions = self::exampleQuestions();
        self::assertNotSame([], $questions);

        foreach ($questions as $i => $question) {
            $n = $i + 1;
            self::assertArrayHasKey(
                'key_name',
                $question,
                "question {$n} must use 'key_name' — the prefab reads that key when "
                . 'recording an answer, and an absent one silently records under ""',
            );
            self::assertIsString($question['key_name']);
            self::assertNotSame('', $question['key_name']);

            self::assertArrayNotHasKey(
                'field',
                $question,
                "question {$n} uses 'field', which the prefab never reads",
            );

            self::assertArrayHasKey('question_text', $question);
        }
    }

    public function testTheExampleQuestionsSatisfyThePrefabsOwnValidator(): void
    {
        // validateQuestions() is private and only runs on the dynamic-callback
        // path, so drive it the way a dynamic agent would: the same question
        // set must survive the validator the SDK applies there.
        $agent = new InfoGathererAgent(name: 'validator-probe', questions: null);
        $agent->setQuestionCallback(
            fn (array $query, array $body, array $headers): array => self::exampleQuestions(),
        );

        $result = $agent->onSwmlRequest([]);

        self::assertIsArray($result);
        $globalData = $result['global_data'] ?? null;
        self::assertIsArray($globalData);
        self::assertSame(self::exampleQuestions(), $globalData['questions']);
    }
}
