<?php
/**
 * Multi-Agent Server Example
 *
 * Demonstrates running multiple agents on the same server, each with
 * different paths and configurations.
 *
 * Available Agents:
 *   /healthcare - Healthcare-focused agent with HIPAA compliance
 *   /finance    - Finance-focused agent with regulatory compliance
 *   /retail     - Retail/customer service agent with sales focus
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\Server\AgentServer;

// --- Healthcare Agent ---

$healthcare = new AgentBase(
    name:       'Healthcare AI Assistant',
    route:      '/healthcare',
    autoAnswer: true,
    recordCall: true,
);

$healthcare->promptAddSection(
    'Healthcare Role',
    'You are a HIPAA-compliant healthcare AI assistant. You help patients and '
    . 'healthcare providers with information, scheduling, and basic guidance.',
);
$healthcare->promptAddSection(
    'Compliance Guidelines',
    'Always maintain patient privacy and confidentiality:',
    bullets: [
        'Never share patient information with unauthorized parties',
        'Direct medical diagnoses to qualified healthcare providers',
        'Use appropriate medical terminology',
        'Maintain professional, caring communication',
    ],
);

$healthcare->setDynamicConfigCallback(function ($qp, $bp, $headers, $a) {
    $urgency = strtolower($qp['urgency'] ?? 'normal');

    if ($urgency === 'high') {
        $a->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Sarah');
        $a->setParams(['ai_model' => 'gpt-4.1-nano', 'end_of_speech_timeout' => 300]);
    } else {
        $a->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
        $a->setParams(['ai_model' => 'gpt-4.1-nano', 'end_of_speech_timeout' => 500]);
    }

    $a->setGlobalData([
        'customer_id'      => $qp['customer_id'] ?? '',
        'urgency_level'    => $urgency,
        'department'       => $qp['department'] ?? 'general',
        'compliance_level' => 'hipaa',
        'session_type'     => 'healthcare',
    ]);
});

// --- Finance Agent ---

$finance = new AgentBase(
    name:       'Financial Services AI',
    route:      '/finance',
    autoAnswer: true,
    recordCall: true,
);

$finance->promptAddSection(
    'Financial Services Role',
    'You are a financial services AI assistant specializing in banking, '
    . 'investments, and financial planning guidance.',
);
$finance->promptAddSection(
    'Regulatory Compliance',
    'Adhere to financial industry regulations:',
    bullets: [
        'Protect sensitive financial information',
        'Never provide specific investment advice without disclaimers',
        'Refer complex matters to licensed financial advisors',
        'Maintain accurate, professional communication',
    ],
);

$finance->setDynamicConfigCallback(function ($qp, $bp, $headers, $a) {
    $accountType = strtolower($qp['account_type'] ?? 'standard');

    if ($accountType === 'premium') {
        $a->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Sarah');
        $a->setParams(['ai_model' => 'gpt-4.1-nano', 'end_of_speech_timeout' => 600]);
    } else {
        $a->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
        $a->setParams(['ai_model' => 'gpt-4.1-nano', 'end_of_speech_timeout' => 400]);
    }

    $a->setGlobalData([
        'customer_id'      => $qp['customer_id'] ?? '',
        'account_type'     => $accountType,
        'service_area'     => $qp['service'] ?? 'general',
        'compliance_level' => 'financial',
        'session_type'     => 'finance',
    ]);
});

// --- Retail Agent ---

$retail = new AgentBase(
    name:       'Retail Customer Service AI',
    route:      '/retail',
    autoAnswer: true,
    recordCall: true,
);

$retail->promptAddSection(
    'Customer Service Role',
    'You are a friendly retail customer service AI assistant focused on '
    . 'providing excellent customer experiences and sales support.',
);
$retail->promptAddSection(
    'Service Excellence',
    'Customer service principles:',
    bullets: [
        'Maintain friendly, helpful demeanor',
        'Listen actively to customer needs',
        'Provide accurate product information',
        'Look for opportunities to enhance the shopping experience',
    ],
);

$retail->setDynamicConfigCallback(function ($qp, $bp, $headers, $a) {
    $tier = strtolower($qp['customer_tier'] ?? 'standard');

    if ($tier === 'vip') {
        $a->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Sarah');
        $a->setParams(['ai_model' => 'gpt-4.1-nano', 'end_of_speech_timeout' => 600]);
    } else {
        $a->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
        $a->setParams(['ai_model' => 'gpt-4.1-nano', 'end_of_speech_timeout' => 400]);
    }

    $a->setGlobalData([
        'customer_id'   => $qp['customer_id'] ?? '',
        'department'    => $qp['department'] ?? 'general',
        'customer_tier' => $tier,
        'session_type'  => 'retail',
    ]);
});

// --- Server Setup ---

$server = new AgentServer(host: '0.0.0.0', port: 3000);

$server->register($healthcare);
$server->register($finance);
$server->register($retail);

echo "Starting Multi-Agent AI Server\n\n";
echo "Available agents:\n";
echo "- http://localhost:3000/healthcare - Healthcare AI (HIPAA compliant)\n";
echo "- http://localhost:3000/finance    - Financial Services AI\n";
echo "- http://localhost:3000/retail     - Retail Customer Service AI\n";
echo "\nExample requests:\n";
echo "curl 'http://localhost:3000/healthcare?customer_id=patient123&urgency=high'\n";
echo "curl 'http://localhost:3000/finance?account_type=premium&service=investment'\n";
echo "curl 'http://localhost:3000/retail?department=electronics&customer_tier=vip'\n\n";

$server->run();
