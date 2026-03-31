<?php
/**
 * FAQ Bot Agent
 *
 * A specialised agent for answering frequently asked questions from
 * a predefined knowledge base. Demonstrates structured knowledge
 * in the prompt and post-prompt summary.
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$companyName = 'SignalWire';

$faqs = [
    'What is SignalWire?'           => 'SignalWire is a communications platform that provides APIs for voice, video, and messaging.',
    'How do I create an AI Agent?'  => 'You can create an AI Agent using the SignalWire AI Agent SDK, which provides a simple way to build and deploy conversational AI agents.',
    'What is SWML?'                 => 'SWML (SignalWire Markup Language) is a markup language for defining communications workflows, including AI interactions.',
];

$agent = new AgentBase(
    name:  'signalwire_faq',
    route: '/faq',
);

$agent->promptAddSection('Personality', "You are a helpful FAQ assistant for {$companyName}.");
$agent->promptAddSection('Goal', 'Answer customer questions using only the provided FAQ knowledge base.');
$agent->promptAddSection('Instructions', '', bullets: [
    'Only answer questions if the information is in the FAQ knowledge base.',
    "If you don't know the answer, politely say so and offer to help with something else.",
    'Be concise and direct in your responses.',
]);

// Build knowledge base section
$kb = "Frequently Asked Questions:\n\n";
foreach ($faqs as $question => $answer) {
    $kb .= "Q: {$question}\nA: {$answer}\n\n";
}
$agent->promptAddSection('Knowledge Base', $kb);

$agent->setPostPrompt(<<<'POST'
Provide a JSON summary of the interaction:
{
    "question_type": "CATEGORY_OF_QUESTION",
    "answered_from_kb": true/false,
    "follow_up_needed": true/false
}
POST);

$agent->onSummary(function ($summary, $raw) {
    if ($summary) {
        echo "FAQ Bot summary: " . json_encode($summary) . "\n";
    }
});

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

echo "Starting FAQ Bot Agent\n";
echo "Available at: http://localhost:3000/faq\n";

$agent->run();
