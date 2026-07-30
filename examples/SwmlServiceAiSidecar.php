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
 *     POST /sales-sidecar/events  → Optional sidecar lifecycle/transcription
 *                                   observer (logs the event, then serves the
 *                                   SWML document — see the callback below)
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
 * Emits an `ai_sidecar` verb, registers a SWAIG tool the sidecar's LLM can
 * call, and mounts an event-sink routing callback.
 */
function buildAiSidecarService(string $publicUrl = 'https://your-host.example.com/sales-sidecar'): Service
{
    $svc = new Service(
        name:  'sales-sidecar',
        route: '/sales-sidecar',
        host:  '0.0.0.0',
        port:  (int) (getenv('PORT') ?: 3000),
    );

    // 1. Emit any SWML — including ai_sidecar — through Service::addVerb, the
    //    validating entry point. `ai_sidecar` is in the schema, so this config
    //    is checked at build time; going around the Service via getDocument()
    //    would skip that check and let a typo'd key ship silently.
    $svc->answer();
    $svc->addVerb('ai_sidecar', [
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

    // 3. Optional: observe ai_sidecar lifecycle events at
    //    POST /sales-sidecar/events. mod_openai POSTs each event as JSON.
    //
    //    A routing callback is `(body, headers): ?string`. Returning a non-empty
    //    string redirects (307) to that route; returning null means "handled
    //    here", and the service answers with its SWML document. There is no
    //    return value that produces a custom JSON ack — so this logs the event
    //    and returns null, which is what the endpoint genuinely does.
    $svc->registerRoutingCallback(function (array $body, array $headers): ?string {
        $type = $body['type'] ?? '<unknown>';
        $type = is_string($type) ? $type : '<unknown>';
        fwrite(STDERR, "[sidecar event] type={$type} body=" . json_encode($body) . "\n");

        return null;
    }, path: '/events');

    return $svc;
}

// Top-level entry point. Two SAPI cases must run the service:
//   1. CLI ("php examples/SwmlServiceAiSidecar.php") — start the built-in
//      server, which re-invokes this file under cli-server.
//   2. cli-server ("php -S host:port examples/SwmlServiceAiSidecar.php") —
//      the built-in server loaded this file as a router; build the
//      service and dispatch the inbound request via Service::run().
$isCliEntrypoint = PHP_SAPI === 'cli'
    && is_array($_SERVER['argv'] ?? null)
    && is_string($_SERVER['argv'][0] ?? null)
    && \realpath($_SERVER['argv'][0]) === __FILE__;
$isCliServer = PHP_SAPI === 'cli-server';
if ($isCliEntrypoint || $isCliServer) {
    $publicUrl = getenv('PUBLIC_URL') ?: 'https://your-host.example.com/sales-sidecar';
    $service = buildAiSidecarService($publicUrl);
    if ($isCliEntrypoint) {
        [$user, $pass] = $service->getBasicAuthCredentials();
        $url = $service->getFullUrl();
        \fwrite(STDOUT, "AI sidecar service listening at {$url}\n");
        \fwrite(STDOUT, "Basic-auth user: {$user}\n");
        \fwrite(STDOUT, "Basic-auth pass: {$pass}\n");
    }
    $service->run();
}
