<?php
/**
 * SWML Service Example
 *
 * Demonstrates creating SWML services using direct verb manipulation
 * and the SWMLBuilder API. Shows voicemail and call recording flows.
 */

require 'vendor/autoload.php';

use SignalWire\SWML\Service as SWMLService;
use SignalWire\SWML\SWMLBuilder;

// --- Example 1: Direct document manipulation ---

echo "=== Example using SWMLService directly ===\n";

$service = new SWMLService(
    name:  'voicemail-direct',
    route: '/voicemail',
    host:  '0.0.0.0',
    port:  3000,
);

$service->answer();
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
$service->hangup();

echo "Document built:\n" . $service->renderDocument() . "\n\n";

// --- Example 2: SWMLBuilder fluent API ---
//
// SWMLBuilder wraps an SWMLService and delegates to it (Python parity:
// SWMLBuilder(service)). Typed verb helpers (answer/play/say/hangup) use
// named args; every other schema verb (record_call, sleep, ...) is
// auto-vivified through __call with a config array.

echo "=== Example using SWMLBuilder ===\n";

$builderService = new SWMLService(name: 'voicemail-builder', route: '/voicemail-builder');
$builder = new SWMLBuilder($builderService);
$builder
    ->answer()
    ->play(url: 'say:Welcome to the recording service.')
    ->record_call([
        'control_id' => 'call_recording',
        'format'     => 'mp3',
        'stereo'     => true,
        'direction'  => 'both',
        'beep'       => true,
    ])
    ->play(url: 'say:This call is being recorded for quality purposes.')
    ->sleep(30000)
    ->play(url: 'say:Thank you for your time. Goodbye!')
    ->hangup();

echo "Document built with SWMLBuilder:\n" . $builder->render() . "\n\n";

// --- Start the direct service ---

echo "Starting SWML Service\n";
echo "Available at: http://localhost:3000/voicemail\n";

$service->run();
