<?php
/**
 * Survey Agent Example
 *
 * Uses the SurveyAgent prefab for collecting structured survey
 * responses with different question types: rating, multiple choice,
 * yes/no, and open-ended.
 */

require 'vendor/autoload.php';

use SignalWire\Prefabs\SurveyAgent;
use SignalWire\SWAIG\FunctionResult;

$questions = [
    [
        'id'       => 'product_satisfaction',
        'text'     => 'On a scale of 1-5, how satisfied are you with our product?',
        'type'     => 'rating',
        'scale'    => 5,
        'required' => true,
    ],
    [
        'id'       => 'usage_frequency',
        'text'     => 'How often do you use our product?',
        'type'     => 'multiple_choice',
        'options'  => ['Daily', 'Weekly', 'Monthly', 'Rarely'],
        'required' => true,
    ],
    [
        'id'       => 'primary_feature',
        'text'     => 'Which feature do you use the most?',
        'type'     => 'multiple_choice',
        'options'  => ['Communication Tools', 'File Sharing', 'Task Management', 'Analytics'],
        'required' => true,
    ],
    [
        'id'       => 'recommend',
        'text'     => 'Would you recommend our product to others?',
        'type'     => 'yes_no',
        'required' => true,
    ],
    [
        'id'       => 'improvement_suggestions',
        'text'     => 'What improvements would you suggest for our product?',
        'type'     => 'open_ended',
        'required' => false,
    ],
];

$agent = new SurveyAgent(
    name:         'product_survey',
    questions:    $questions,
    route:        '/product_survey',
    surveyName:   'Product Feedback Survey',
    introduction: 'Thank you for participating in our product survey! '
        . 'Your feedback helps us improve our products and services. '
        . 'This survey should take about 2-3 minutes to complete.',
    conclusion:   'Thank you for completing our survey! Your feedback is extremely valuable.',
    brandName:    'SampleTech',
    maxRetries:   2,
);

$agent->setParams([
    'ai_model'              => 'gpt-4.1-nano',
    'end_of_speech_timeout' => 2000,
    'ai_volume'             => 6,
    'wait_for_user'         => true,
]);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');

// Custom feedback analysis tool
$agent->defineTool(
    name:        'analyze_feedback',
    description: 'Analyse the customer feedback for sentiment and key themes',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'feedback' => ['type' => 'string', 'description' => "The customer's feedback text"],
        ],
    ],
    handler: function (array $args, array $raw): FunctionResult {
        $feedback = strtolower($args['feedback'] ?? '');

        $positive = array_sum(array_map(
            fn($w) => str_contains($feedback, $w) ? 1 : 0,
            ['great', 'good', 'excellent', 'love', 'like', 'helpful']
        ));
        $negative = array_sum(array_map(
            fn($w) => str_contains($feedback, $w) ? 1 : 0,
            ['bad', 'poor', 'terrible', 'hate', 'dislike', 'difficult']
        ));

        $sentiment = $positive > $negative ? 'positive' : ($negative > $positive ? 'negative' : 'neutral');

        return new FunctionResult("Feedback sentiment: {$sentiment}.");
    },
);

$agent->onSummary(function ($summary, $raw) {
    if ($summary) {
        echo "Survey completed: " . json_encode($summary, JSON_PRETTY_PRINT) . "\n";
    }
});

$user = $agent->basicAuthUser();
$pass = $agent->basicAuthPassword();

echo "Starting Product Survey Agent\n";
echo "URL: http://localhost:3000/product_survey\n";
echo "Basic Auth: {$user}:{$pass}\n";

$agent->run();
