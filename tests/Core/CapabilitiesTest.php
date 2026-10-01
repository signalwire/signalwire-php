<?php

declare(strict_types=1);

namespace SignalWire\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignalWire\Core\Capabilities;

/**
 * Reading what a client declares it can render. Ported from signalwire-python
 * tests/unit/core/test_capabilities.py. The rule pinned here: absence means no.
 */
class CapabilitiesTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function body(): array
    {
        return [
            'vars' => [
                'userVariables' => [
                    'capabilities' => [
                        'display_content' => true,
                        'transcript' => true,
                        'chat_handoff' => false,
                    ],
                    'metadata' => ['widget' => ['opened_at' => '2026-01-01T00:00:00Z']],
                ],
            ],
        ];
    }

    public function testExtractsFromTheNestedShape(): void
    {
        $this->assertArrayHasKey('capabilities', Capabilities::userVariables(self::body()));
    }

    /** @return list<array{0: mixed}> */
    public static function junkUserVariables(): array
    {
        return [
            [null], [[]], ['nonsense'], [42],
            [['vars' => null]], [['vars' => []]],
            [['vars' => ['userVariables' => null]]],
            [['vars' => ['userVariables' => 'not a dict']]],
        ];
    }

    #[DataProvider('junkUserVariables')]
    public function testMissingLevelsYieldAnEmptyArray(mixed $junk): void
    {
        $this->assertSame([], Capabilities::userVariables($junk));
    }

    public function testOnlyTruthyNamesAreReturned(): void
    {
        $this->assertSame(['display_content', 'transcript'], Capabilities::declaredCapabilities(self::body()));
    }

    public function testFalseIsNotADeclaration(): void
    {
        $this->assertNotContains('chat_handoff', Capabilities::declaredCapabilities(self::body()));
    }

    public function testAcceptsAlreadyExtractedUserVariables(): void
    {
        $this->assertSame(['a'], Capabilities::declaredCapabilities(['capabilities' => ['a' => true]]));
    }

    public function testANameThisSdkHasNeverHeardOfStillPassesThrough(): void
    {
        $this->assertTrue(Capabilities::hasCapability(['capabilities' => ['future_thing' => true]], 'future_thing'));
    }

    /** @return list<array{0: mixed}> */
    public static function junkCapabilities(): array
    {
        return [
            [null], [[]], ['nonsense'], [42],
            [['vars' => ['userVariables' => ['capabilities' => 'not a dict']]]],
            [['vars' => ['userVariables' => ['capabilities' => null]]]],
            [['vars' => ['userVariables' => []]]],
        ];
    }

    #[DataProvider('junkCapabilities')]
    public function testAbsenceAndMalformationBothMeanNo(mixed $junk): void
    {
        $this->assertSame([], Capabilities::declaredCapabilities($junk));
        $this->assertFalse(Capabilities::hasCapability($junk, 'display_content'));
    }

    public function testDeclared(): void
    {
        $this->assertTrue(Capabilities::hasCapability(self::body(), 'display_content'));
    }

    public function testDeclaredFalse(): void
    {
        $this->assertFalse(Capabilities::hasCapability(self::body(), 'chat_handoff'));
    }

    public function testNeverMentioned(): void
    {
        $this->assertFalse(Capabilities::hasCapability(self::body(), 'telepathy'));
    }
}
