<?php

/**
 * strict_render_dump.php — the PHP port's SWML STRICT-RENDER dump program for
 * the cross-port negative differ (porting-sdk/scripts/diff_port_strict_render.py).
 *
 * The strict-render contract: building/rendering an SWML document with a
 * MISSHAPEN config, an UNKNOWN verb, or a MISSPELLED/unknown key must RAISE —
 * not silently drop or accept it. This program builds EACH of the 18
 * strict_render_corpus cases in PHP idiom, catches each build's exception as
 * "raised" (a clean build is "ok"), and emits ONE JSON object mapping
 *
 *     case-id -> "raised" | "ok"
 *
 * to stdout (JSON only; diagnostics go to stderr). The differ compares each
 * case's outcome against the python oracle.
 *
 * The corpus mixes two targets:
 *   - "SWMLService" cases exercise add_verb(name, config) with schema
 *     validation ON (Service::addVerb).
 *   - "AgentBase" cases exercise the contexts builder: define_tool +
 *     define_contexts -> add_context -> add_step -> set_text/set_functions/
 *     set_valid_contexts, then ContextBuilder validation (via toArray()).
 *
 * Run from the signalwire-php repo root:
 *
 *     php scripts/strict_render_dump.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWML\Service;

/**
 * Run a build closure and classify its outcome.
 *
 * A clean return is "ok"; ANY thrown Throwable (schema-validation error,
 * InvalidArgumentException, LogicException, dangling-reference error, etc.)
 * is "raised" — exactly the port-idiomatic failure the contract asks the SDK
 * to surface instead of a silent drop.
 *
 * @param callable():void $build
 */
function outcome(callable $build): string
{
    try {
        $build();
        return 'ok';
    } catch (\Throwable $e) {
        return 'raised';
    }
}

/** A SWMLService with schema validation ON (production default). */
function strictService(): Service
{
    return new Service(name: 'strict', route: '/strict');
}

/** An AgentBase with schema validation ON. */
function strictAgent(): AgentBase
{
    return new AgentBase(name: 'ctxagent', route: '/ctx');
}

$out = [];

// ================================================================
// Verb-level strict render (target: SWMLService, validation ON)
// ================================================================

// unknown / misspelled verb
$out['strict_unknown_verb'] = outcome(function (): void {
    strictService()->addVerb('foobar', []);
});

// misspelled / unknown config key on a CLOSED verb
$out['strict_answer_misspelled_key'] = outcome(function (): void {
    strictService()->addVerb('answer', ['maxduration' => 5]);
});
$out['strict_answer_unknown_key'] = outcome(function (): void {
    strictService()->addVerb('answer', ['wibble' => 1]);
});
$out['strict_play_misspelled_key'] = outcome(function (): void {
    strictService()->addVerb('play', ['urlz' => ['say:hi']]);
});
$out['strict_play_valid_plus_unknown_key'] = outcome(function (): void {
    strictService()->addVerb('play', ['url' => 'say:hi', 'foo' => 1]);
});
$out['strict_record_misspelled_key'] = outcome(function (): void {
    strictService()->addVerb('record', ['formatt' => 'wav']);
});

// wrong-typed config
$out['strict_answer_wrong_type'] = outcome(function (): void {
    strictService()->addVerb('answer', ['max_duration' => 'notanumber']);
});

// the ai verb: unknown/misspelled TOP-LEVEL keys (GAP 1); ai.params OPEN
$out['strict_ai_misspelled_top_key'] = outcome(function (): void {
    strictService()->addVerb('ai', ['prompt' => ['text' => 'hi'], 'temperatur' => 0.5]);
});
$out['strict_ai_unknown_top_key'] = outcome(function (): void {
    strictService()->addVerb('ai', ['prompt' => ['text' => 'hi'], 'zzz' => 1]);
});
$out['strict_ai_missing_prompt'] = outcome(function (): void {
    strictService()->addVerb('ai', ['post_prompt' => ['text' => 'bye']]);
});

// good documents must still render (regression guard)
$out['strict_answer_ok'] = outcome(function (): void {
    strictService()->addVerb('answer', ['max_duration' => 5]);
});
$out['strict_play_ok'] = outcome(function (): void {
    strictService()->addVerb('play', ['url' => 'say:hi']);
});
$out['strict_ai_ok'] = outcome(function (): void {
    strictService()->addVerb('ai', ['prompt' => ['text' => 'hi']]);
});
$out['strict_ai_params_open_ok'] = outcome(function (): void {
    strictService()->addVerb('ai', ['prompt' => ['text' => 'hi'], 'params' => ['some_future_param' => 1]]);
});

// ================================================================
// Contexts-level strict render (target: AgentBase; dangling refs)
// ================================================================

// dangling step-function reference (GAP 2 / r5 F3)
$out['strict_dangling_step_function'] = outcome(function (): void {
    $agent = strictAgent();
    $agent->defineTool('order_status', 'look up an order', [], fn (array $a, array $raw): mixed => null);
    $contexts = $agent->defineContexts();
    $ctx = $contexts->addContext('default');
    $step = $ctx->addStep('help');
    $step->setText('help');
    $step->setFunctions(['order_status', 'get_datetime']);
    $contexts->toArray();
});

// a step referencing a registered tool must render
$out['strict_registered_step_function_ok'] = outcome(function (): void {
    $agent = strictAgent();
    $agent->defineTool('order_status', 'look up an order', [], fn (array $a, array $raw): mixed => null);
    $contexts = $agent->defineContexts();
    $ctx = $contexts->addContext('default');
    $step = $ctx->addStep('help');
    $step->setText('help');
    $step->setFunctions(['order_status']);
    $contexts->toArray();
});

// reserved native tools (next_step/change_context) are not dangling
$out['strict_reserved_native_function_ok'] = outcome(function (): void {
    $agent = strictAgent();
    $contexts = $agent->defineContexts();
    $ctx = $contexts->addContext('default');
    $step = $ctx->addStep('help');
    $step->setText('help');
    $step->setFunctions(['next_step', 'change_context']);
    $contexts->toArray();
});

// valid_contexts references an undefined context — must raise
$out['strict_dangling_valid_context'] = outcome(function (): void {
    $agent = strictAgent();
    $contexts = $agent->defineContexts();
    $ctx = $contexts->addContext('default');
    $step = $ctx->addStep('help');
    $step->setText('help');
    $step->setValidContexts(['nowhere']);
    $contexts->toArray();
});

$encoded = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    fwrite(STDERR, 'strict-render-dump: json_encode failed: ' . json_last_error_msg() . "\n");
    exit(1);
}
echo $encoded, "\n";
