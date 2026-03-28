<?php
/**
 * Survey Prefab Example
 *
 * Demonstrates using the Survey prefab agent to conduct a structured
 * survey with multiple question types and validation.
 */

require 'vendor/autoload.php';

use SignalWire\Prefabs\SurveyAgent;

$agent = new SurveyAgent(
    name:            'customer-satisfaction',
    route:           '/survey',
    surveyName:      'Customer Satisfaction Survey',
    brandName:       'Acme Corp',
    introduction:    'Thank you for choosing Acme Corp! We would love your feedback.',
    conclusion:      'Thank you for completing our survey. Your feedback helps us improve!',
    surveyQuestions: [
        [
            'id'       => 'satisfaction',
            'text'     => 'On a scale of 1-5, how satisfied are you with our service?',
            'type'     => 'rating',
            'required' => true,
        ],
        [
            'id'       => 'recommend',
            'text'     => 'Would you recommend us to a friend? Yes or no.',
            'type'     => 'yes_no',
            'required' => true,
        ],
        [
            'id'       => 'feedback',
            'text'     => 'Do you have any additional comments or suggestions?',
            'type'     => 'open_ended',
            'required' => false,
        ],
    ],
);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

$agent->setPostPrompt(<<<'POST'
Return a JSON summary of the survey responses:
{
    "satisfaction_rating": NUMBER,
    "would_recommend": true/false,
    "comments": "TEXT_OR_NONE",
    "survey_completed": true/false
}
POST);

$agent->onSummary(function ($summary, $raw) {
    if ($summary) {
        echo "Survey results:\n";
        if (is_array($summary)) {
            echo json_encode($summary) . "\n";
        } else {
            echo "{$summary}\n";
        }
    }
});

echo "Starting Customer Satisfaction Survey\n";
echo "Available at: http://localhost:3000/survey\n";
echo "Questions: satisfaction rating, recommendation, open feedback\n\n";

$agent->run();
