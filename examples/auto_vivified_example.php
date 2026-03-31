<?php
/**
 * Auto-Vivified SWML Service Example
 *
 * Demonstrates building SWML documents using auto-vivification where
 * verb methods can be called directly on the service (e.g. $service->play())
 * instead of using addVerb('play', ...).
 *
 * Shows voicemail, IVR, and call transfer services.
 */

require 'vendor/autoload.php';

use SignalWire\SWML\SWMLService;

// --- Voicemail Service (auto-vivified) ---

$voicemail = new SWMLService(name: 'voicemail', route: '/voicemail');
$voicemail->addAnswerVerb();
$voicemail->play(['url' => "say:Hello, you've reached the voicemail service. Please leave a message after the beep."]);
$voicemail->sleep(1000);
$voicemail->play(['url' => 'https://example.com/beep.wav']);
$voicemail->record([
    'format'      => 'mp3',
    'stereo'      => false,
    'beep'        => false,
    'max_length'  => 120,
    'terminators' => '#',
    'status_url'  => 'https://example.com/voicemail-status',
]);
$voicemail->play(['url' => 'say:Thank you for your message. Goodbye!']);
$voicemail->addHangupVerb();

// --- IVR Menu Service ---

$ivr = new SWMLService(name: 'ivr', route: '/ivr');
$ivr->addAnswerVerb();

$ivr->addSection('main_menu');
$ivr->addVerbToSection('main_menu', 'prompt', [
    'play'            => 'say:Welcome. Press 1 for sales, 2 for support, or 3 for voicemail.',
    'max_digits'      => 1,
    'terminators'     => '#',
    'digit_timeout'   => 5.0,
    'initial_timeout' => 10.0,
]);
$ivr->addVerbToSection('main_menu', 'switch', [
    'variable' => 'prompt_digits',
    'case' => [
        '1' => [['transfer' => ['dest' => 'sales']]],
        '2' => [['transfer' => ['dest' => 'support']]],
        '3' => [['transfer' => ['dest' => 'voicemail']]],
    ],
    'default' => [
        ['play' => ['url' => "say:I didn't understand your selection."]],
        ['transfer' => ['dest' => 'main_menu']],
    ],
]);

$ivr->addSection('sales');
$ivr->addVerbToSection('sales', 'play', ['url' => 'say:Connecting you to sales.']);
$ivr->addVerbToSection('sales', 'connect', ['to' => '+15551234567']);

$ivr->addSection('support');
$ivr->addVerbToSection('support', 'play', ['url' => 'say:Connecting you to support.']);
$ivr->addVerbToSection('support', 'connect', ['to' => '+15557654321']);

$ivr->addVerb('transfer', ['dest' => 'main_menu']);

// --- Call Transfer Service ---

$transfer = new SWMLService(name: 'transfer', route: '/transfer');
$transfer->addAnswerVerb();
$transfer->play(['url' => "say:Thank you for calling. We'll connect you with the next available agent."]);
$transfer->addVerb('connect', [
    'from'             => '+15551234567',
    'timeout'          => 30,
    'answer_on_bridge' => true,
    'ringback'         => ['ring:us'],
    'parallel'         => [
        ['to' => '+15552223333'],
        ['to' => '+15554445555'],
        ['to' => '+15556667777'],
    ],
]);
$transfer->play(['url' => "say:All agents are busy. Please leave a message."]);
$transfer->record([
    'format'      => 'mp3',
    'stereo'      => false,
    'beep'        => true,
    'max_length'  => 120,
    'terminators' => '#',
]);
$transfer->play(['url' => "say:Thank you. We'll get back to you soon."]);
$transfer->addHangupVerb();

// --- Select and Run ---

$serviceName = $argv[1] ?? 'voicemail';
$services = ['voicemail' => $voicemail, 'ivr' => $ivr, 'transfer' => $transfer];
$selected = $services[$serviceName] ?? $voicemail;

echo "Starting {$serviceName} service (auto-vivified)\n";
echo "Available at: http://localhost:3000{$selected->route()}\n";

$selected->run();
