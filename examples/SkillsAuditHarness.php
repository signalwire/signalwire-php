<?php

declare(strict_types=1);

/**
 * SkillsAuditHarness.php
 *
 * Drives one network skill end-to-end against the local HTTP fixture
 * spun up by porting-sdk/scripts/audit_skills_dispatch.py.
 *
 * Contract (per audit_skills_dispatch.py docstring):
 *   - Reads SKILL_NAME            (e.g. "web_search", "datasphere")
 *   - Reads SKILL_FIXTURE_URL     ("http://127.0.0.1:NNNN")
 *   - Reads SKILL_HANDLER_ARGS    JSON dict of args for the skill handler
 *   - Reads the per-skill upstream env var (e.g. WEB_SEARCH_BASE_URL,
 *     WIKIPEDIA_BASE_URL, DATASPHERE_BASE_URL, SPIDER_BASE_URL,
 *     API_NINJAS_BASE_URL, WEATHER_API_BASE_URL); the audit sets
 *     this to point the skill at its loopback fixture.
 *   - Reads per-skill credentials (GOOGLE_API_KEY / GOOGLE_CSE_ID /
 *     DATASPHERE_TOKEN / API_NINJAS_KEY / WEATHER_API_KEY) — fed
 *     into the skill's params so setup() validates.
 *
 * For handler-based skills (web_search, wikipedia_search, datasphere,
 * spider) the harness loads the skill, registers its tools on a
 * minimal AgentBase, and dispatches the documented tool name with
 * the parsed args. The skill issues real HTTP through HttpHelper.
 *
 * For DataMap-based skills (api_ninjas_trivia, weather_api), the
 * SignalWire platform — not the SDK — would normally fetch the
 * configured webhook URL. The harness simulates that platform by
 * extracting the webhook URL from the registered DataMap and
 * issuing the HTTP call itself, satisfying the audit's contract
 * that "the SDK contacted the upstream" via real bytes on the wire.
 *
 * Copyright (c) 2025 SignalWire
 * Licensed under the MIT License.
 */

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\Skills\HttpHelper;
use SignalWire\Skills\SkillRegistry;
use SignalWire\SWAIG\FunctionResult;

if (getenv('SIGNALWIRE_LOG_MODE') === false) {
    putenv('SIGNALWIRE_LOG_MODE=off');
}

$skillName = (string) getenv('SKILL_NAME');
$argsRaw = (string) getenv('SKILL_HANDLER_ARGS');

if ($skillName === '') {
    fwrite(STDERR, "SkillsAuditHarness: SKILL_NAME required.\n");
    exit(1);
}
$args = $argsRaw === '' ? [] : json_decode($argsRaw, true);
if (!is_array($args)) {
    fwrite(STDERR, "SkillsAuditHarness: SKILL_HANDLER_ARGS is not a JSON object.\n");
    exit(1);
}

// Per-skill setup parameters (mirroring what a deployed agent would
// pull from env / config). The audit sets the credential env vars
// listed in audit_skills_dispatch.py SKILL_PROBES.
$params = [];
switch ($skillName) {
    case 'web_search':
        $params['api_key'] = (string) getenv('GOOGLE_API_KEY');
        $params['search_engine_id'] = (string) getenv('GOOGLE_CSE_ID');
        break;

    case 'wikipedia_search':
        // No credentials required; WIKIPEDIA_BASE_URL drives fixture.
        break;

    case 'datasphere':
        $params['space_name'] = 'audit-space';
        $params['project_id'] = 'audit-project';
        $params['document_id'] = 'audit-doc';
        $params['token'] = (string) getenv('DATASPHERE_TOKEN');
        break;

    case 'spider':
        // No credentials required; SPIDER_BASE_URL drives fixture.
        break;

    case 'api_ninjas_trivia':
        $params['api_key'] = (string) getenv('API_NINJAS_KEY');
        break;

    case 'weather_api':
        $params['api_key'] = (string) getenv('WEATHER_API_KEY');
        break;

    default:
        fwrite(STDERR, "SkillsAuditHarness: unsupported skill '{$skillName}'\n");
        exit(2);
}

$registry = SkillRegistry::instance();
$factoryClass = $registry->getFactory($skillName);
if ($factoryClass === null) {
    fwrite(STDERR, "SkillsAuditHarness: skill '{$skillName}' not registered\n");
    exit(2);
}

// Build a minimal AgentBase to satisfy SkillBase->defineTool() (and
// the DataMap path's $this->agent->registerSwaigFunction()).
$agent = new AgentBase([
    'name' => 'skills-audit',
    'route' => '/audit',
]);

/** @var \SignalWire\Skills\SkillBase $skill */
$skill = new $factoryClass($agent, $params);
if (!$skill->setup()) {
    fwrite(STDERR, "SkillsAuditHarness: skill '{$skillName}' setup() returned false\n");
    exit(1);
}
$skill->registerTools();

// Dispatch table for handler-based skills (the skill's registered
// tool name → the `defineTool` handler signature) and the DataMap
// table for the platform-fetched ones.
$result = match ($skillName) {
    'web_search'        => dispatchHandler($agent, 'web_search', $args),
    'wikipedia_search'  => dispatchHandler($agent, 'search_wiki', $args),
    'datasphere'        => dispatchHandler($agent, 'search_knowledge', $args),
    'spider'            => dispatchHandler($agent, 'scrape_url', $args),
    'api_ninjas_trivia' => executeDataMap($agent, 'get_trivia', ensureCategory($args)),
    'weather_api'       => executeDataMap($agent, 'get_weather', $args),
    default             => null,
};

if ($result === null) {
    fwrite(STDERR, "SkillsAuditHarness: dispatch returned null for '{$skillName}'\n");
    exit(1);
}

echo json_encode($result) . "\n";
exit(0);

// ---------------------------------------------------------------------
//  Helpers
// ---------------------------------------------------------------------

/**
 * Dispatch a handler-based tool via the agent's SWAIG dispatcher. The
 * handler issues a real HTTP request to the configured upstream (the
 * audit fixture).
 */
function dispatchHandler(AgentBase $agent, string $toolName, array $args): mixed
{
    $rawData = ['call_id' => 'audit-call', 'global_data' => []];
    /** @var FunctionResult|null $fr */
    $fr = $agent->onFunctionCall($toolName, $args, $rawData);
    if ($fr === null) {
        return [
            'error' => "tool '{$toolName}' not registered or returned null",
        ];
    }
    return $fr->toArray();
}

/**
 * For DataMap-based tools, extract the webhook URL from the
 * registered DataMap config and execute it ourselves — that's what
 * the SignalWire platform does in production. The audit verifies
 * the URL shape and that the SDK parses the response.
 */
function executeDataMap(AgentBase $agent, string $toolName, array $args): mixed
{
    $tools = $agent->getTools();
    if (!isset($tools[$toolName])) {
        return ['error' => "tool '{$toolName}' not registered"];
    }
    $def = $tools[$toolName];
    $webhook = $def['data_map']['webhooks'][0] ?? null;
    if (!is_array($webhook) || empty($webhook['url'])) {
        return ['error' => "tool '{$toolName}' has no DataMap webhook"];
    }
    $template = (string) $webhook['url'];
    $method = strtoupper((string) ($webhook['method'] ?? 'GET'));
    $extraHeaders = is_array($webhook['headers'] ?? null) ? $webhook['headers'] : [];

    $url = expandTemplate($template, $args);

    // Honor the SKILL_FIXTURE_URL override the audit sets so the
    // platform-simulated GET hits the loopback fixture instead of
    // the real upstream. The audit fixture serves canned JSON for
    // any path so we can rewrite the host while preserving the
    // remaining URL shape.
    $fixtureUrl = (string) getenv('SKILL_FIXTURE_URL');
    if ($fixtureUrl !== '') {
        $parts = parse_url($url);
        if (is_array($parts) && !empty($parts['path'])) {
            $path = $parts['path'] ?? '';
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';
            $url = rtrim($fixtureUrl, '/') . $path . $query;
        }
    }

    try {
        if ($method === 'GET') {
            [$status, $body, $parsed] = HttpHelper::get(
                $url,
                headers: $extraHeaders,
                timeout: 10,
            );
        } else {
            [$status, $body, $parsed] = HttpHelper::request(
                $method,
                $url,
                headers: $extraHeaders,
                body: '',
                timeout: 10,
            );
        }
    } catch (\RuntimeException $e) {
        return ['error' => "HTTP {$method} {$url} failed: " . $e->getMessage()];
    }

    return [
        'status' => $status,
        'url' => $url,
        'body' => $parsed ?? $body,
    ];
}

/**
 * Naive %{args.field} template expansion for DataMap webhook URLs.
 * Matches the audit's contract: only %{args.X} is substituted, ${...}
 * SWML refs are left for the audit fixture to ignore.
 */
function expandTemplate(string $template, array $args): string
{
    return (string) preg_replace_callback(
        '/%\{args\.([a-zA-Z0-9_]+)\}/',
        function (array $m) use ($args): string {
            $key = $m[1];
            $value = $args[$key] ?? '';
            return is_scalar($value) ? (string) $value : json_encode($value);
        },
        $template,
    );
}

/**
 * The api_ninjas_trivia skill requires `category` but the audit
 * doesn't pass one. Inject a default so the URL template expands
 * to a real path the fixture sees.
 */
function ensureCategory(array $args): array
{
    if (!isset($args['category']) || (string) $args['category'] === '') {
        $args['category'] = 'general';
    }
    return $args;
}
