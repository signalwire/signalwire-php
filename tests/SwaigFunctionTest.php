<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\SWAIG\SwaigFunction;
use SignalWire\Tests\Support\Shape;

/**
 * Behavioral parity tests for SwaigFunction. Mirrors the Python reference
 * (core/swaig_function.py) and the TS oracle (SwaigFunction.ts): execute
 * round-trip, to_swaig serialization, validate_args, ensureParameterStructure.
 */
class SwaigFunctionTest extends TestCase
{
    public function testExecuteReturnsFunctionResultDict(): void
    {
        $fn = new SwaigFunction(
            name: 'get_time',
            handler: fn (array $args, array $raw) => new FunctionResult('It is noon.'),
            description: 'Return the time',
        );
        $result = $fn->execute(['tz' => 'UTC'], ['call_id' => 'x']);
        $this->assertSame(['response' => 'It is noon.'], $result);
    }

    public function testExecutePassesArgsAndRawDataToHandler(): void
    {
        $seen = [];
        $fn = new SwaigFunction(
            name: 'echo',
            handler: function (array $args, array $raw) use (&$seen) {
                $seen = [$args, $raw];
                return new FunctionResult('ok');
            },
            description: 'echo',
        );
        $fn->execute(['a' => 1], ['call_id' => 'c1']);
        $this->assertSame([['a' => 1], ['call_id' => 'c1']], $seen);
    }

    public function testExecuteWrapsPlainDictWithoutResponse(): void
    {
        $fn = new SwaigFunction(
            name: 't',
            handler: fn (array $a, array $r) => ['foo' => 'bar'],
            description: 'd',
        );
        $this->assertSame(['response' => 'Function completed successfully'], $fn->execute([], []));
    }

    public function testExecutePassesThroughDictWithResponseKey(): void
    {
        $fn = new SwaigFunction(
            name: 't',
            handler: fn (array $a, array $r) => ['response' => 'raw passthrough', 'action' => []],
            description: 'd',
        );
        $this->assertSame(['response' => 'raw passthrough', 'action' => []], $fn->execute([], []));
    }

    public function testExecuteContainsThrownHandler(): void
    {
        $fn = new SwaigFunction(
            name: 'boom',
            handler: function (array $a, array $r): FunctionResult {
                throw new \RuntimeException('kaboom');
            },
            description: 'd',
        );
        $result = $fn->execute([], []);
        // Error is contained — the AI sees a generic recovery message, never the exception.
        $response = $result['response'] ?? null;
        $this->assertIsString($response);
        $this->assertStringContainsString("couldn't complete that action", $response);
    }

    public function testToSwaigBuildsDefinition(): void
    {
        $fn = new SwaigFunction(
            name: 'lookup',
            handler: fn (array $a, array $r) => new FunctionResult('x'),
            description: 'Look something up',
            parameters: ['query' => ['type' => 'string', 'description' => 'the query']],
            required: ['query'],
        );
        $def = $fn->toSwaig('https://agent.example.com');

        $this->assertSame('lookup', $def['function']);
        $this->assertSame('Look something up', $def['description']);
        $this->assertSame('https://agent.example.com/swaig', $def['web_hook_url']);
        $this->assertSame('object', Shape::at($def, 'parameters', 'type'));
        $this->assertArrayHasKey('query', Shape::sub($def, 'parameters', 'properties'));
        $this->assertSame(['query'], Shape::at($def, 'parameters', 'required'));
    }

    public function testToSwaigAppendsTokenAndCallId(): void
    {
        $fn = new SwaigFunction(
            name: 't',
            handler: fn (array $a, array $r) => new FunctionResult('x'),
            description: 'd',
        );
        $def = $fn->toSwaig('https://a.example.com', token: 'TOK', callId: 'CID');
        $this->assertSame('https://a.example.com/swaig?token=TOK&call_id=CID', $def['web_hook_url']);
    }

    public function testToSwaigMergesExtraFields(): void
    {
        $fn = new SwaigFunction(
            name: 't',
            handler: fn (array $a, array $r) => new FunctionResult('x'),
            description: 'd',
            extraFields: ['meta_data_token' => 'abc'],
        );
        $def = $fn->toSwaig('https://a.example.com');
        $this->assertSame('abc', $def['meta_data_token']);
    }

    public function testValidateArgsEmptySchemaIsValid(): void
    {
        $fn = new SwaigFunction(
            name: 't',
            handler: fn (array $a, array $r) => new FunctionResult('x'),
            description: 'd',
        );
        $this->assertSame([true, []], $fn->validateArgs(['anything' => 1]));
    }

    public function testValidateArgsRequiredAndType(): void
    {
        $fn = new SwaigFunction(
            name: 't',
            handler: fn (array $a, array $r) => new FunctionResult('x'),
            description: 'd',
            parameters: [
                'city' => ['type' => 'string'],
                'days' => ['type' => 'integer'],
            ],
            required: ['city'],
        );

        [$ok, $errors] = $fn->validateArgs(['city' => 'Denver', 'days' => 3]);
        $this->assertTrue($ok);
        $this->assertSame([], $errors);

        [$ok2, $errors2] = $fn->validateArgs(['days' => 'not-an-int']);
        $this->assertFalse($ok2);
        $this->assertNotEmpty($errors2);
    }

    public function testValidateArgsEnum(): void
    {
        $fn = new SwaigFunction(
            name: 't',
            handler: fn (array $a, array $r) => new FunctionResult('x'),
            description: 'd',
            parameters: ['size' => ['type' => 'string', 'enum' => ['s', 'm', 'l']]],
        );
        $this->assertTrue($fn->validateArgs(['size' => 'm'])[0]);
        $this->assertFalse($fn->validateArgs(['size' => 'xl'])[0]);
    }

    public function testIsExternalWhenWebhookUrlSet(): void
    {
        $external = new SwaigFunction(
            name: 't',
            handler: fn (array $a, array $r) => new FunctionResult('x'),
            description: 'd',
            webhookUrl: 'https://ext.example.com/hook',
        );
        $this->assertTrue($external->isExternal);
    }
}
