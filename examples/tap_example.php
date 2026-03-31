<?php
/**
 * Tap and Stop Tap Example
 *
 * Demonstrates using tap and stop_tap FunctionResult helpers for
 * background call monitoring via WebSocket and RTP streaming.
 */

require 'vendor/autoload.php';

use SignalWire\SWAIG\FunctionResult;

// 1. Basic WebSocket Tap
echo "=== Basic WebSocket Tap ===\n";
$wsTap = (new FunctionResult('Starting call monitoring'))
    ->tap('wss://monitoring.company.com/audio-stream')
    ->say('Call monitoring is now active');
echo json_encode($wsTap->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 2. Basic RTP Tap
echo "=== Basic RTP Tap ===\n";
$rtpTap = (new FunctionResult('Starting RTP monitoring'))
    ->tap('rtp://192.168.1.100:5004')
    ->updateGlobalData(['rtp_monitoring' => true]);
echo json_encode($rtpTap->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 3. Advanced Compliance Monitoring
echo "=== Advanced Compliance Monitoring ===\n";
$compliance = (new FunctionResult('Setting up compliance monitoring'))
    ->tap(
        uri:       'wss://compliance.company.com/secure-stream',
        controlId: 'compliance_tap_001',
        direction: 'both',
        codec:     'PCMA',
        statusUrl: 'https://api.company.com/compliance-events',
    )
    ->say('This call may be monitored for compliance purposes');
echo json_encode($compliance->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 4. Customer Service Monitoring
echo "=== Customer Service Monitoring ===\n";
$csMonitor = (new FunctionResult('Initialising quality monitoring'))
    ->tap(
        uri:       'wss://quality.company.com/cs-monitoring',
        controlId: 'cs_quality_monitor',
        direction: 'speak',
        statusUrl: 'https://api.company.com/quality-events',
    )
    ->updateGlobalData(['quality_monitoring' => true])
    ->say('Welcome to customer service. How can I help you today?');
echo json_encode($csMonitor->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 5. Stop Tap Examples
echo "=== Stop Tap Examples ===\n";
$stopRecent = (new FunctionResult('Ending monitoring session'))
    ->stopTap()
    ->say('Call monitoring has been stopped');
echo "Stop most recent tap:\n" . json_encode($stopRecent->toArray(), JSON_PRETTY_PRINT) . "\n\n";

$stopSpecific = (new FunctionResult('Ending compliance monitoring'))
    ->stopTap('compliance_tap_001')
    ->updateGlobalData(['compliance_session' => false])
    ->say('Compliance monitoring has been deactivated');
echo "Stop specific tap:\n" . json_encode($stopSpecific->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 6. Call Center Workflow
echo "=== Call Center Workflow ===\n";
$startMonitor = (new FunctionResult('Call center session starting'))
    ->tap(
        uri:       'wss://callcenter.company.com/agent-monitoring',
        controlId: 'agent_monitor_001',
        direction: 'both',
        statusUrl: 'https://api.company.com/callcenter-events',
    )
    ->updateGlobalData(['call_monitored' => true])
    ->say('Thank you for calling. Your call may be monitored for quality assurance.');
echo "Start monitoring:\n" . json_encode($startMonitor->toArray(), JSON_PRETTY_PRINT) . "\n\n";

$endMonitor = (new FunctionResult('Ending call session'))
    ->stopTap('agent_monitor_001')
    ->updateGlobalData(['call_monitored' => false]);
echo "End monitoring:\n" . json_encode($endMonitor->toArray(), JSON_PRETTY_PRINT) . "\n";

echo "\nCOMPLETE: All tap examples completed\n";
