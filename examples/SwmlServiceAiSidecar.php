<?php

declare(strict_types=1);

/**
 * SwmlServiceAiSidecar.php
 *
 * Proves that SignalWire\SWML\Service can emit the `ai_sidecar` verb,
 * register SWAIG tools the sidecar's LLM can call, and dispatch them
 * end-to-end — without any AgentBase code path.
 *
 * The `ai_sidecar` verb runs an AI listener alongside an in-progress call
 * (real-time copilot, transcription analyzer, compliance monitor, etc.).
 * It is NOT an agent — it does not own the call. So the right host is
 * SWML\Service, not AgentBase.
 *
 * Run:
 *     php examples/SwmlServiceAiSidecar.php
 *
 * What this serves:
 *     GET  /sales-sidecar         → SWML doc with the ai_sidecar verb
 *     POST /sales-sidecar/swaig   → SWAIG tool dispatch (used by the sidecar's LLM)
 *     POST /sales-sidecar/events  → Optional sidecar lifecycle/transcription sink
 *
 * Drive the SWAIG path through the SDK CLI:
 *     bin/swaig-test --url http://user:pass@localhost:3000/sales-sidecar --list-tools
 *     bin/swaig-test --url http://user:pass@localhost:3000/sales-sidecar \
 *         --exec lookup_competitor --param competitor=ACME
 *
 * Copyright (c) 2025 SignalWire
 * Licensed under the MIT License.
 */

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\SWAIG\FunctionResult;
use SignalWire\SWML\Service;

/**
 * Build the AI-sidecar SWMLService.
 *
 * Emits an `ai_sidecar` verb (via the document's addVerbToSection, since
 * `ai_sidecar` is not in the schema yet), registers a SWAIG tool the
 * sidecar's LLM can call, and mounts an event-sink routing callback.
 */
function buildAiSidecarService(string $publicUrl = 'https://your-host.example.com/sales-sidecar'): Service
{
    $svc = new Service([
        'name'  => 'sales-sidecar',
        'route' => '/sales-sidecar',
        'host'  => '0.0.0.0',
        'port'  => (int) (getenv('PORT') ?: 3000),
    ]);

    // 1. Emit any SWML — including ai_sidecar. SWML\Service exposes the
    //    underlying Document so callers can drop in arbitrary verb hashes
    //    even before the schema lists a new platform verb.
    $svc->answer();
    $svc->getDocument()->addVerbToSection('main', 'ai_sidecar', [
        // Required: prompt + lang.
        'prompt' => 'You are a real-time sales copilot. Listen to the call '
            . 'and surface competitor pricing comparisons when relevant.',
        'lang'   => 'en-US',
        // Required by spec: which leg(s) the sidecar listens to.
        'direction' => ['remote-caller', 'local-caller'],
        // Optional: where the sidecar POSTs lifecycle/transcription events.
        'url' => "{$publicUrl}/events",
        // Where the sidecar's LLM POSTs SWAIG tool calls. Note the
        // UPPERCASE "SWAIG" key per the platform schema.
        'SWAIG' => [
            'defaults' => [
                'web_hook_url' => "{$publicUrl}/swaig",
            ],
        ],
    ]);
    $svc->hangup();

    // 2. Register tools the sidecar's LLM can call. Same defineTool() you'd
    //    use on AgentBase — it lives on SWML\Service.
    $svc->defineTool(
        name: 'lookup_competitor',
        description: 'Look up competitor pricing by company name. The sidecar '
            . 'should call this whenever the caller mentions a competitor.',
        parameters: [
            'competitor' => [
                'type' => 'string',
                'description' => "The competitor's company name, e.g. 'ACME'.",
            ],
        ],
        handler: function (array $args, array $rawData): FunctionResult {
            $competitor = $args['competitor'] ?? '<unknown>';
            return new FunctionResult(
                "Pricing for {$competitor}: \$99/seat. Our equivalent plan is "
                . '$79/seat with the same SLA.'
            );
        },
        secure: false,
    );

    // 3. Optional: mount an event sink for ai_sidecar lifecycle events at
    //    POST /sales-sidecar/events. mod_openai POSTs each event as JSON.
    $svc->registerRoutingCallback('/events', function (?array $body, array $headers): array {
        $type = is_array($body) ? ($body['type'] ?? '<unknown>') : '<unknown>';
        fwrite(STDERR, "[sidecar event] type={$type} body=" . json_encode($body) . "\n");
        return ['ok' => true];
    });

    return $svc;
}

// Top-level entry point: only run the server when invoked directly,
// so the file can also be require()'d by tests or other harnesses.
if (PHP_SAPI === 'cli'
    && isset($_SERVER['argv'][0])
    && realpath($_SERVER['argv'][0]) === __FILE__
) {
    $publicUrl = getenv('PUBLIC_URL') ?: 'https://your-host.example.com/sales-sidecar';
    $service = buildAiSidecarService($publicUrl);
    [$user, $pass] = $service->getBasicAuthCredentials();
    $url = $service->getFullUrl();
    fwrite(STDOUT, "AI sidecar service listening at {$url}\n");
    fwrite(STDOUT, "Basic-auth user: {$user}\n");
    fwrite(STDOUT, "Basic-auth pass: {$pass}\n");
    $service->run();
}
