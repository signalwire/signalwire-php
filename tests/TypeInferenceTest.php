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
use SignalWire\SWAIG\TypeInference;

/**
 * Behavioral parity tests for TypeInference — the runtime schema-inference +
 * typed-handler-wrapper helpers. Mirrors the Python reference
 * (core/agent/tools/type_inference.py) and TS oracle (TypeInference.ts):
 * infer_schema returns (parameters, required, description, isTyped, hasRawData);
 * create_typed_handler_wrapper unpacks args into named params.
 */
class TypeInferenceTest extends TestCase
{
    public function testInferSchemaFromTypedHandler(): void
    {
        $handler = function (string $city, int $days = 5): string {
            return "$city:$days";
        };
        [$params, $required, $description, $isTyped, $hasRawData] = TypeInference::inferSchema($handler);

        $this->assertTrue($isTyped);
        $this->assertFalse($hasRawData);
        $this->assertSame(['type' => 'string'], $params['city']);
        $this->assertSame(['type' => 'integer'], $params['days']);
        // city is required (no default, non-nullable); days has a default.
        $this->assertSame(['city'], $required);
    }

    public function testInferSchemaFromTypedHandlerWithDocblockDescription(): void
    {
        // Two params (one required, one optional-with-default) + a docblock:
        // parameters, required, and description must all come out right.
        $handler =
            /**
             * Look up the weather for a city.
             *
             * @param string $city the city name
             */
            function (string $city, int $days = 5): string {
                return "$city:$days";
            };
        [$params, $required, $description, $isTyped, $hasRawData] = TypeInference::inferSchema($handler);

        $this->assertTrue($isTyped);
        $this->assertFalse($hasRawData);
        $this->assertSame(['city', 'days'], array_keys($params));
        $this->assertSame(['type' => 'string'], $params['city']);
        $this->assertSame(['type' => 'integer'], $params['days']);
        // city has no default and is non-nullable -> required; days has a default.
        $this->assertSame(['city'], $required);
        $this->assertSame('Look up the weather for a city.', $description);
    }

    public function testInferSchemaDetectsRawDataAndExcludesItFromSchema(): void
    {
        $handler = function (string $query, ?array $rawData = null): string {
            return $query;
        };
        [$params, $required, , $isTyped, $hasRawData] = TypeInference::inferSchema($handler);

        $this->assertTrue($isTyped);
        $this->assertTrue($hasRawData);
        $this->assertArrayHasKey('query', $params);
        $this->assertArrayNotHasKey('rawData', $params, 'rawData must be excluded from the schema');
    }

    public function testInferSchemaOldStyleUntypedHandlerIsNotTyped(): void
    {
        // Legacy (args, raw_data) shape with no meaningful hints -> old style.
        $handler = function (array $args, array $raw_data): string {
            return '';
        };
        [$params, $required, $description, $isTyped, $hasRawData] = TypeInference::inferSchema($handler);

        $this->assertFalse($isTyped);
        $this->assertSame([], $params);
        $this->assertSame([], $required);
        $this->assertNull($description);
    }

    public function testInferSchemaNullableParamIsOptional(): void
    {
        $handler = function (?string $note): string {
            return (string) $note;
        };
        [, $required] = TypeInference::inferSchema($handler);
        $this->assertSame([], $required, 'a nullable param is optional, so not required');
    }

    public function testCreateTypedHandlerWrapperUnpacksArgs(): void
    {
        $handler = fn (string $city, int $days): string => "$city/$days";
        $wrapped = TypeInference::createTypedHandlerWrapper($handler, false);

        $this->assertSame('Denver/3', $wrapped(['city' => 'Denver', 'days' => 3], null));
    }

    public function testCreateTypedHandlerWrapperPassesRawData(): void
    {
        $seenRaw = null;
        $handler = function (string $q, ?array $rawData = null) use (&$seenRaw): string {
            $seenRaw = $rawData;
            return $q;
        };
        $wrapped = TypeInference::createTypedHandlerWrapper($handler, true);

        $out = $wrapped(['q' => 'hello'], ['call_id' => 'c1']);
        $this->assertSame('hello', $out);
        $this->assertSame(['call_id' => 'c1'], $seenRaw);
    }
}
