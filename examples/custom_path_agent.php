<?php
/**
 * Custom Path Agent Example
 *
 * Demonstrates creating an agent with a custom route/path.
 * Useful for running multiple agents on the same server,
 * creating semantic URLs, or hosting behind path-based reverse proxies.
 *
 * Usage:
 *   curl "http://localhost:3000/chat"
 *   curl "http://localhost:3000/chat?user_name=Alice&topic=AI"
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(
    name:       'Chat Assistant',
    route:      '/chat',
    autoAnswer: true,
    recordCall: true,
);

$agent->promptAddSection(
    'Role',
    'You are a friendly chat assistant ready to help with any questions or conversations.',
);

$agent->setDynamicConfigCallback(function ($qp, $bp, $headers, $a) {
    $userName = $qp['user_name'] ?? 'friend';
    $topic    = $qp['topic'] ?? 'general conversation';
    $mood     = strtolower($qp['mood'] ?? 'friendly');

    $a->promptAddSection(
        'Personalization',
        "The user's name is {$userName}. They're interested in discussing {$topic}.",
    );

    $a->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');

    $style = match ($mood) {
        'professional' => 'Maintain a professional, business-appropriate tone.',
        'casual'       => 'Use a casual, relaxed conversational style.',
        default        => 'Be warm, friendly, and approachable in your responses.',
    };
    $a->promptAddSection('Communication Style', $style);

    $a->setGlobalData([
        'user_name'    => $userName,
        'topic'        => $topic,
        'mood'         => $mood,
        'session_type' => 'chat',
    ]);

    $a->addHints('chat', 'assistant', 'help', 'conversation', 'question');
});

echo "Starting Chat Agent\n";
echo "Available at: http://localhost:3000/chat\n";

$agent->run();
