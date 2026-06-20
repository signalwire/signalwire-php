<?php

/**
 * emit_skills.php — the PHP port's SKILL-DUMP program for the cross-port
 * SKILL-CONTRACT differ (porting-sdk/scripts/diff_skill_contracts.py).
 *
 * The sibling of emit_corpus.php, for built-in SKILLS rather than
 * FunctionResult. For each covered skill it looks the skill class up in the
 * registry, instantiates it with the canonical config from the shared corpus
 * (porting-sdk/scripts/skill_contract_corpus.py — the single source of truth)
 * and a CAPTURING fake agent, runs setup() + registerTools(), and prints ONE
 * JSON object mapping
 *
 *     skill-id -> [ { "name": ..., "parameters": {...}, "required"?: [...] }, ... ]
 *
 * to stdout. The differ runs this program, parses that object, and structurally
 * compares each skill's tool contract against the Python reference (which
 * registers the same tools). The differ normalises both sides (flat vs wrapped
 * params, required list, enum order); this program emits each tool's name +
 * parameters verbatim. DESCRIPTIONS are NOT part of the compared contract.
 * Mirrors the Ruby reference dump (signalwire-ruby/bin/emit-skills).
 *
 * CAPTURING FAKE AGENT
 * ====================
 * SkillBase::__construct(object $agent, array $params=[]); its protected
 * defineTool() forwards to $agent->defineTool(name, description, parameters,
 * handler). DataMap skills instead call $agent->registerSwaigFunction($def)
 * directly. The fake agent below records BOTH call shapes:
 *   * defineTool(name, desc, params, handler) — `params` is the flat
 *     {param => {type, description, required?, ...}} map (the PHP idiom marks a
 *     required param with an inline `'required' => true`). We lift those inline
 *     flags into a top-level `required` array — that IS what PHP's inline-
 *     required convention means on the wire, and it lets the differ (which
 *     drops a param's inline `required` and reads only the top-level list) see
 *     the same required set Python's `define_tool(..., required=[...])` yields.
 *   * registerSwaigFunction($def) — a raw SWAIG dict: the name lives under
 *     "function" and the WRAPPED arg schema (with its own top-level `required`
 *     array) under "argument". We forward `argument` as the parameters object
 *     so the differ reads `properties` + `required` straight from it. The
 *     DataMap skills (GoogleMaps, DatasphereServerless, PlayBackgroundFile,
 *     SwmlTransfer) and a few API skills (ApiNinjasTrivia, Joke, WeatherApi)
 *     register this way.
 *
 * CONTRACT (mirrors the per-port dump contract in the differ's --help):
 *   - The id set MUST equal corpus_ids() (the differ rejects a mismatch).
 *   - Only stdout carries the JSON object; all logs/errors go to stderr.
 *
 * Run from the signalwire-php repo root:
 *
 *     php scripts/emit_skills.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\Skills\SkillRegistry;

/**
 * CapturingAgent — a minimal fake agent that records the tool contracts a skill
 * registers, via either of the two registration paths skills use. It implements
 * only the surface SkillBase + the DataMap skills touch during registerTools().
 */
final class CapturingAgent
{
    /** @var list<array{name: string, parameters: array<string, mixed>, required?: list<string>}> */
    public array $tools = [];

    /**
     * Mirror of SWMLService::defineTool — handler tools. `$parameters` is the
     * flat {param => {type, ...}} map; the PHP idiom marks a required param with
     * an inline `'required' => true`. Lift those into a top-level `required`
     * list (and strip the inline flag from the param def so the emitted schema
     * is the canonical {type, enum?} the differ compares).
     *
     * @param array<string, mixed> $parameters
     */
    public function defineTool(
        string $name,
        string $description,
        array $parameters,
        callable $handler,
        bool $secure = false,
    ): self {
        $props = [];
        $required = [];
        foreach ($parameters as $param => $def) {
            if (is_array($def) && !empty($def['required'])) {
                $required[] = $param;
            }
            if (is_array($def)) {
                unset($def['required']);
            }
            $props[$param] = $def;
        }
        $tool = ['name' => $name, 'parameters' => $props];
        if ($required !== []) {
            $tool['required'] = $required;
        }
        $this->tools[] = $tool;
        return $this;
    }

    /**
     * Mirror of SWMLService::registerSwaigFunction — DataMap / raw SWAIG tools.
     * The name lives under "function"; the wrapped arg schema (with its own
     * top-level `required`) under "argument". Forward `argument` verbatim as the
     * parameters object so the differ reads `properties` + `required` from it.
     *
     * @param array<string, mixed> $funcDef
     */
    public function registerSwaigFunction(array $funcDef): self
    {
        $name = $funcDef['function'] ?? '';
        if ($name === '') {
            return $this;
        }
        $argument = $funcDef['argument'] ?? [];
        $this->tools[] = ['name' => $name, 'parameters' => $argument];
        return $this;
    }
}

/**
 * Locate porting-sdk/scripts/skill_contract_corpus.py via $PORTING_SDK /
 * $PORTING_SDK_PATH or the sibling ../porting-sdk (the adjacency convention),
 * run it, and return its `corpus` array (each entry {id, skill, config}).
 *
 * @return list<array{id: string, skill: string, config?: array<string, mixed>}>
 */
function load_corpus(): array
{
    $bases = array_filter([
        getenv('PORTING_SDK') ?: null,
        getenv('PORTING_SDK_PATH') ?: null,
        __DIR__ . '/../../porting-sdk',
    ]);

    foreach ($bases as $base) {
        $script = rtrim($base, '/') . '/scripts/skill_contract_corpus.py';
        if (!is_file($script)) {
            continue;
        }
        $out = shell_exec('python3 ' . escapeshellarg($script));
        if ($out === null || $out === false) {
            throw new \RuntimeException("running {$script} failed");
        }
        $decoded = json_decode($out, true);
        if (!is_array($decoded) || !isset($decoded['corpus']) || !is_array($decoded['corpus'])) {
            throw new \RuntimeException("malformed corpus from {$script}");
        }
        return $decoded['corpus'];
    }

    throw new \RuntimeException(
        'cannot locate porting-sdk/scripts/skill_contract_corpus.py '
        . '(set PORTING_SDK / PORTING_SDK_PATH or clone porting-sdk adjacent)'
    );
}

/**
 * Instantiate one covered skill with the corpus config + a capturing fake
 * agent, run its lifecycle, and return the list of tool contracts it registers.
 *
 * @param array{id: string, skill: string, config?: array<string, mixed>} $entry
 * @return list<array<string, mixed>>
 */
function contracts_for(array $entry): array
{
    $skillName = $entry['skill'];
    $config = $entry['config'] ?? [];

    $className = SkillRegistry::instance()->getFactory($skillName);
    if ($className === null || !class_exists($className)) {
        throw new \RuntimeException("no registered class for covered skill '{$skillName}'");
    }

    $agent = new CapturingAgent();
    /** @var \SignalWire\Skills\SkillBase $skill */
    $skill = new $className($agent, $config);

    if (!$skill->setup()) {
        throw new \RuntimeException(
            "skill '{$skillName}' setup() returned false with the corpus config "
            . '— config drift between the corpus and the port.'
        );
    }

    $skill->registerTools();

    return $agent->tools;
}

try {
    $corpus = load_corpus();

    $out = [];
    foreach ($corpus as $entry) {
        $out[$entry['id']] = contracts_for($entry);
    }

    $encoded = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        fwrite(STDERR, 'emit-skills: json_encode failed: ' . json_last_error_msg() . "\n");
        exit(1);
    }
    echo $encoded, "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'emit-skills: ' . $e->getMessage() . "\n");
    exit(1);
}
