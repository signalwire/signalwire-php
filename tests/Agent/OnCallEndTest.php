<?php

declare(strict_types=1);

namespace SignalWire\Tests\Agent;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;

/**
 * AgentBase::onCallEnd. Ported from signalwire-python
 * tests/unit/core/test_agent_consolidation.py TestOnCallEnd: `call_log` is a
 * CONDITIONAL field on a SWAIG request, so registering a handler must turn on
 * `swaig_post_conversation` -- without it the hangup hook fires and carries no
 * transcript, indistinguishable from the hook never running.
 */
class OnCallEndTest extends TestCase
{
    private static function agent(): AgentBase
    {
        return new AgentBase(name: 'call-end', basicAuthUser: 'u', basicAuthPassword: 'p');
    }

    /** @return array<string, mixed> the rendered ai.params block */
    private static function aiParams(AgentBase $a): array
    {
        $swml = $a->renderSwml();
        $sections = $swml['sections'] ?? null;
        $main = is_array($sections) ? ($sections['main'] ?? []) : [];
        foreach (is_array($main) ? $main : [] as $verb) {
            if (is_array($verb) && isset($verb['ai']) && is_array($verb['ai'])) {
                $params = $verb['ai']['params'] ?? [];
                $out = [];
                foreach (is_array($params) ? $params : [] as $k => $v) {
                    $out[(string) $k] = $v;
                }
                return $out;
            }
        }
        return [];
    }

    /** @param array<string, mixed> $payload */
    private static function fire(AgentBase $a, array $payload): void
    {
        $a->onFunctionCall('hangup_hook', [], $payload);
    }

    public function testRegisteringEnablesThePayloadParameter(): void
    {
        $a = self::agent();
        $this->assertArrayNotHasKey('swaig_post_conversation', self::aiParams($a));
        $a->onCallEnd(fn (array $callLog, array $raw) => null);
        $this->assertTrue(self::aiParams($a)['swaig_post_conversation'] ?? null);
    }

    public function testRegisteringDefinesTheReservedHook(): void
    {
        $a = self::agent();
        $a->onCallEnd(fn (array $callLog, array $raw) => null);
        $this->assertTrue($a->hasFunction('hangup_hook'));
    }

    public function testHandlersReceiveTheLogAndRunInOrder(): void
    {
        $seen = [];
        $a = self::agent();
        $a->onCallEnd(function (array $callLog, array $raw) use (&$seen): void {
            $seen[] = ['one', count($callLog)];
        });
        $a->onCallEnd(function (array $callLog, array $raw) use (&$seen): void {
            $seen[] = ['two', $raw['call_id'] ?? null];
        });
        self::fire($a, ['call_log' => [['role' => 'user']], 'call_id' => 'c-1']);
        $this->assertSame([['one', 1], ['two', 'c-1']], $seen);
    }

    public function testRawCallLogIsAcceptedToo(): void
    {
        $seen = [];
        $a = self::agent();
        $a->onCallEnd(function (array $callLog, array $raw) use (&$seen): void {
            $seen[] = count($callLog);
        });
        self::fire($a, ['raw_call_log' => [['role' => 'user'], ['role' => 'assistant']]]);
        $this->assertSame([2], $seen);
    }

    public function testOneFailingHandlerDoesNotStopTheOthers(): void
    {
        $seen = [];
        $a = self::agent();
        $a->onCallEnd(function (array $callLog, array $raw): void {
            throw new \RuntimeException('boom');
        });
        $a->onCallEnd(function (array $callLog, array $raw) use (&$seen): void {
            $seen[] = 'still ran';
        });
        self::fire($a, ['call_log' => []]);
        $this->assertSame(['still ran'], $seen);
    }

    public function testAnExplicitFalseIsNotOverridden(): void
    {
        $a = self::agent();
        $a->setParams(['swaig_post_conversation' => false]);
        $a->onCallEnd(fn (array $callLog, array $raw) => null);
        $this->assertFalse(self::aiParams($a)['swaig_post_conversation'] ?? null);
    }

    public function testReturnsTheHandler(): void
    {
        $a = self::agent();
        $handler = fn (array $callLog, array $raw) => null;
        $this->assertSame($handler, $a->onCallEnd($handler));
    }
}
