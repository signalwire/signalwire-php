<?php

/**
 * swml_dump.php — the PHP port's SWML dump program for the cross-port SWML
 * differ (porting-sdk/scripts/diff_port_swml.py).
 *
 * For each swml_corpus case it builds an AgentBase, applies the setter chain,
 * renders the SWML document, and extracts the observed dotted path (e.g.
 * "ai.prompt.pom") — emitting ONE JSON object mapping
 *
 *     case-id -> extracted-fragment
 *
 * to stdout. The differ canonicalizes both sides and byte-compares against the
 * python oracle. Only stdout carries JSON; logs go to stderr.
 * Mirrors the Go reference dump (signalwire-go/cmd/swml-dump/main.go).
 *
 * Run from the signalwire-php repo root:
 *
 *     php scripts/swml_dump.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\Agent\AgentBase;

/** newAgent constructs a demo AgentBase (name "demo", route "/demo") with POM on. */
function newAgent(): AgentBase
{
    return new AgentBase(name: 'demo', route: '/demo', usePom: true);
}

/** @return array<string,mixed> */
function renderDoc(AgentBase $a): array
{
    return $a->renderSwml();
}

/**
 * extract walks a dotted path into a rendered SWML doc. "ai.prompt" means: find
 * the ai verb in sections.main, then index into it — the PHP mirror of
 * diff_port_swml._extract.
 *
 * @param array<string,mixed> $doc
 */
function extractPath(array $doc, string $path): mixed
{
    $ai = null;
    $sections = $doc['sections'] ?? null;
    if (is_array($sections)) {
        $main = $sections['main'] ?? null;
        if (is_array($main)) {
            foreach ($main as $sec) {
                if (is_array($sec) && array_key_exists('ai', $sec)) {
                    $ai = $sec['ai'];
                    break;
                }
            }
        }
    }

    $node = $ai !== null ? ['ai' => $ai] : $doc;
    foreach (explode('.', $path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return $node;
}

/**
 * pick reduces a map fragment to the listed keys (mirrors the oracle's `pick`).
 *
 * @param list<string> $keys
 */
function pick(mixed $frag, array $keys): mixed
{
    if (!is_array($frag)) {
        return $frag;
    }
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = $frag[$k] ?? null;
    }
    return $out;
}

$out = [];

// swml_set_prompt_llm_params: two set_prompt_llm_params calls MERGE.
$a = newAgent();
$a->setPromptLlmParams(['temperature' => 0.5]);
$a->setPromptLlmParams(['top_p' => 0.9]);
$out['swml_set_prompt_llm_params'] = pick(extractPath(renderDoc($a), 'ai.prompt'), ['temperature', 'top_p']);

// swml_set_post_prompt_llm_params: establish a post-prompt, then merge params.
$a = newAgent();
$a->setPostPrompt('Summarize the call.');
$a->setPostPromptLlmParams(['temperature' => 0.3]);
$a->setPostPromptLlmParams(['top_p' => 0.8]);
$out['swml_set_post_prompt_llm_params'] = pick(extractPath(renderDoc($a), 'ai.post_prompt'), ['temperature', 'top_p']);

// swml_add_language: engine/model/voice carried into ai.languages.
$a = newAgent();
$a->addLanguage(name: 'English', code: 'en-US', voice: 'rime.spore', engine: 'rime', model: 'mistv2');
$out['swml_add_language'] = extractPath(renderDoc($a), 'ai.languages');

// swml_add_pattern_hint: structured hint into ai.hints.
$a = newAgent();
$a->addPatternHint('SignalWire', 'signal wire', 'SignalWire', true);
$out['swml_add_pattern_hint'] = extractPath(renderDoc($a), 'ai.hints');

// swml_add_hint: a plain string hint.
$a = newAgent();
$a->addHint('SignalWire');
$out['swml_add_hint'] = extractPath(renderDoc($a), 'ai.hints');

// swml_prompt_add_section: POM sections render into ai.prompt.pom.
$a = newAgent();
$a->promptAddSection('Role', 'You are a helpful assistant.');
$a->promptAddSection('Rules', '', ['Be concise', 'Be accurate']);
$out['swml_prompt_add_section'] = extractPath(renderDoc($a), 'ai.prompt.pom');

// swml_add_pronunciation: renders into ai.pronounce.
$a = newAgent();
$a->addPronunciation('SW', 'SignalWire', true);
$out['swml_add_pronunciation'] = extractPath(renderDoc($a), 'ai.pronounce');

// swml_define_tool_complete_schema: a COMPLETE {type,properties,required} schema
// passed to defineTool must PASS THROUGH — the rendered SWAIG function's argument
// (PHP's rename of the oracle's `parameters` field) is that schema FLAT, not
// double-wrapped. Extract ai.SWAIG.functions[?function=lookup].argument.
$a = newAgent();
$a->defineTool(
    'lookup',
    'Look up a thing',
    ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
    fn (array $args, array $raw): mixed => null,
);
$functions = extractPath(renderDoc($a), 'ai.SWAIG.functions');
$lookupArg = null;
if (is_array($functions)) {
    foreach ($functions as $fn) {
        if (is_array($fn) && ($fn['function'] ?? null) === 'lookup') {
            $lookupArg = $fn['argument'] ?? null;
            break;
        }
    }
}
$out['swml_define_tool_complete_schema'] = $lookupArg;

$encoded = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    fwrite(STDERR, 'swml-dump: json_encode failed: ' . json_last_error_msg() . "\n");
    exit(1);
}
echo $encoded, "\n";
