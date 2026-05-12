<?php
/**
 * Per-Question Function Whitelist Demo (gather_info)
 *
 * This example exists to teach one specific gotcha: while a step's
 * gather_info is asking questions, ALL of the step's other functions
 * are forcibly deactivated. The only callable tools during a gather
 * question are:
 *
 *   - `gather_submit` (the native answer-submission tool, always
 *     active)
 *   - Whatever names you list in that question's `functions` option
 *
 * `next_step` and `change_context` are also filtered out — the model
 * literally cannot navigate away until the gather completes. This is
 * by design: it forces a tight ask → submit → next-question loop.
 *
 * If a question needs to call out to a tool — for example, to
 * validate an email format, geocode a ZIP, or look up something from
 * an external service — you must list that tool name in the
 * question's `functions` option. The function is active ONLY for
 * that question.
 *
 * Below: a customer-onboarding gather flow where each question
 * unlocks a different validation tool, and where the step's own
 * non-gather tools (escalate_to_human, lookup_existing_account) are
 * LOCKED OUT during gather because they aren't whitelisted on any
 * question.
 *
 * Run this file to see the resulting SWML.
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

$agent = new AgentBase(
    name:  'gather_per_question_functions_demo',
    route: '/'
);

// Tools that the step would normally have available — but during
// gather questioning, they're all locked out unless they appear in a
// question's `functions` whitelist.
$agent->defineTool(
    'validate_email',
    'Validate that an email address is well-formed and deliverable',
    ['email' => ['type' => 'string']],
    fn(array $args, array $raw) => new FunctionResult('valid'),
);
$agent->defineTool(
    'geocode_zip',
    'Look up the city/state for a US ZIP code',
    ['zip' => ['type' => 'string']],
    fn(array $args, array $raw) => new FunctionResult('{"city":"...","state":"..."}'),
);
$agent->defineTool(
    'check_age_eligibility',
    'Verify the customer is old enough for the product',
    ['age' => ['type' => 'integer']],
    fn(array $args, array $raw) => new FunctionResult('eligible'),
);
// These tools are NOT whitelisted on any gather question. They are
// registered on the agent and active outside the gather, but during
// the gather they cannot be called — gather mode locks them out.
$agent->defineTool(
    'escalate_to_human',
    'Transfer the conversation to a live agent',
    [],
    fn(array $args, array $raw) => new FunctionResult('transferred'),
);
$agent->defineTool(
    'lookup_existing_account',
    'Search for an existing account by email',
    ['email' => ['type' => 'string']],
    fn(array $args, array $raw) => new FunctionResult('not found'),
);

// Build a single-context agent with one onboarding step.
$cb  = $agent->defineContexts();
$ctx = $cb->addContext('default');

$onboard = $ctx->addStep('onboard');
$onboard
    ->setText(
        'Onboard a new customer by collecting their details. Use '
        . 'gather_info to ask one question at a time. Each question '
        . 'may unlock a specific validation tool — only that tool '
        . 'and gather_submit are callable while answering it.'
    )
    ->setFunctions([
        // Outside of the gather (which is the entire step here),
        // these would be available. During the gather they are
        // forcibly hidden in favor of the per-question whitelists.
        'escalate_to_human',
        'lookup_existing_account',
    ])
    ->setGatherInfo([
        'output_key'        => 'customer',
        'completion_action' => 'next_step',
        'prompt'            => "I'll need to collect a few details to set up "
                             . "your account. I'll ask one question at a time.",
    ])
    // Question 1: email — only validate_email + gather_submit
    // callable.
    ->addGatherQuestion([
        'key'       => 'email',
        'question'  => "What's your email address?",
        'confirm'   => true,
        'functions' => ['validate_email'],
    ])
    // Question 2: zip — only geocode_zip + gather_submit callable.
    ->addGatherQuestion([
        'key'       => 'zip',
        'question'  => "What's your ZIP code?",
        'functions' => ['geocode_zip'],
    ])
    // Question 3: age — only check_age_eligibility + gather_submit
    // callable.
    ->addGatherQuestion([
        'key'       => 'age',
        'question'  => 'How old are you?',
        'type'      => 'integer',
        'functions' => ['check_age_eligibility'],
    ])
    // Question 4: referral_source — no `functions` → only
    // gather_submit is callable. The model cannot validate, lookup,
    // escalate — nothing. This is the right pattern when a question
    // needs no tools.
    ->addGatherQuestion([
        'key'      => 'referral_source',
        'question' => 'How did you hear about us?',
    ]);

// A simple confirmation step the gather auto-advances into.
$ctx->addStep('confirm')
    ->setText(
        'Read the collected info back to the customer and confirm '
        . 'everything is correct.'
    )
    ->setFunctions([])
    ->setEnd(true);

$swml = $agent->renderSwml();
echo json_encode($swml, JSON_PRETTY_PRINT) . "\n";
