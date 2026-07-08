<?php

/**
 * state_dump.php — the PHP port's STATE dump program for the cross-port state
 * differ (porting-sdk/scripts/diff_port_state.py).
 *
 * For each state_corpus case it builds the target object, applies the mutation
 * chain via the PHP SDK's native API, reads the observable state through the
 * public accessor / rendered representation, and prints ONE JSON object mapping
 *
 *     case-id -> observed-state
 *
 * to stdout. The differ canonicalizes both sides and byte-compares against the
 * python oracle. Only stdout carries JSON; logs go to stderr.
 * Mirrors the Go reference dump (signalwire-go/cmd/state-dump/main.go).
 *
 * A couple of observables (the AgentBase lowercased sip-username set; the
 * VerbHandlerRegistry's verb-name list) have no public accessor in the PHP
 * surface — they are read here via reflection (a test-harness concern, kept out
 * of the SDK's public API).
 *
 * Run from the signalwire-php repo root:
 *
 *     php scripts/state_dump.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\Contexts\ContextBuilder;
use SignalWire\Prefabs\InfoGathererAgent;
use SignalWire\Server\AgentServer;
use SignalWire\Skills\SkillRegistry;
use SignalWire\SWML\Service;
use SignalWire\SWML\SWMLVerbHandler;
use SignalWire\SWML\VerbHandlerRegistry;

/**
 * Read a protected/private array property via reflection (test-harness only).
 *
 * @return array<array-key,mixed>
 */
function reflectProp(object $obj, string $prop): array
{
    $rp = new ReflectionProperty($obj, $prop);
    $rp->setAccessible(true);
    $val = $rp->getValue($obj);
    return is_array($val) ? $val : [];
}

function demoAgent(): AgentBase
{
    return new AgentBase(name: 'demo', route: '/demo');
}

// A minimal custom verb handler — the PHP analog of the corpus's throwaway
// __register_verb__ handler.
$greetHandler = new class ('greet') extends SWMLVerbHandler {
    public function __construct(private string $verb)
    {
    }

    public function getVerbName(): string
    {
        return $this->verb;
    }

    /** @return array{0: bool, 1: list<string>} */
    public function validateConfig(array $config): array
    {
        return [true, []];
    }

    /** @return array<string,mixed> */
    public function buildConfig(array $kwargs = []): array
    {
        return $kwargs;
    }
};

$out = [];

// ---- global_data: set MERGES into the accumulated global data ----
$a = demoAgent();
$a->setGlobalData(['company' => 'SignalWire', 'tier' => 'gold']);
$out['state_set_global_data'] = reflectProp($a, 'globalData');

$a = demoAgent();
$a->updateGlobalData(['k1' => 'v1']);
$a->updateGlobalData(['k2' => 'v2']);
$out['state_update_global_data'] = reflectProp($a, 'globalData');

$a = demoAgent();
$a->setGlobalData(['a' => 1, 'b' => 2]);
$a->setGlobalData(['b' => 99, 'c' => 3]);
$out['state_global_data_merge'] = reflectProp($a, 'globalData');

// ---- sip-username registration on AgentBase (lowercased set) ----
$a = demoAgent();
$a->registerSipUsername('Bob');
$a->registerSipUsername('alice');
$names = array_keys(reflectProp($a, 'sipUsernames'));
sort($names);
$out['state_register_sip_username'] = $names;

$a = demoAgent();
$a->registerSipUsername('Bob');
$a->registerSipUsername('BOB');
$a->registerSipUsername('bob');
$names = array_keys(reflectProp($a, 'sipUsernames'));
sort($names);
$out['state_register_sip_username_dedup'] = $names;

// ---- AgentServer sip-username mapping (username -> route) + lookup ----
$s = new AgentServer();
$s->setupSipRouting('/sip', false);
$s->registerSipUsername('Bob', '/agent');
$s->registerSipUsername('sales', '/sales');
// Lookup mirrors Python's private AgentServer._lookup_sip_route:
// mapping.get(username.lower()). The store is lowercased by registerSipUsername.
$mapping = $s->getSipUsernameMapping();
$lookup = static fn (string $u): ?string => $mapping[strtolower($u)] ?? null;
$out['server_sip_username_mapping'] = [
    'mapping' => $mapping,
    'lookup_bob' => $lookup('bob'),
    'lookup_BOB' => $lookup('BOB'),
    'lookup_missing' => $lookup('nope'),
];

// ---- unregister removes the agent route from the registry ----
$s = new AgentServer();
$s->register(new AgentBase(name: 'agent', route: '/agent'), '/agent');
$s->register(new AgentBase(name: 'other', route: '/other'), '/other');
$s->unregister('/agent');
$out['server_unregister'] = $s->getAgents();

// ---- routing-callback registration on SWMLService (path-normalized) ----
$svc = new Service(name: 'svc', route: '/svc');
$noop = static fn (array $body, array $headers): ?string => null;
$svc->registerRoutingCallback('/sip/', $noop);
$svc->registerRoutingCallback('voice', $noop);
$paths = array_keys(reflectProp($svc, 'routingCallbacks'));
sort($paths);
$out['state_register_routing_callback'] = $paths;

// ---- verb-handler registration (VerbHandlerRegistry: ai preloaded) ----
$reg = new VerbHandlerRegistry();
$reg->registerHandler($greetHandler);
$verbNames = array_keys(reflectProp($reg, 'handlers'));
sort($verbNames);
$out['state_register_verb_handler'] = [
    'verbs' => $verbNames,
    'has_greet' => $reg->hasHandler('greet'),
    'has_ai' => $reg->hasHandler('ai'),
    'has_missing' => $reg->hasHandler('nope'),
];

// ---- skill registration (SkillRegistry: name -> factory, idempotent) ----
$skReg = new SkillRegistry();
$skReg->registerSkill('custom_alpha', 'SignalWire\\Skills\\SkillBase');
$skReg->registerSkill('custom_beta', 'SignalWire\\Skills\\SkillBase');
$skReg->registerSkill('custom_alpha', 'SignalWire\\Skills\\SkillBase'); // idempotent
$skillNames = array_keys(reflectProp($skReg, 'registeredSkills'));
sort($skillNames);
$out['state_register_skill'] = $skillNames;

// ---- InfoGatherer.submit_answer: records answer + advances index ----
$questions = [
    ['key_name' => 'name', 'question_text' => 'What is your name?'],
    ['key_name' => 'email', 'question_text' => 'What is your email?'],
];

$out['infogatherer_submit_answer_first'] = submitAnswerDelta(
    new InfoGathererAgent(name: 'demo', questions: $questions, route: '/demo'),
    ['answer' => 'Alice'],
    ['global_data' => [
        'questions' => $questions,
        'question_index' => 0,
        'answers' => [],
    ]]
);

$out['infogatherer_submit_answer_last'] = submitAnswerDelta(
    new InfoGathererAgent(name: 'demo', questions: $questions, route: '/demo'),
    ['answer' => 'a@b.com'],
    ['global_data' => [
        'questions' => $questions,
        'question_index' => 1,
        'answers' => [['key_name' => 'name', 'answer' => 'Alice']],
    ]]
);

// ---- contexts/steps navigation (valid_steps rendered per step) ----
$a = demoAgent();
$cb = $a->defineContexts();
$ctx = $cb->addContext('default');
$ctx->addStep('greet', task: 'Greet the caller.', valid_steps: ['collect']);
$ctx->addStep('collect', task: 'Collect their info.', valid_steps: ['greet']);
$out['state_contexts_navigation'] = contextsNav($cb);

/**
 * submitAnswerDelta drives InfoGatherer::submitAnswer and reduces the result to
 * the observable delta (mirrors diff_port_state._observe "submit_answer_delta").
 *
 * @param array<string,mixed> $args
 * @param array<string,mixed> $rawData
 * @return array<string,mixed>
 */
function submitAnswerDelta(InfoGathererAgent $ig, array $args, array $rawData): array
{
    $result = $ig->submitAnswer($args, $rawData);
    $m = $result->toArray();
    $actions = is_array($m['action'] ?? null) ? $m['action'] : [];
    $gd = [];
    foreach ($actions as $action) {
        if (is_array($action) && isset($action['set_global_data']) && is_array($action['set_global_data'])) {
            $gd = $action['set_global_data'];
            break;
        }
    }
    $resp = is_string($m['response'] ?? null) ? $m['response'] : '';
    return [
        'question_index' => $gd['question_index'] ?? null,
        'answers' => $gd['answers'] ?? null,
        'done' => str_contains($resp, 'All questions have been answered'),
    ];
}

/**
 * contextsNav renders the builder and reduces to per-context {name, valid_steps}.
 *
 * @return array<string,mixed>
 */
function contextsNav(ContextBuilder $cb): array
{
    $m = $cb->toArray();
    $nav = [];
    foreach ($m as $cname => $cdoc) {
        $stepsRaw = $cdoc['steps'] ?? null;
        $reduced = [];
        if (is_array($stepsRaw)) {
            foreach ($stepsRaw as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $reduced[] = [
                    'name' => $step['name'] ?? null,
                    'valid_steps' => $step['valid_steps'] ?? null,
                ];
            }
        }
        $nav[$cname] = $reduced;
    }
    return $nav;
}

$encoded = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    fwrite(STDERR, 'state-dump: json_encode failed: ' . json_last_error_msg() . "\n");
    exit(1);
}
echo $encoded, "\n";
