<?php
/**
 * Comprehensive Dynamic Agent Configuration
 *
 * Demonstrates the power of dynamic agent configuration:
 * - Dynamic voice and language selection
 * - Context-aware prompt configuration
 * - Tier-based feature settings
 * - Industry-specific customisation
 * - A/B testing configuration
 * - Multi-tenant global data
 *
 * Usage examples:
 *   curl "http://localhost:3000/dynamic?tier=premium&industry=healthcare"
 *   curl "http://localhost:3000/dynamic?tier=enterprise&industry=retail&language=es&locale=mx"
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;

$agent = new AgentBase(
    name:       'Comprehensive Dynamic Agent',
    route:      '/dynamic',
    autoAnswer: true,
    recordCall: true,
);

$voiceOptions = [
    'standard'   => ['inworld.Mark', 'inworld.Sarah', 'inworld.Blake'],
    'premium'    => ['inworld.Sarah', 'inworld.Hanna', 'inworld.Mark'],
    'enterprise' => ['inworld.Mark', 'inworld.Sarah', 'inworld.Hanna', 'inworld.Blake'],
];

$industryConfigs = [
    'healthcare' => ['compliance_level' => 'high',     'response_style' => 'professional'],
    'finance'    => ['compliance_level' => 'high',     'response_style' => 'formal'],
    'retail'     => ['compliance_level' => 'medium',   'response_style' => 'friendly'],
    'general'    => ['compliance_level' => 'standard', 'response_style' => 'conversational'],
];

$agent->setDynamicConfigCallback(function ($qp, $bp, $headers, $a)
    use ($voiceOptions, $industryConfigs) {

    $tier      = strtolower($qp['tier'] ?? 'standard');
    $industry  = strtolower($qp['industry'] ?? 'general');
    $reqVoice  = strtolower($qp['voice'] ?? '');
    $language  = strtolower($qp['language'] ?? 'en');
    $locale    = strtolower($qp['locale'] ?? 'us');
    $testGroup = strtoupper($qp['test_group'] ?? 'A');
    $debug     = strtolower($qp['debug'] ?? '') === 'true';
    $custId    = $qp['customer_id'] ?? '';

    // --- Voice & Language ---
    $available = $voiceOptions[$tier] ?? $voiceOptions['standard'];
    $voice = (in_array($reqVoice, $available)) ? $reqVoice : $available[0];

    if ($language === 'es') {
        $a->addLanguage(name: 'Spanish', code: $locale === 'mx' ? 'es-MX' : 'es-ES', voice: 'inworld.Sarah');
    } elseif ($language === 'fr') {
        $a->addLanguage(name: 'French', code: $locale === 'ca' ? 'fr-CA' : 'fr-FR', voice: 'inworld.Hanna');
    } else {
        $code = $locale === 'ca' ? 'en-CA' : 'en-US';
        $a->addLanguage(name: 'English', code: $code, voice: $voice);
    }

    // --- Tier Parameters ---
    $params = match ($tier) {
        'enterprise' => [
            'end_of_speech_timeout' => 800,  'attention_timeout' => 25000,
            'background_file_volume' => -35, 'digit_timeout' => 8000,
        ],
        'premium' => [
            'end_of_speech_timeout' => 600,  'attention_timeout' => 20000,
            'background_file_volume' => -30, 'digit_timeout' => 6000,
        ],
        default => [
            'end_of_speech_timeout' => 400,  'attention_timeout' => 15000,
            'background_file_volume' => -20, 'digit_timeout' => 4000,
        ],
    };
    if ($testGroup === 'B') {
        $params['end_of_speech_timeout'] = (int) ($params['end_of_speech_timeout'] * 1.2);
    }
    $params['ai_model'] = 'gpt-4.1-nano';
    $a->setParams($params);

    // --- Industry Prompts ---
    $config = $industryConfigs[$industry] ?? $industryConfigs['general'];
    $a->promptAddSection(
        'Role and Purpose',
        "You are a professional AI assistant specialised in {$industry} services. "
        . "Maintain {$config['response_style']} communication standards.",
    );

    if ($industry === 'healthcare') {
        $a->promptAddSection('Healthcare Guidelines', 'Follow HIPAA compliance standards.', bullets: [
            'Protect patient privacy at all times',
            'Direct medical questions to qualified healthcare providers',
            'Use appropriate medical terminology when helpful',
        ]);
    } elseif ($industry === 'finance') {
        $a->promptAddSection('Financial Guidelines', 'Adhere to financial industry regulations.', bullets: [
            'Never provide specific investment advice',
            'Protect sensitive financial information',
            'Refer complex matters to qualified advisors',
        ]);
    } elseif ($industry === 'retail') {
        $a->promptAddSection('Customer Service Excellence', 'Focus on customer satisfaction.', bullets: [
            'Maintain friendly, helpful demeanour',
            'Understand product features and benefits',
            'Handle complaints with empathy',
        ]);
    }

    if (in_array($tier, ['premium', 'enterprise'])) {
        $a->promptAddSection('Enhanced Capabilities', "As a {$tier} service, you have advanced features:", bullets: [
            'Extended conversation memory',
            'Priority processing and faster responses',
            'Access to specialised knowledge bases',
        ]);
    }

    // --- Global Data ---
    $features = ['basic_conversation', 'function_calling'];
    if (in_array($tier, ['premium', 'enterprise'])) {
        array_push($features, 'extended_memory', 'priority_processing');
    }
    if ($tier === 'enterprise') {
        array_push($features, 'custom_integration', 'dedicated_support');
    }

    $globalData = [
        'service_tier'     => $tier,
        'industry_focus'   => $industry,
        'test_group'       => $testGroup,
        'features_enabled' => $features,
        'compliance_level' => $config['compliance_level'],
    ];
    if ($custId) {
        $globalData['customer_id']   = $custId;
        $globalData['customer_tier'] = $tier;
    }
    $a->setGlobalData($globalData);

    // --- Debug Mode ---
    if ($debug) {
        $a->promptAddSection('Debug Mode', 'Debug mode is enabled. Provide additional context.', bullets: [
            'Show decision-making process when appropriate',
            'Explain feature availability based on tier',
        ]);
        $a->addHints('debug', 'verbose', 'capabilities');
    }

    // --- A/B Testing ---
    if ($testGroup === 'B') {
        $a->addHints('enhanced', 'personalised', 'proactive');
        $a->promptAddSection('Enhanced Interaction Style', 'You are using an enhanced conversation style:', bullets: [
            'Ask clarifying questions more frequently',
            'Offer proactive suggestions when appropriate',
        ]);
    }
});

echo "Starting Comprehensive Dynamic Agent\n";
echo "Available at: http://localhost:3000/dynamic\n";
echo "\nSupported parameters:\n";
echo "  tier=standard|premium|enterprise\n";
echo "  industry=healthcare|finance|retail|general\n";
echo "  language=en|es|fr  locale=us|mx|ca|fr\n";
echo "  test_group=A|B  debug=true  customer_id=<string>\n\n";

$agent->run();
