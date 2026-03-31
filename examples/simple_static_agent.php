<?php
/**
 * Simple Static Agent (Traditional Way)
 *
 * All configuration is done during initialisation and remains
 * the same for every request. Compare with simple_dynamic_agent.php
 * and simple_dynamic_enhanced.php for dynamic alternatives.
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(
    name:       'Simple Customer Service Agent',
    autoAnswer: true,
    recordCall: true,
);

// STATIC CONFIGURATION - Set once during initialisation

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');

$agent->setParams([
    'ai_model'              => 'gpt-4.1-nano',
    'end_of_speech_timeout' => 500,
    'attention_timeout'     => 15000,
    'background_file_volume' => -20,
]);

$agent->addHints('SignalWire', 'SWML', 'API', 'webhook', 'SIP');

$agent->setGlobalData([
    'agent_type'       => 'customer_service',
    'service_level'    => 'standard',
    'features_enabled' => ['basic_conversation', 'help_desk'],
    'session_info'     => ['environment' => 'production', 'version' => '1.0'],
]);

$agent->promptAddSection(
    'Role and Purpose',
    'You are a professional customer service representative. Your goal is to help '
    . 'customers with their questions and provide excellent service.',
);

$agent->promptAddSection('Guidelines', 'Follow these customer service principles:', bullets: [
    'Listen carefully to customer needs',
    'Provide accurate and helpful information',
    'Maintain a professional and friendly tone',
    'Escalate complex issues when appropriate',
    'Always confirm understanding before ending',
]);

$agent->promptAddSection('Available Services', 'You can help customers with:', bullets: [
    'General product information',
    'Account questions and support',
    'Technical troubleshooting guidance',
    'Billing and payment inquiries',
    'Service status and updates',
]);

echo "Starting Simple Static Agent\n";
echo "Configuration: STATIC (set once at startup)\n";
echo "Available at: http://localhost:3000/\n";

$agent->run();
