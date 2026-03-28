<?php

declare(strict_types=1);

namespace SignalWire\Relay;

final class Constants
{
    public const PROTOCOL_VERSION = [
        'major' => 2,
        'minor' => 0,
        'revision' => 0,
    ];

    // Call states
    public const CALL_STATE_CREATED = 'created';
    public const CALL_STATE_RINGING = 'ringing';
    public const CALL_STATE_ANSWERED = 'answered';
    public const CALL_STATE_ENDING = 'ending';
    public const CALL_STATE_ENDED = 'ended';

    public const CALL_TERMINAL_STATES = [
        'ended' => true,
    ];

    // Dial states
    public const DIAL_STATE_DIALING = 'dialing';
    public const DIAL_STATE_ANSWERED = 'answered';
    public const DIAL_STATE_FAILED = 'failed';

    // Message states
    public const MESSAGE_STATE_QUEUED = 'queued';
    public const MESSAGE_STATE_INITIATED = 'initiated';
    public const MESSAGE_STATE_SENT = 'sent';
    public const MESSAGE_STATE_DELIVERED = 'delivered';
    public const MESSAGE_STATE_UNDELIVERED = 'undelivered';
    public const MESSAGE_STATE_FAILED = 'failed';
    public const MESSAGE_STATE_RECEIVED = 'received';

    public const MESSAGE_TERMINAL_STATES = [
        'delivered' => true,
        'undelivered' => true,
        'failed' => true,
    ];

    // Action terminal states per event type
    public const ACTION_TERMINAL_STATES = [
        'calling.call.play' => ['finished' => true, 'error' => true],
        'calling.call.record' => ['finished' => true, 'no_input' => true],
        'calling.call.detect' => ['finished' => true, 'error' => true],
        'calling.call.collect' => ['finished' => true, 'error' => true, 'no_input' => true, 'no_match' => true],
        'calling.call.fax' => ['finished' => true, 'error' => true],
        'calling.call.tap' => ['finished' => true],
        'calling.call.stream' => ['finished' => true],
        'calling.call.transcribe' => ['finished' => true],
        'calling.call.pay' => ['finished' => true, 'error' => true],
    ];
}
