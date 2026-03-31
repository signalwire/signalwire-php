<?php
/**
 * Basic SWML Service Example
 *
 * Demonstrates using SWMLService directly to create and serve SWML documents
 * without AI components. Shows voicemail, IVR, and call transfer flows.
 */

require 'vendor/autoload.php';

use SignalWire\SWML\SWMLService;

// --- Voicemail Service ---

$voicemail = new SWMLService(name: 'voicemail', route: '/voicemail');
$voicemail->addAnswerVerb();
$voicemail->addVerb('play', ['url' => "say:Hello, you've reached the voicemail service. Please leave a message after the beep."]);
$voicemail->addVerb('sleep', 1000);
$voicemail->addVerb('play', ['url' => 'https://example.com/beep.wav']);
$voicemail->addVerb('record', [
    'format'     => 'mp3',
    'stereo'     => false,
    'beep'       => false,
    'max_length' => 120,
    'terminators' => '#',
    'status_url' => 'https://example.com/voicemail-status',
]);
$voicemail->addVerb('play', ['url' => 'say:Thank you for your message. Goodbye!']);
$voicemail->addHangupVerb();

// --- IVR Menu Service ---

$ivr = new SWMLService(name: 'ivr', route: '/ivr');
$ivr->addAnswerVerb();

$ivr->addSection('main_menu');
$ivr->addVerbToSection('main_menu', 'prompt', [
    'play'            => 'say:Welcome to our service. Press 1 for sales, 2 for support, or 3 to leave a message.',
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
        ['play' => ['url' => "say:I'm sorry, I didn't understand your selection."]],
        ['transfer' => ['dest' => 'main_menu']],
    ],
]);

$ivr->addSection('sales');
$ivr->addVerbToSection('sales', 'play', ['url' => 'say:Connecting you to sales. Please hold.']);
$ivr->addVerbToSection('sales', 'connect', ['to' => '+15551234567']);

$ivr->addSection('support');
$ivr->addVerbToSection('support', 'play', ['url' => 'say:Connecting you to support. Please hold.']);
$ivr->addVerbToSection('support', 'connect', ['to' => '+15557654321']);

$ivr->addVerb('transfer', ['dest' => 'main_menu']);

// --- Call Transfer Service ---

$transfer = new SWMLService(name: 'transfer', route: '/transfer');
$transfer->addAnswerVerb();
$transfer->addVerb('play', ['url' => "say:Thank you for calling. We'll connect you with the next available agent."]);
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
$transfer->addVerb('play', ['url' => "say:We're sorry, but all of our agents are currently busy. Please leave a message."]);
$transfer->addVerb('record', [
    'format'     => 'mp3',
    'stereo'     => false,
    'beep'       => true,
    'max_length' => 120,
    'terminators' => '#',
]);
$transfer->addVerb('play', ['url' => "say:Thank you for your message. We'll get back to you as soon as possible."]);
$transfer->addHangupVerb();

// --- Render ---

$service = $argv[1] ?? 'voicemail';

$services = [
    'voicemail' => $voicemail,
    'ivr'       => $ivr,
    'transfer'  => $transfer,
];

$selected = $services[$service] ?? $voicemail;

echo "Starting {$service} service\n";
echo "Available at: http://localhost:3000{$selected->route()}\n";

$selected->run();
