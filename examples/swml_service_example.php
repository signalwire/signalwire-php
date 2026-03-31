<?php
/**
 * SWML Service Example
 *
 * Demonstrates creating SWML services using direct verb manipulation
 * and the SWMLBuilder API. Shows voicemail and call recording flows.
 */

require 'vendor/autoload.php';

use SignalWire\SWML\SWMLService;
use SignalWire\SWML\SWMLBuilder;

// --- Example 1: Direct document manipulation ---

echo "=== Example using SWMLService directly ===\n";

$service = new SWMLService(
    name:  'voicemail-direct',
    route: '/voicemail',
    host:  '0.0.0.0',
    port:  3000,
);

$service->addAnswerVerb();
$service->addVerb('play', [
    'url' => "say:Hello, you've reached our voicemail service. Please leave a message after the beep.",
]);
$service->addVerb('sleep', 1000);
$service->addVerb('record', [
    'format'      => 'mp3',
    'stereo'      => false,
    'beep'        => true,
    'max_length'  => 120,
    'terminators' => '#',
]);
$service->addVerb('play', ['url' => 'say:Thank you for your message. Goodbye!']);
$service->addHangupVerb();

echo "Document built with " . count($service->getVerbs()) . " verbs\n\n";

// --- Example 2: SWMLBuilder fluent API ---

echo "=== Example using SWMLBuilder ===\n";

$builder = new SWMLBuilder();
$builder
    ->answer()
    ->play(['url' => 'say:Welcome to the recording service.'])
    ->recordCall([
        'control_id' => 'call_recording',
        'format'     => 'mp3',
        'stereo'     => true,
        'direction'  => 'both',
        'beep'       => true,
    ])
    ->play(['url' => 'say:This call is being recorded for quality purposes.'])
    ->sleep(30000)
    ->play(['url' => 'say:Thank you for your time. Goodbye!'])
    ->hangup();

echo "Document built with SWMLBuilder\n\n";

// --- Start the direct service ---

echo "Starting SWML Service\n";
echo "Available at: http://localhost:3000/voicemail\n";

$service->run();
