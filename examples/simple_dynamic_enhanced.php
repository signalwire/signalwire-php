<?php
/**
 * Simple Dynamic Agent - Enhanced Example
 *
 * Shows the real power of dynamic configuration by actually using
 * request parameters to customise the agent behaviour.
 *
 * Parameters:
 *   vip=true/false        - premium voice and faster response
 *   department=sales/support/billing - specialised expertise
 *   customer_id=<string>  - personalised experience
 *   language=en/es        - language and voice selection
 *
 * Usage:
 *   curl "http://localhost:3000"
 *   curl "http://localhost:3000?vip=true&customer_id=CUST123"
 *   curl "http://localhost:3000?department=sales&language=es"
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(
    name:       'Enhanced Dynamic Customer Service Agent',
    autoAnswer: true,
    recordCall: true,
);

$agent->setDynamicConfigCallback(function ($qp, $bp, $headers, $a) {
    $isVip      = strtolower($qp['vip'] ?? '') === 'true';
    $department = strtolower($qp['department'] ?? 'general');
    $customerId = $qp['customer_id'] ?? '';
    $language   = strtolower($qp['language'] ?? 'en');

    // --- Voice ---
    if ($language === 'es') {
        $a->addLanguage(name: 'Spanish', code: 'es-ES', voice: $isVip ? 'inworld.Sarah' : 'inworld.Mark');
    } else {
        $a->addLanguage(name: 'English', code: 'en-US', voice: $isVip ? 'inworld.Sarah' : 'inworld.Mark');
    }

    // --- AI Parameters ---
    $a->setParams($isVip ? [
        'ai_model' => 'gpt-4.1-nano', 'end_of_speech_timeout' => 300,
        'attention_timeout' => 20000, 'background_file_volume' => -30,
    ] : [
        'ai_model' => 'gpt-4.1-nano', 'end_of_speech_timeout' => 500,
        'attention_timeout' => 15000, 'background_file_volume' => -20,
    ]);

    // --- Hints ---
    $hints = ['SignalWire', 'SWML', 'API', 'webhook', 'SIP'];
    if ($department === 'sales') {
        array_push($hints, 'pricing', 'enterprise', 'subscription', 'upgrade');
    } elseif ($department === 'billing') {
        array_push($hints, 'invoice', 'payment', 'billing', 'account');
    } else {
        array_push($hints, 'support', 'technical', 'troubleshoot', 'configuration');
    }
    $a->addHints(...$hints);

    // --- Global Data ---
    $globalData = [
        'agent_type'       => 'customer_service',
        'service_level'    => $isVip ? 'vip' : 'standard',
        'department'       => $department,
        'language'         => $language,
        'features_enabled' => ['basic_conversation', 'help_desk'],
    ];
    if ($customerId) {
        $globalData['customer_id']  = $customerId;
        $globalData['personalized'] = true;
    }
    if ($isVip) {
        $globalData['features_enabled'] = array_merge($globalData['features_enabled'], ['priority_support', 'premium_features']);
    }
    $a->setGlobalData($globalData);

    // --- Dynamic Prompts ---
    $role = $customerId
        ? "You are a professional customer service representative helping customer {$customerId}. "
        : 'You are a professional customer service representative. ';
    $role .= $isVip
        ? 'This is a VIP customer who receives priority service.'
        : 'Help customers with their questions and provide excellent service.';
    $a->promptAddSection('Role and Purpose', $role);

    if ($department === 'sales') {
        $a->promptAddSection('Sales Expertise', 'You specialise in sales:', bullets: [
            'Present product features and benefits clearly',
            'Handle pricing questions and special offers',
            'Process orders and upgrades',
        ]);
    } elseif ($department === 'billing') {
        $a->promptAddSection('Billing Expertise', 'You specialise in billing:', bullets: [
            'Explain billing statements and charges',
            'Process payment arrangements',
            'Handle dispute resolution professionally',
        ]);
    } else {
        $a->promptAddSection('General Support', 'Follow these principles:', bullets: [
            'Listen carefully to customer needs',
            'Provide accurate and helpful information',
            'Escalate complex issues when appropriate',
        ]);
    }

    if ($isVip) {
        $a->promptAddSection('VIP Service Standards', 'This customer receives premium service:', bullets: [
            'Provide immediate attention and priority handling',
            'Offer premium solutions and exclusive options',
            'Ensure complete satisfaction before concluding',
        ]);
    }
});

echo "Starting Enhanced Dynamic Agent\n";
echo "Available at: http://localhost:3000/\n\n";
echo "Parameters: vip, department, customer_id, language\n";
echo "Try: curl 'http://localhost:3000?vip=true&customer_id=CUST123'\n\n";

$agent->run();
