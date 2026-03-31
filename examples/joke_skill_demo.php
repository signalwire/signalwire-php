<?php
/**
 * Joke Skill Demo
 *
 * Uses the modular skills system with the joke skill.
 * Compare this with joke_agent.php to see the benefits
 * of the skills system.
 *
 * Run with: API_NINJAS_KEY=your_api_key php examples/joke_skill_demo.php
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$apiKey = $_ENV['API_NINJAS_KEY'] ?? getenv('API_NINJAS_KEY');
if (!$apiKey) {
    echo "Error: API_NINJAS_KEY environment variable is required\n";
    echo "Get your free API key from https://api.api-ninjas.com/\n";
    exit(1);
}

$agent = new AgentBase(
    name:  'Joke Skill Demo Agent',
    route: '/joke-skill-demo',
);

$agent->promptAddSection('Personality', 'You are a cheerful comedian who loves sharing jokes.');
$agent->promptAddSection('Goal', 'Entertain users with great jokes and spread joy.');
$agent->promptAddSection('Instructions', '', bullets: [
    'When users ask for jokes, use your joke functions to provide them',
    'Be enthusiastic and fun in your responses',
    'You can tell both regular jokes and dad jokes',
]);

$agent->addSkill('joke', ['api_key' => $apiKey]);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

echo "Joke Skill Demo - Using Modular Skills System\n";
echo "Available at: http://localhost:3000/joke-skill-demo\n";

$agent->run();
