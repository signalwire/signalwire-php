<?php
/**
 * Room and SIP Example
 *
 * Demonstrates using join_room, join_conference, and sip_refer
 * FunctionResult helpers for multi-party communication and SIP transfers.
 */

require 'vendor/autoload.php';

use SignalWire\SWAIG\FunctionResult;

// 1. Basic Room Join
echo "=== Basic Room Join ===\n";
$room = (new FunctionResult('Joining the support team room'))
    ->joinRoom('support_team_room')
    ->say('Welcome to the support team collaboration room');
echo json_encode($room->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 2. Conference Room with Metadata
echo "=== Conference Room ===\n";
$conf = (new FunctionResult('Setting up daily standup meeting'))
    ->joinRoom('daily_standup_room')
    ->updateGlobalData(['meeting_active' => true, 'room_name' => 'daily_standup_room'])
    ->say('You have joined the daily standup meeting.');
echo json_encode($conf->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 3. Basic SIP REFER
echo "=== Basic SIP REFER ===\n";
$sipRefer = (new FunctionResult('Transferring your call to support'))
    ->say('Please hold while I transfer you to our support specialist')
    ->sipRefer('sip:support@company.com');
echo json_encode($sipRefer->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 4. Advanced SIP REFER
echo "=== Advanced SIP REFER ===\n";
$advSip = (new FunctionResult('Transferring to technical support'))
    ->say("I'm connecting you to our senior technical specialist")
    ->sipRefer('sip:tech-specialist@pbx.company.com:5060')
    ->updateGlobalData([
        'transfer_completed'   => true,
        'transfer_destination' => 'tech-specialist@pbx.company.com',
    ]);
echo json_encode($advSip->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 5. Customer Service Workflow
echo "=== Customer Service Workflow ===\n";
$joinService = (new FunctionResult('Connecting to customer service'))
    ->joinRoom('customer_service_room')
    ->say('You have been connected to our customer service team');
echo "Join service room:\n" . json_encode($joinService->toArray(), JSON_PRETTY_PRINT) . "\n\n";

$escalate = (new FunctionResult('Escalating to manager'))
    ->say('Let me connect you with a manager who can better assist you')
    ->sipRefer('sip:manager@customer-service.company.com')
    ->updateGlobalData(['escalated' => true, 'escalation_reason' => 'customer_request']);
echo "Escalate to manager:\n" . json_encode($escalate->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 6. Join Conference
echo "=== Join Conference ===\n";
$joinConf = (new FunctionResult('Joining team conference'))
    ->joinConference('daily_standup')
    ->say('Welcome to the daily standup conference');
echo json_encode($joinConf->toArray(), JSON_PRETTY_PRINT) . "\n";

echo "\nCOMPLETE: All room and SIP examples completed\n";
