<?php

declare(strict_types=1);

/**
 * SwmlServiceSwaigStandalone.php
 *
 * Proves that SignalWire\SWML\Service — by itself, with NO AgentBase — can
 * host SWAIG functions and serve them on its own /swaig endpoint.
 *
 * This is the path you take when you want a SWAIG-callable HTTP service that
 * isn't an `<ai>` agent: the SWAIG verb is a generic LLM-tool surface and
 * SWML\Service is the host. AgentBase is just an SWML\Service subclass that
 * also layers in prompts, AI config, dynamic config, and token validation.
 *
 * Run:
 *     php examples/SwmlServiceSwaigStandalone.php
 *
 * Then exercise the endpoints (credentials are printed when the server
 * starts, or override via SWML_BASIC_AUTH_USER / SWML_BASIC_AUTH_PASSWORD):
 *
 *     curl -u user:pass http://localhost:3000/standalone
 *     curl -u user:pass http://localhost:3000/standalone/swaig \
 *         -H 'Content-Type: application/json' \
 *         -d '{"function":"lookup_competitor","argument":{"parsed":[{"competitor":"ACME"}]}}'
 *
 * Drive it through the SDK CLI without standing up a separate process:
 *     bin/swaig-test --url http://user:pass@localhost:3000/standalone --list-tools
 *     bin/swaig-test --url http://user:pass@localhost:3000/standalone \
 *         --exec lookup_competitor --param competitor=ACME
 *
 * Copyright (c) 2025 SignalWire
 * Licensed under the MIT License.
 */

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\SWAIG\FunctionResult;
use SignalWire\SWML\Service;

/**
 * Build the standalone SWAIG-hosting service.
 *
 * Uses SignalWire\SWML\Service directly — no AgentBase. The SWML doc is
 * minimal (answer + hangup); the SWAIG surface is independent of the doc
 * contents and lives on the inherited /swaig endpoint.
 */
function buildStandaloneSwaigService(): Service
{
    $svc = new Service([
        'name'  => 'standalone-swaig',
        'route' => '/standalone',
        'host'  => '0.0.0.0',
        'port'  => (int) (getenv('PORT') ?: 3000),
    ]);

    // 1. Build a minimal SWML document. Any verbs are fine — the SWAIG
    //    HTTP surface is independent of what the document contains.
    $svc->answer();
    $svc->hangup();

    // 2. Register a SWAIG function. defineTool() lives on SWML\Service,
    //    not just AgentBase. The handler receives parsed arguments plus
    //    the raw POST body and may return a FunctionResult or an array.
    $svc->defineTool(
        name: 'lookup_competitor',
        description: 'Look up competitor pricing by company name. Use this '
            . "when the user asks how a competitor's price compares to ours.",
        parameters: [
            'competitor' => [
                'type' => 'string',
                'description' => "The competitor's company name, e.g. 'ACME'.",
            ],
        ],
        handler: function (array $args, array $rawData): FunctionResult {
            $competitor = $args['competitor'] ?? '<unknown>';
            return new FunctionResult(
                "{$competitor} pricing is \$99/seat; we're \$79/seat."
            );
        },
        secure: false, // standalone services don't validate session tokens by default
    );

    return $svc;
}

// Top-level entry point: only run the server when invoked directly,
// so the file can also be require()'d by tests or other harnesses.
if (PHP_SAPI === 'cli'
    && isset($_SERVER['argv'][0])
    && realpath($_SERVER['argv'][0]) === __FILE__
) {
    $service = buildStandaloneSwaigService();
    [$user, $pass] = $service->getBasicAuthCredentials();
    $url = $service->getFullUrl();
    fwrite(STDOUT, "Standalone SWAIG service listening at {$url}\n");
    fwrite(STDOUT, "Basic-auth user: {$user}\n");
    fwrite(STDOUT, "Basic-auth pass: {$pass}\n");
    $service->run();
}
