<?php
/**
 * Session and State Demo
 *
 * Demonstrates session lifecycle management:
 * - on_summary hook for processing conversation summaries
 * - set_global_data for providing context to the AI
 * - update_global_data for modifying state during a call
 * - Tool result actions (hangup, set_global_data, etc.)
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

$agent = new AgentBase(
    name:  'session-state-demo',
    route: '/session-state',
);

// Configure prompt
$agent->promptAddSection(
    'Role',
    'You are a customer service agent that tracks session state.',
    bullets: [
        'Use check_account to look up customer info',
        'Use update_preferences to modify customer preferences',
        'Use end_call to hang up when the customer is done',
    ],
);

// Initial global data for every session
$agent->setGlobalData([
    'company'     => 'Acme Corp',
    'department'  => 'customer_service',
    'call_reason' => 'unknown',
]);

// Post-prompt for summary
$agent->setPostPrompt(<<<'POST'
Summarize the conversation as JSON:
{
    "customer_name": "NAME_OR_UNKNOWN",
    "call_reason": "REASON",
    "resolved": true/false,
    "actions_taken": ["action1", "action2"]
}
POST);

// Summary callback
$agent->onSummary(function ($summary, $raw) {
    if ($summary) {
        echo "CONVERSATION SUMMARY:\n";
        if (is_array($summary)) {
            echo json_encode($summary) . "\n";
        } else {
            echo "{$summary}\n";
        }
    }
});

// --- Tool: check_account ---
$agent->defineTool(
    name:        'check_account',
    description: 'Look up a customer account by name or ID',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'identifier' => ['type' => 'string', 'description' => 'Customer name or account ID'],
        ],
        'required' => ['identifier'],
    ],
    handler: function (array $args, array $raw): FunctionResult {
        $id = $args['identifier'] ?? 'unknown';
        $result = new FunctionResult(
            "Found account for {$id}: Premium tier, active since 2020."
        );
        // Update global data so the AI knows the customer
        $result->updateGlobalData([
            'customer_name' => $id,
            'account_tier'  => 'premium',
            'call_reason'   => 'account_inquiry',
        ]);
        return $result;
    },
);

// --- Tool: update_preferences ---
$agent->defineTool(
    name:        'update_preferences',
    description: 'Update customer communication preferences',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'email_notifications' => ['type' => 'boolean', 'description' => 'Enable email notifications'],
            'sms_notifications'   => ['type' => 'boolean', 'description' => 'Enable SMS notifications'],
        ],
    ],
    handler: function (array $args, array $raw): FunctionResult {
        $prefs = [];
        if (!empty($args['email_notifications'])) $prefs[] = 'email';
        if (!empty($args['sms_notifications']))   $prefs[] = 'SMS';
        $prefStr = !empty($prefs) ? implode(' and ', $prefs) : 'none';
        return new FunctionResult(
            "Preferences updated: {$prefStr} notifications enabled."
        );
    },
);

// --- Tool: end_call ---
$agent->defineTool(
    name:        'end_call',
    description: 'End the call after saying goodbye',
    parameters:  ['type' => 'object', 'properties' => []],
    handler: function (array $args, array $raw): FunctionResult {
        $result = new FunctionResult('Thank you for calling. Goodbye!');
        $result->hangup();
        return $result;
    },
);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

echo "Starting Session State Demo\n";
echo "Available at: http://localhost:3000/session-state\n";

$agent->run();
