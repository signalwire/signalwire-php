<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\Relay\CallState;
use SignalWire\Relay\Constants;
use SignalWire\Relay\Device;
use SignalWire\Relay\DialState;
use SignalWire\Relay\Event;
use SignalWire\Relay\MessageState;

/**
 * Tier-3 typed-object tests for the RELAY state enums
 * ({@see CallState} / {@see DialState} / {@see MessageState}) and the
 * {@see Device} value object.
 *
 * These drive the REAL enum/value-object methods (no mocks): every backing
 * value is checked against the {@see Constants} the rest of the SDK already
 * uses, isTerminal() is checked against the CALL/MESSAGE_TERMINAL_STATES maps,
 * tryFromWire() round-trips and degrades gracefully on unknown server values,
 * and Device::toArray() is byte-compared against the hand-written wire array.
 *
 * Real-mock-backed accessor tests (typed accessor agrees with the string after
 * a dispatched event) live in tests/Relay/*MockTest.php.
 */
class RelayTypedStateTest extends TestCase
{
    // =====================================================================
    //  CallState
    // =====================================================================

    #[Test]
    public function callStateBackingValuesMatchConstants(): void
    {
        $this->assertSame(Constants::CALL_STATE_CREATED, CallState::Created->value);
        $this->assertSame(Constants::CALL_STATE_RINGING, CallState::Ringing->value);
        $this->assertSame(Constants::CALL_STATE_ANSWERED, CallState::Answered->value);
        $this->assertSame(Constants::CALL_STATE_ENDING, CallState::Ending->value);
        $this->assertSame(Constants::CALL_STATE_ENDED, CallState::Ended->value);

        // Exactly five members, no more.
        $this->assertCount(5, CallState::cases());
    }

    #[Test]
    public function callStateIsTerminalOnlyForEnded(): void
    {
        $this->assertTrue(CallState::Ended->isTerminal());
        $this->assertFalse(CallState::Created->isTerminal());
        $this->assertFalse(CallState::Ringing->isTerminal());
        $this->assertFalse(CallState::Answered->isTerminal());
        $this->assertFalse(CallState::Ending->isTerminal());

        // isTerminal() must agree with the CALL_TERMINAL_STATES map the
        // SDK's own dispatch uses.
        foreach (CallState::cases() as $state) {
            $this->assertSame(
                isset(Constants::CALL_TERMINAL_STATES[$state->value]),
                $state->isTerminal(),
                "isTerminal() disagrees with CALL_TERMINAL_STATES for {$state->value}",
            );
        }
    }

    #[Test]
    public function callStateTryFromWireRoundTripsEveryKnownValue(): void
    {
        foreach (CallState::cases() as $state) {
            $this->assertSame(
                $state,
                CallState::tryFromWire($state->value),
                "tryFromWire did not round-trip {$state->value}",
            );
        }
    }

    #[Test]
    public function callStateTryFromWireReturnsNullForUnknownOrNull(): void
    {
        // Unknown server value -> null (forward-compatible, never throws).
        $this->assertNull(CallState::tryFromWire('teleported'));
        // null in -> null out.
        $this->assertNull(CallState::tryFromWire(null));
        // Wrong-vocabulary value (a message state) must NOT coerce.
        $this->assertNull(CallState::tryFromWire('delivered'));
    }

    // =====================================================================
    //  DialState
    // =====================================================================

    #[Test]
    public function dialStateBackingValuesMatchConstantsAndWire(): void
    {
        $this->assertSame(Constants::DIAL_STATE_DIALING, DialState::Dialing->value);
        $this->assertSame(Constants::DIAL_STATE_ANSWERED, DialState::Answered->value);
        $this->assertSame(Constants::DIAL_STATE_FAILED, DialState::Failed->value);
        // no_answer / busy are the extra terminal-failure values the port's
        // Client::handleDialEvent treats as terminal.
        $this->assertSame('no_answer', DialState::NoAnswer->value);
        $this->assertSame('busy', DialState::Busy->value);

        $this->assertCount(5, DialState::cases());
    }

    #[Test]
    public function dialStateIsTerminalForEverythingButDialing(): void
    {
        $this->assertFalse(DialState::Dialing->isTerminal());
        $this->assertTrue(DialState::Answered->isTerminal());
        $this->assertTrue(DialState::Failed->isTerminal());
        $this->assertTrue(DialState::NoAnswer->isTerminal());
        $this->assertTrue(DialState::Busy->isTerminal());
    }

    #[Test]
    public function dialStateTryFromWireRoundTripsAndDegrades(): void
    {
        foreach (DialState::cases() as $state) {
            $this->assertSame($state, DialState::tryFromWire($state->value));
        }
        $this->assertNull(DialState::tryFromWire('ringing')); // a call state, not a dial state
        $this->assertNull(DialState::tryFromWire('unknown_outcome'));
        $this->assertNull(DialState::tryFromWire(null));
    }

    // =====================================================================
    //  MessageState
    // =====================================================================

    #[Test]
    public function messageStateBackingValuesMatchConstants(): void
    {
        $this->assertSame(Constants::MESSAGE_STATE_QUEUED, MessageState::Queued->value);
        $this->assertSame(Constants::MESSAGE_STATE_INITIATED, MessageState::Initiated->value);
        $this->assertSame(Constants::MESSAGE_STATE_SENT, MessageState::Sent->value);
        $this->assertSame(Constants::MESSAGE_STATE_DELIVERED, MessageState::Delivered->value);
        $this->assertSame(Constants::MESSAGE_STATE_UNDELIVERED, MessageState::Undelivered->value);
        $this->assertSame(Constants::MESSAGE_STATE_FAILED, MessageState::Failed->value);
        $this->assertSame(Constants::MESSAGE_STATE_RECEIVED, MessageState::Received->value);

        $this->assertCount(7, MessageState::cases());
    }

    #[Test]
    public function messageStateIsTerminalMatchesTerminalMap(): void
    {
        $this->assertTrue(MessageState::Delivered->isTerminal());
        $this->assertTrue(MessageState::Undelivered->isTerminal());
        $this->assertTrue(MessageState::Failed->isTerminal());
        $this->assertFalse(MessageState::Queued->isTerminal());
        $this->assertFalse(MessageState::Initiated->isTerminal());
        $this->assertFalse(MessageState::Sent->isTerminal());
        $this->assertFalse(MessageState::Received->isTerminal());

        foreach (MessageState::cases() as $state) {
            $this->assertSame(
                isset(Constants::MESSAGE_TERMINAL_STATES[$state->value]),
                $state->isTerminal(),
                "isTerminal() disagrees with MESSAGE_TERMINAL_STATES for {$state->value}",
            );
        }
    }

    #[Test]
    public function messageStateTryFromWireRoundTripsAndDegrades(): void
    {
        foreach (MessageState::cases() as $state) {
            $this->assertSame($state, MessageState::tryFromWire($state->value));
        }
        $this->assertNull(MessageState::tryFromWire('answered')); // a call/dial state
        $this->assertNull(MessageState::tryFromWire('vaporized'));
        $this->assertNull(MessageState::tryFromWire(null));
    }

    // =====================================================================
    //  The three vocabularies must never be conflated
    // =====================================================================

    #[Test]
    public function vocabulariesDoNotCrossCoerce(): void
    {
        // 'ringing' is a CallState only.
        $this->assertSame(CallState::Ringing, CallState::tryFromWire('ringing'));
        $this->assertNull(DialState::tryFromWire('ringing'));
        $this->assertNull(MessageState::tryFromWire('ringing'));

        // 'no_answer' is a DialState only.
        $this->assertSame(DialState::NoAnswer, DialState::tryFromWire('no_answer'));
        $this->assertNull(CallState::tryFromWire('no_answer'));
        $this->assertNull(MessageState::tryFromWire('no_answer'));

        // 'delivered' is a MessageState only.
        $this->assertSame(MessageState::Delivered, MessageState::tryFromWire('delivered'));
        $this->assertNull(CallState::tryFromWire('delivered'));
        $this->assertNull(DialState::tryFromWire('delivered'));

        // 'created' is a CallState; 'queued' is a MessageState — confirm the
        // initial-state tokens don't bleed across.
        $this->assertNull(MessageState::tryFromWire('created'));
        $this->assertNull(CallState::tryFromWire('queued'));
    }

    // =====================================================================
    //  Event::dialState() typed accessor (from a real Event)
    // =====================================================================

    #[Test]
    public function eventDialStateReadsTypedFromParams(): void
    {
        // Production wire: dial event carries dial_state.
        $answered = new Event('calling.call.dial', ['tag' => 't1', 'dial_state' => 'answered']);
        $this->assertSame(DialState::Answered, $answered->dialState());
        $this->assertTrue($answered->dialState()?->isTerminal());

        // Legacy fixture: state key instead of dial_state.
        $legacy = new Event('calling.call.dial', ['tag' => 't1', 'state' => 'failed']);
        $this->assertSame(DialState::Failed, $legacy->dialState());

        // No dial_state at all -> null.
        $none = new Event('calling.call.dial', ['tag' => 't1']);
        $this->assertNull($none->dialState());

        // Unknown server value -> null (forward-compatible).
        $unknown = new Event('calling.call.dial', ['dial_state' => 'partial']);
        $this->assertNull($unknown->dialState());
    }

    // =====================================================================
    //  Device value object
    // =====================================================================

    #[Test]
    public function deviceToArrayIsByteIdenticalToHandWrittenLiteral(): void
    {
        // The exact literal callers hand-write today (see
        // tests/Relay/OutboundCallMockTest.php deviceFor()).
        $handWritten = [
            'type'   => 'phone',
            'params' => ['to_number' => '+15551110000', 'from_number' => '+15552220000'],
        ];

        $typed = new Device('phone', [
            'to_number'   => '+15551110000',
            'from_number' => '+15552220000',
        ]);

        // === is order-sensitive on PHP arrays: this proves identical keys,
        // values, AND key order.
        $this->assertSame($handWritten, $typed->toArray());
    }

    #[Test]
    public function devicePhoneFactoryMatchesHandWrittenLiteral(): void
    {
        $handWritten = [
            'type'   => 'phone',
            'params' => ['to_number' => '+15551110000', 'from_number' => '+15552220000'],
        ];
        $this->assertSame(
            $handWritten,
            Device::phone('+15551110000', '+15552220000')->toArray(),
        );
    }

    #[Test]
    public function devicePhoneFactoryMergesExtraParamsAfterNumbers(): void
    {
        $this->assertSame(
            [
                'type'   => 'phone',
                'params' => [
                    'to_number'   => '+15551110000',
                    'from_number' => '+15552220000',
                    'timeout'     => 30,
                ],
            ],
            Device::phone('+15551110000', '+15552220000', ['timeout' => 30])->toArray(),
        );
    }

    #[Test]
    public function deviceSipFactoryMatchesReferShape(): void
    {
        // Grounded in calling.refer.params.json: device.params.to (required) +
        // optional headers.
        $this->assertSame(
            [
                'type'   => 'sip',
                'params' => ['to' => 'sip:bob@example.com', 'headers' => ['X-Foo' => 'bar']],
            ],
            Device::sip('sip:bob@example.com', ['headers' => ['X-Foo' => 'bar']])->toArray(),
        );
    }

    #[Test]
    public function deviceFieldsAreReadonlyAndPublic(): void
    {
        $d = new Device('webrtc', ['from' => 'agent']);
        $this->assertSame('webrtc', $d->type);
        $this->assertSame(['from' => 'agent'], $d->params);

        // Empty params default round-trips as an empty array under 'params'.
        $this->assertSame(['type' => 'agent', 'params' => []], (new Device('agent'))->toArray());

        // readonly: a post-construction write is a hard \Error.
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line — deliberately violating readonly. */
        $d->type = 'mutated';
    }
}
