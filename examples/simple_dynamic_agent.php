<?php
/**
 * Simple Dynamic Agent Example
 *
 * This agent is configured dynamically per-request using a callback.
 * The configuration happens fresh for each incoming request, allowing
 * parameter-based customization (VIP routing, tenant isolation, etc.).
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(
    name:       'Simple Customer Service Agent (Dynamic)',
    autoAnswer: true,
    recordCall: true,
);

// Set up a dynamic configuration callback instead of static config
$agent->setDynamicConfigCallback(function ($queryParams, $bodyParams, $headers, $agentClone) {
    // Voice and language
    $agentClone->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');

    // AI parameters
    $agentClone->setParams([
        'ai_model'                => 'gpt-4.1-nano',
        'end_of_speech_timeout'   => 500,
        'attention_timeout'       => 15000,
        'background_file_volume'  => -20,
    ]);

    // Hints for speech recognition
    $agentClone->addHints('SignalWire', 'SWML', 'API', 'webhook', 'SIP');

    // Global data
    $agentClone->setGlobalData([
        'agent_type'       => 'customer_service',
        'service_level'    => 'standard',
        'features_enabled' => ['basic_conversation', 'help_desk'],
        'session_info'     => [
            'environment' => 'production',
            'version'     => '1.0',
        ],
    ]);

    // Prompt sections
    $agentClone->promptAddSection(
        'Role and Purpose',
        'You are a professional customer service representative. Your goal is to help '
        . 'customers with their questions and provide excellent service.',
    );

    $agentClone->promptAddSection(
        'Guidelines',
        'Follow these customer service principles:',
        bullets: [
            'Listen carefully to customer needs',
            'Provide accurate and helpful information',
            'Maintain a professional and friendly tone',
            'Escalate complex issues when appropriate',
            'Always confirm understanding before ending',
        ],
    );

    $agentClone->promptAddSection(
        'Available Services',
        'You can help customers with:',
        bullets: [
            'General product information',
            'Account questions and support',
            'Technical troubleshooting guidance',
            'Billing and payment inquiries',
            'Service status and updates',
        ],
    );
});

echo "Starting Simple Dynamic Agent -- configuration changes based on requests\n";
echo "Available at: http://localhost:3000/\n";

$agent->run();
