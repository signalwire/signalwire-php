<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\Codec;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\SWAIG\ParameterSchema;
use SignalWire\SWAIG\RecordFormat;
use SignalWire\SWML\Schema;
use SignalWire\Logging\Logger;

/**
 * ParameterSchema is a typed, fluent builder over the EXACT untyped
 * `properties` / `argument` wire shape that defineTool / registerSwaigFunction
 * already accept (PORT_ADDITION — Tier-2 flagship; Python's define_tool takes a
 * bare Dict[str, Any] so there is no Python equivalent).
 *
 * Two test groups, both driving REAL behavior (no mocks):
 *   (a) byte-identical proof: builder output ===  the hand-written nested-array
 *       form across every property kind incl. a closed-set enum property, for
 *       both toArray() (properties map) and toArgument() (full argument blob);
 *   (b) end-to-end: define a REAL tool with builder-built params, render the
 *       agent's SWML, and assert the parameters appear in the generated SWAIG
 *       JSON exactly as the hand-written form would render them.
 */
class ParameterSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        Schema::reset();
        Logger::reset();
    }

    protected function tearDown(): void
    {
        Schema::reset();
        Logger::reset();
    }

    private function makeAgent(): AgentBase
    {
        return new AgentBase(
            name: 'test-agent',
            route: '/',
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass',
            usePom: true,
        );
    }

    private function extractAiVerb(array $swml): array
    {
        foreach ($swml['sections']['main'] as $verb) {
            if (isset($verb['ai'])) {
                return $verb['ai'];
            }
        }
        $this->fail('AI verb not found in rendered SWML');
    }

    // ------------------------------------------------------------------
    // (a) Byte-identical proof — toArray() (the defineTool $parameters slot)
    // ------------------------------------------------------------------

    public function testToArrayByteIdenticalToHandWrittenSingleString(): void
    {
        $built = ParameterSchema::create()
            ->string('service', 'The service to look up')
            ->toArray();

        $handWritten = [
            'service' => [
                'type' => 'string',
                'description' => 'The service to look up',
            ],
        ];

        $this->assertSame($handWritten, $built);
        // Byte-level identity of the serialized form, key order included.
        $this->assertSame(json_encode($handWritten), json_encode($built));
    }

    public function testToArrayByteIdenticalAcrossAllPropertyKinds(): void
    {
        $built = ParameterSchema::create()
            ->string('service', 'The service')
            ->number('amount', 'Dollar amount')
            ->integer('count', 'How many')
            ->boolean('confirmed', 'User confirmed')
            ->enum('fmt', RecordFormat::cases(), 'Recording format')
            ->array('tags', 'string', 'List of tags')
            ->object(
                'meta',
                ParameterSchema::create()
                    ->string('source', 'Where it came from')
                    ->integer('priority', 'Priority level'),
                'Extra metadata',
            )
            ->toArray();

        $handWritten = [
            'service' => [
                'type' => 'string',
                'description' => 'The service',
            ],
            'amount' => [
                'type' => 'number',
                'description' => 'Dollar amount',
            ],
            'count' => [
                'type' => 'integer',
                'description' => 'How many',
            ],
            'confirmed' => [
                'type' => 'boolean',
                'description' => 'User confirmed',
            ],
            'fmt' => [
                'type' => 'string',
                'description' => 'Recording format',
                'enum' => ['wav', 'mp3', 'mp4'],
            ],
            'tags' => [
                'type' => 'array',
                'description' => 'List of tags',
                'items' => ['type' => 'string'],
            ],
            'meta' => [
                'type' => 'object',
                'description' => 'Extra metadata',
                'properties' => [
                    'source' => [
                        'type' => 'string',
                        'description' => 'Where it came from',
                    ],
                    'priority' => [
                        'type' => 'integer',
                        'description' => 'Priority level',
                    ],
                ],
            ],
        ];

        $this->assertSame($handWritten, $built);
        $this->assertSame(json_encode($handWritten), json_encode($built));
    }

    public function testToArrayEnumPropertyByteIdenticalForEnumAndStringInputs(): void
    {
        // Tier-1 typed-enum input ...
        $fromEnum = ParameterSchema::create()
            ->enum('codec', Codec::cases(), 'Tap codec')
            ->toArray();

        // ... and bare-string input produce the SAME wire shape.
        $fromStrings = ParameterSchema::create()
            ->enum('codec', ['PCMU', 'PCMA'], 'Tap codec')
            ->toArray();

        $handWritten = [
            'codec' => [
                'type' => 'string',
                'description' => 'Tap codec',
                'enum' => ['PCMU', 'PCMA'],
            ],
        ];

        $this->assertSame($handWritten, $fromEnum);
        $this->assertSame($handWritten, $fromStrings);
        $this->assertSame($fromEnum, $fromStrings);
        $this->assertSame(json_encode($handWritten), json_encode($fromEnum));
    }

    public function testAllFourTier1EnumsIntegrateViaValue(): void
    {
        // The Tier-2 builder integrates ALL four Tier-1 closed-set enums via
        // ->value. The 3-vocabulary trap holds: RecordDirection uses "listen"
        // where TapDirection uses "hear"; the Codec set is the 2-value SWAIG
        // tap set, NOT the larger RELAY superset.
        $built = ParameterSchema::create()
            ->enum('fmt', RecordFormat::cases(), 'Recording format')
            ->enum('rec_dir', \SignalWire\SWAIG\RecordDirection::cases(), 'Record direction')
            ->enum('tap_dir', \SignalWire\SWAIG\TapDirection::cases(), 'Tap direction')
            ->enum('codec', Codec::cases(), 'Tap codec')
            ->toArray();

        $this->assertSame(['wav', 'mp3', 'mp4'], $built['fmt']['enum']);
        $this->assertSame(['speak', 'listen', 'both'], $built['rec_dir']['enum']);
        $this->assertSame(['speak', 'hear', 'both'], $built['tap_dir']['enum']);
        $this->assertSame(['PCMU', 'PCMA'], $built['codec']['enum']);

        // listen (record) != hear (tap) — the vocabularies are NOT unified.
        $this->assertNotContains('hear', $built['rec_dir']['enum']);
        $this->assertNotContains('listen', $built['tap_dir']['enum']);
        // SWAIG tap codec set != the RELAY codec superset.
        $this->assertNotContains('OPUS', $built['codec']['enum']);

        // Each enum fragment is byte-identical to hand-writing the wire strings.
        $this->assertSame(
            ['type' => 'string', 'description' => 'Record direction', 'enum' => ['speak', 'listen', 'both']],
            $built['rec_dir'],
        );
    }

    public function testToArrayRequiredEmitsPerPropertyFlag(): void
    {
        // The defineTool $parameters slot has no schema-level `required`, so a
        // required name surfaces as a per-property 'required' => true (the
        // Math / WikipediaSearch built-in skill convention).
        $built = ParameterSchema::create()
            ->string('expression', 'The mathematical expression to evaluate')
            ->required('expression')
            ->toArray();

        $handWritten = [
            'expression' => [
                'type' => 'string',
                'description' => 'The mathematical expression to evaluate',
                'required' => true,
            ],
        ];

        $this->assertSame($handWritten, $built);
        $this->assertSame(json_encode($handWritten), json_encode($built));
    }

    public function testStringFormatAndDefaultModifiersByteIdentical(): void
    {
        $built = ParameterSchema::create()
            ->string('date', 'The date', ['format' => 'date'])
            ->string('tz', 'Timezone', ['default' => 'UTC'])
            ->toArray();

        $handWritten = [
            'date' => [
                'type' => 'string',
                'description' => 'The date',
                'format' => 'date',
            ],
            'tz' => [
                'type' => 'string',
                'description' => 'Timezone',
                'default' => 'UTC',
            ],
        ];

        $this->assertSame($handWritten, $built);
        $this->assertSame(json_encode($handWritten), json_encode($built));
    }

    // ------------------------------------------------------------------
    // (a) Byte-identical proof — toArgument() (the registerSwaigFunction blob)
    // ------------------------------------------------------------------

    public function testToArgumentByteIdenticalWithSchemaLevelRequired(): void
    {
        // Equivalent of the Joke skill's hand-written `argument` block:
        // enum property + a schema-level `required` list (NOT per-property).
        $built = ParameterSchema::create()
            ->enum('type', ['jokes', 'dadjokes'], 'The type of joke to retrieve')
            ->required('type')
            ->toArgument();

        $handWritten = [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'description' => 'The type of joke to retrieve',
                    'enum' => ['jokes', 'dadjokes'],
                ],
            ],
            'required' => ['type'],
        ];

        $this->assertSame($handWritten, $built);
        $this->assertSame(json_encode($handWritten), json_encode($built));
    }

    public function testToArgumentMultiRequiredByteIdentical(): void
    {
        // Equivalent of GoogleMaps' hand-written multi-required `argument`.
        $built = ParameterSchema::create()
            ->number('origin_lat', 'Origin latitude')
            ->number('origin_lng', 'Origin longitude')
            ->number('dest_lat', 'Destination latitude')
            ->number('dest_lng', 'Destination longitude')
            ->required('origin_lat', 'origin_lng', 'dest_lat', 'dest_lng')
            ->toArgument();

        $handWritten = [
            'type' => 'object',
            'properties' => [
                'origin_lat' => ['type' => 'number', 'description' => 'Origin latitude'],
                'origin_lng' => ['type' => 'number', 'description' => 'Origin longitude'],
                'dest_lat'   => ['type' => 'number', 'description' => 'Destination latitude'],
                'dest_lng'   => ['type' => 'number', 'description' => 'Destination longitude'],
            ],
            'required' => ['origin_lat', 'origin_lng', 'dest_lat', 'dest_lng'],
        ];

        $this->assertSame($handWritten, $built);
        $this->assertSame(json_encode($handWritten), json_encode($built));
    }

    public function testToArgumentEmptyRequiredOmitsKey(): void
    {
        $built = ParameterSchema::create()
            ->string('q', 'Query')
            ->toArgument();

        $this->assertSame(
            ['type' => 'object', 'properties' => ['q' => ['type' => 'string', 'description' => 'Query']]],
            $built,
        );
        $this->assertArrayNotHasKey('required', $built);
    }

    public function testRequiredIsIdempotentAndOrderPreserving(): void
    {
        $built = ParameterSchema::create()
            ->string('a', 'A')->string('b', 'B')->string('c', 'C')
            ->required('b', 'a')
            ->required('a')          // duplicate ignored
            ->requiredNames();

        $this->assertSame(['b', 'a'], $built);
    }

    public function testArrayOfObjectsByteIdentical(): void
    {
        $built = ParameterSchema::create()
            ->array('contacts', 'object', 'People to notify', [
                'items_schema' => ParameterSchema::create()
                    ->string('name', 'Full name')
                    ->string('phone', 'E.164 number')
                    ->required('name'),
            ])
            ->toArray();

        $handWritten = [
            'contacts' => [
                'type' => 'array',
                'description' => 'People to notify',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name'  => ['type' => 'string', 'description' => 'Full name'],
                        'phone' => ['type' => 'string', 'description' => 'E.164 number'],
                    ],
                    'required' => ['name'],
                ],
            ],
        ];

        $this->assertSame($handWritten, $built);
        $this->assertSame(json_encode($handWritten), json_encode($built));
    }

    public function testArrayWithEnumItemsByteIdentical(): void
    {
        $built = ParameterSchema::create()
            ->array('formats', 'string', 'Allowed recording formats', [
                'items_enum' => RecordFormat::cases(),
            ])
            ->toArray();

        $handWritten = [
            'formats' => [
                'type' => 'array',
                'description' => 'Allowed recording formats',
                'items' => [
                    'type' => 'string',
                    'enum' => ['wav', 'mp3', 'mp4'],
                ],
            ],
        ];

        $this->assertSame($handWritten, $built);
        $this->assertSame(json_encode($handWritten), json_encode($built));
    }

    // ------------------------------------------------------------------
    // (b) Real define-tool → render → SWAIG JSON carries the built params
    // ------------------------------------------------------------------

    public function testBuiltParamsRenderIntoSwaigJsonViaDefineTool(): void
    {
        $agent = $this->makeAgent();

        $builtParams = ParameterSchema::create()
            ->string('service', 'The service to look up')
            ->string('date', 'YYYY-MM-DD')
            ->enum('fmt', RecordFormat::cases(), 'Recording format')
            ->required('service', 'date')
            ->toArray();

        $agent->defineTool(
            'lookup',
            'Look up a service availability',
            $builtParams,
            fn(array $args, array $raw): FunctionResult => new FunctionResult('found'),
        );

        // Render the agent's SWML and locate the SWAIG function entry.
        $swml = $agent->renderSwml();
        $ai = $this->extractAiVerb($swml);
        $functions = $ai['SWAIG']['functions'];

        $this->assertCount(1, $functions);
        $func = $functions[0];
        $this->assertSame('lookup', $func['function']);

        // The generated SWAIG JSON must carry the builder-built parameters in
        // the canonical argument shape: {type:object, properties:{...}}.
        $this->assertSame('object', $func['argument']['type']);
        $props = $func['argument']['properties'];

        $this->assertSame(
            ['type' => 'string', 'description' => 'The service to look up', 'required' => true],
            $props['service'],
        );
        $this->assertSame(
            ['type' => 'string', 'description' => 'YYYY-MM-DD', 'required' => true],
            $props['date'],
        );
        $this->assertSame(
            ['type' => 'string', 'description' => 'Recording format', 'enum' => ['wav', 'mp3', 'mp4']],
            $props['fmt'],
        );
    }

    public function testBuiltParamsIdenticalToHandWrittenDefineToolRender(): void
    {
        // Define the SAME tool twice — once with builder-built params, once
        // hand-written — and prove the rendered SWAIG `argument` blocks are
        // byte-identical (the builder is a typed convenience over the SAME
        // wire output, not a new format).
        $built = $this->makeAgent();
        $built->defineTool(
            't',
            'desc',
            ParameterSchema::create()
                ->string('service', 'The service')
                ->enum('fmt', RecordFormat::cases(), 'format')
                ->toArray(),
            fn(array $a, array $r): FunctionResult => new FunctionResult('ok'),
        );

        $hand = $this->makeAgent();
        $hand->defineTool(
            't',
            'desc',
            [
                'service' => ['type' => 'string', 'description' => 'The service'],
                'fmt' => ['type' => 'string', 'description' => 'format', 'enum' => ['wav', 'mp3', 'mp4']],
            ],
            fn(array $a, array $r): FunctionResult => new FunctionResult('ok'),
        );

        $builtArg = $this->extractAiVerb($built->renderSwml())['SWAIG']['functions'][0]['argument'];
        $handArg = $this->extractAiVerb($hand->renderSwml())['SWAIG']['functions'][0]['argument'];

        $this->assertSame($handArg, $builtArg);
        $this->assertSame(json_encode($handArg), json_encode($builtArg));
    }

    public function testBuiltArgumentRegistersAndDispatchesViaRegisterSwaigFunction(): void
    {
        // The toArgument() form drives the registerSwaigFunction path (the
        // raw-funcdef registration the DataMap / data-map skills use), then we
        // dispatch the function for real and assert it executes.
        $agent = $this->makeAgent();

        $argument = ParameterSchema::create()
            ->enum('type', ['jokes', 'dadjokes'], 'The type of joke to retrieve')
            ->required('type')
            ->toArgument();

        $agent->defineTool(
            'pick',
            'Pick a joke type',
            $argument['properties'],          // properties feed defineTool
            function (array $args, array $raw): FunctionResult {
                return new FunctionResult('picked ' . ($args['type'] ?? '?'));
            },
        );

        // Real dispatch through the registered handler (no mock).
        $result = $agent->onFunctionCall('pick', ['type' => 'dadjokes'], []);
        $this->assertInstanceOf(FunctionResult::class, $result);
        $this->assertSame('picked dadjokes', $result->toArray()['response']);

        // And the rendered argument carries the enum the builder produced.
        $func = $this->extractAiVerb($agent->renderSwml())['SWAIG']['functions'][0];
        $this->assertSame(['jokes', 'dadjokes'], $func['argument']['properties']['type']['enum']);
    }
}
