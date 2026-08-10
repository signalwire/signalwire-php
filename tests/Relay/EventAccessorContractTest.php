<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\TestCase;
use SignalWire\Relay\Event;

/**
 * Pins the accessor NAME on {@see Event}, and the digit-extraction the shipped
 * IVR example routes on.
 *
 * Why: relay/examples/relay_ivr_connect.php guarded its collect-result handling
 * with `method_exists($resultEvent, 'params')` and then called
 * `$resultEvent->params()`. Event exposes `getParams()` — there has never been a
 * `params()`. So the guard was ALWAYS false, the entire result-extraction block
 * was dead, and `$resultType`/`$digits` stayed empty strings. Every digit branch
 * in that example (press 1 / 2 / 0) was unreachable and the example fell through
 * to its else on every single call.
 *
 * A `method_exists()` guard turns a wrong method name from a fatal into
 * permanently-skipped code, which is exactly why nothing ever failed. These
 * tests assert both halves — the accessor name on the SDK, and that the
 * example's extracted `collectResult()` really reaches the digits — so the hole
 * cannot quietly re-open.
 */
class EventAccessorContractTest extends TestCase
{
    private static function loadExample(): void
    {
        // The example guards its blocking Relay client behind a CLI-entrypoint
        // check, so requiring it here defines the helpers and returns without
        // opening a WebSocket.
        require_once __DIR__ . '/../../relay/examples/relay_ivr_connect.php';
    }

    /**
     * Build a collect event carrying the production wire payload — the same
     * shape ActionsMockTest pushes for `calling.call.collect`.
     */
    private static function collectEvent(string $digits): Event
    {
        return new Event(
            'calling.call.collect',
            [
                'call_id' => 'call-ivr-1',
                'control_id' => 'ivr-1',
                'result' => ['type' => 'digit', 'params' => ['digits' => $digits]],
            ],
        );
    }

    public function testEventExposesGetParamsAndNotParams(): void
    {
        $event = self::collectEvent('1');

        // getParams() is the accessor the SDK and examples must use. Calling it
        // here IS the assertion on its name: PHPStan proves the name
        // statically, so a rename fails the analyser before this test runs.
        self::assertArrayHasKey('result', $event->getParams());

        // The exact misspelling the IVR example guarded on. If a params()
        // accessor is ever added, this test must be revisited deliberately
        // rather than the example silently taking a different path.
        self::assertFalse(
            method_exists($event, 'params'),
            'Event has no params() — a method_exists("params") guard is dead code',
        );
    }

    public function testCollectResultExtractsTheDigitTheIvrRoutesOn(): void
    {
        self::loadExample();

        // Against the pre-fix example this returned ['', ''] for EVERY input,
        // because the method_exists('params') guard never passed.
        self::assertSame(
            ['type' => 'digit', 'digits' => '1'],
            \collectResult(self::collectEvent('1')),
        );
        self::assertSame(
            ['type' => 'digit', 'digits' => '0'],
            \collectResult(self::collectEvent('0')),
        );
    }

    public function testCollectResultIsEmptyWhenTheCollectProducedNothing(): void
    {
        self::loadExample();

        // A timed-out collect resolves with no event at all.
        self::assertSame(['type' => '', 'digits' => ''], \collectResult(null));

        // An event with no `result` key (e.g. a state-only frame).
        $bare = new Event('calling.call.collect', ['call_id' => 'call-ivr-1']);
        self::assertSame(['type' => '', 'digits' => ''], \collectResult($bare));
    }
}
