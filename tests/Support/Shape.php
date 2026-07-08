<?php

declare(strict_types=1);

namespace SignalWire\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Test-only helpers for reading dynamically-shaped, serialized structures
 * (the `array<string,mixed>` returned by `toArray()`/`json_decode(..., true)`
 * across the SWML / SWAIG / RELAY / REST surfaces).
 *
 * These do REAL runtime narrowing — each hop asserts the value is an array
 * before indexing — so the level-9 analyser can follow a deep wire-shape chain
 * without every intermediate becoming `mixed`. They are not type overrides:
 * an actual PHPUnit assertion runs, and a wrong shape fails the test loudly
 * (strictly stronger than the pre-annotation `$arr[$k]` which would silently
 * warn on a non-array). Green tests are unaffected — the shapes under test are
 * present and array-typed by construction.
 */
final class Shape
{
    /**
     * Walk a serialized structure by a sequence of keys, asserting each hop is
     * an array. Returns the leaf as `mixed` (fine for assertSame/assertEquals,
     * which accept `mixed`). Replaces a chain like `$arr['a'][0]['b']` with
     * `Shape::at($arr, 'a', 0, 'b')`.
     *
     * @param int|string ...$keys
     */
    public static function at(mixed $data, int|string ...$keys): mixed
    {
        foreach ($keys as $k) {
            Assert::assertIsArray($data, "Shape::at expected an array at key '{$k}'");
            Assert::assertArrayHasKey($k, $data, "Shape::at missing key '{$k}'");
            /** @var array<array-key,mixed> $data */
            $data = $data[$k];
        }

        return $data;
    }

    /**
     * Assert-and-narrow a `mixed` to `array<array-key,mixed>` for the cases
     * where the whole array (not a leaf) is needed — e.g. passing it to
     * `assertCount()`, `foreach`, or `assertArrayHasKey()`.
     *
     * @return array<array-key,mixed>
     */
    public static function arr(mixed $data): array
    {
        Assert::assertIsArray($data);

        return $data;
    }

    /**
     * Like {@see at()} but returns the sub-array narrowed to
     * `array<array-key,mixed>` — for a chain whose leaf is itself an array that
     * the caller then indexes or counts.
     *
     * @param int|string ...$keys
     * @return array<array-key,mixed>
     */
    public static function sub(mixed $data, int|string ...$keys): array
    {
        return self::arr(self::at($data, ...$keys));
    }

    /**
     * Assert-and-narrow a `mixed` numeric leaf to `float`. JSON round-trips a
     * whole-number value as an int (`-6`, not `-6.0`), so a test asserting a
     * numeric wire field must accept int OR float — this asserts numeric-ness
     * then casts, exactly reproducing the pre-annotation `(float) ($x ?? 0)`
     * idiom that fed `assertEqualsWithDelta`. (assertIsFloat would spuriously
     * fail on the int form.)
     */
    public static function num(mixed $data): float
    {
        Assert::assertTrue(
            is_int($data) || is_float($data) || (is_string($data) && is_numeric($data)),
            'Shape::num expected a numeric value',
        );

        return (float) $data;
    }
}
