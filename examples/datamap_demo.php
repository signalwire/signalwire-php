<?php
/**
 * DataMap Demo - Shows how to use the DataMap class for server-side tools
 *
 * This demo creates an agent with data_map tools:
 * 1. Simple API call (weather)
 * 2. Expression-based pattern matching
 * These tools execute on SignalWire's servers, no webhook needed.
 */

require 'vendor/autoload.php';

use SignalWire\Agent\AgentBase;
use SignalWire\DataMap\DataMap;
use SignalWire\SWAIG\FunctionResult;

$agent = new AgentBase(
    name:  'datamap-demo',
    route: '/datamap-demo',
);

$agent->promptAddSection(
    'Role',
    'You are a helpful assistant with access to weather data and file playback control.',
);

// 1. Simple weather API via DataMap
$weather = (new DataMap('get_weather'))
    ->description('Get weather for a location')
    ->parameter('location', 'string', 'City name or location', required: true)
    ->webhook('GET', 'https://api.weather.com/v1/current?key=API_KEY&q=${args.location}')
    ->output(new FunctionResult(
        'Current weather in ${args.location}: ${response.current.condition.text}, ${response.current.temp_f}F'
    ));

$agent->registerSwaigFunction($weather->toSwaigFunction());

// 2. Expression-based file control (no API calls)
$fileControl = (new DataMap('file_control'))
    ->description('Control audio/video playback')
    ->parameter('command', 'string', 'Playback command', required: true,
        enum: ['play', 'pause', 'stop', 'next', 'previous'])
    ->expression(
        '${args.command}',
        'play|resume',
        new FunctionResult('Playback started'),
        nomatchOutput: new FunctionResult('Playback stopped'),
    );

$agent->registerSwaigFunction($fileControl->toSwaigFunction());

// 3. Regular SWAIG function for comparison
$agent->defineTool(
    name:        'echo_test',
    description: 'A simple echo function for testing',
    parameters:  [
        'type' => 'object',
        'properties' => [
            'message' => ['type' => 'string', 'description' => 'Message to echo back'],
        ],
    ],
    handler: function (array $args, array $raw): FunctionResult {
        $msg = $args['message'] ?? 'nothing';
        return new FunctionResult("Echo: {$msg}");
    },
);

$agent->addLanguage(name: 'English', code: 'en-US', voice: 'inworld.Mark');
$agent->setParams(['ai_model' => 'gpt-4.1-nano']);

echo "Starting DataMap Demo Agent\n";
echo "Available at: http://localhost:3000/datamap-demo\n";

$agent->run();
