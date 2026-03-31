<?php
/**
 * Record Call Example
 *
 * Demonstrates using record_call and stop_record_call FunctionResult
 * helpers for background call recording in various scenarios:
 * - Basic recording
 * - Advanced recording with custom settings
 * - Voicemail recording
 * - Compliance recording
 */

require 'vendor/autoload.php';

use SignalWire\SWAIG\FunctionResult;

// 1. Basic Recording
echo "=== Basic Recording Example ===\n";
$basic = (new FunctionResult('Starting basic call recording'))
    ->recordCall()
    ->say('This call is now being recorded');
echo json_encode($basic->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 2. Advanced Recording
echo "=== Advanced Recording Example ===\n";
$advanced = (new FunctionResult('Starting advanced call recording'))
    ->recordCall(
        controlId:  'support_call_001',
        stereo:     true,
        format:     'mp3',
        direction:  'both',
        terminators: '*#',
        beep:       true,
        maxLength:  600,
        statusUrl:  'https://api.company.com/recording-webhook',
    )
    ->say('This call is being recorded for quality and training purposes');
echo json_encode($advanced->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 3. Voicemail Recording
echo "=== Voicemail Recording Example ===\n";
$voicemail = (new FunctionResult('Please leave your message after the beep'))
    ->recordCall(
        controlId:  'voicemail_123456',
        format:     'wav',
        direction:  'speak',
        terminators: '#',
        beep:       true,
        maxLength:  120,
    );
echo json_encode($voicemail->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 4. Stop Recording
echo "=== Stop Recording Example ===\n";
$stop = (new FunctionResult('Ending call recording'))
    ->stopRecordCall('support_call_001')
    ->say('Thank you for calling. Your feedback is important to us.');
echo json_encode($stop->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 5. Customer Service Workflow
echo "=== Customer Service Workflow ===\n";
$startRec = (new FunctionResult('Transferring you to a customer service agent'))
    ->recordCall(
        controlId: 'cs_transfer_001',
        format:    'mp3',
        direction: 'both',
        beep:      false,
        maxLength: 1800,
        statusUrl: 'https://api.company.com/recording-status',
    )
    ->updateGlobalData(['recording_id' => 'cs_transfer_001'])
    ->say('Please hold while I connect you to an agent');
echo "Start recording:\n" . json_encode($startRec->toArray(), JSON_PRETTY_PRINT) . "\n\n";

$endRec = (new FunctionResult('Call recording stopped'))
    ->stopRecordCall('cs_transfer_001')
    ->say('Thank you for calling. Have a wonderful day!');
echo "End recording:\n" . json_encode($endRec->toArray(), JSON_PRETTY_PRINT) . "\n\n";

// 6. Compliance Recording
echo "=== Compliance Recording Example ===\n";
$compliance = (new FunctionResult('This call is being recorded for compliance purposes'))
    ->recordCall(
        controlId: 'compliance_rec_001',
        stereo:    true,
        format:    'wav',
        direction: 'both',
        beep:      true,
        statusUrl: 'https://compliance.company.com/recording-webhook',
    );
echo json_encode($compliance->toArray(), JSON_PRETTY_PRINT) . "\n";

echo "\nCOMPLETE: All recording examples completed\n";
