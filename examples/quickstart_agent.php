<?php

/**
 * Quickstart: the minimal AI agent from the top-level README.
 *
 * A self-contained microservice that generates SWML and handles SWAIG tool
 * calls. Run it with `php quickstart_agent.php`, or test it without a server
 * via `vendor/bin/swaig-test --file quickstart_agent.php --list-tools`.
 *
 * The `construct` region below is included verbatim into README.md by the
 * readme-include gate — the doc code IS this compiled code, asserted
 * byte-identical at gate time.
 */

// region: construct
require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

$agent = new AgentBase(name: 'my-agent', route: '/agent');

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->promptAddSection('Role', 'You are a helpful assistant.');

$agent->defineTool(
    name:        'get_time',
    description: 'Get the current time',
    parameters:  ['type' => 'object', 'properties' => []],
    handler: function (array $args, array $rawData): FunctionResult {
        return new FunctionResult('The time is ' . date('H:i:s'));
    },
);

$agent->run();
// endregion: construct
