<?php

/**
 * wire_relay_dump.php — the PHP port's WIRE-RELAY dump program for the cross-port
 * relay differ (porting-sdk/scripts/diff_port_wire_relay.py).
 *
 * It captures, for each wire_relay_corpus case, the observable RELAY artifact:
 *   - verb   : the {method, params} JSON-RPC frame a Call verb (or an Action
 *     control-op) hands to the wire.
 *   - client : the {method, params} frame a RelayClient call (execute / dial /
 *     send_message) sends.
 *   - event  : the decoded fields a typed event decoder extracts from a payload.
 *
 * It prints ONE JSON object mapping case-id -> artifact to stdout; the differ
 * canonicalizes both sides (normalizing the random control_id to a sentinel) and
 * byte-compares against the python oracle. Only stdout carries JSON; logs go to
 * stderr. Mirrors the Go reference dump (signalwire-go/cmd/wire-relay-dump).
 *
 * Frame capture (interpreted-port strategy): a RelayClient subclass overrides
 * ``execute`` to RECORD the {method, params} frame and return a canned success —
 * so verbs/Action-ops proceed without a real WebSocket. For ``dial`` (which
 * awaits a calling.call.dial event) the override resolves the pending dial before
 * returning. Event decoding is pure (no wire).
 *
 * Run from the signalwire-php repo root:
 *
 *     php scripts/wire_relay_dump.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\Relay\Call;
use SignalWire\Relay\Client;
use SignalWire\Relay\Event\CollectEvent;
use SignalWire\Relay\Event\QueueEvent;
use SignalWire\Relay\Event\RecordEvent;
use SignalWire\Relay\Event\RelayEvent;

const NODE = 'node-abc';
const CALL = 'call-xyz';
const CID  = 'ctl-123';

/**
 * A RelayClient that records every execute() frame and returns a canned success,
 * so Call verbs / Action control-ops / client-level calls proceed without a
 * real socket. Mirrors the oracle's `_RecordingClient(RelayClient)`.
 */
final class RecordingClient extends Client
{
    /** @var array<string, array<string,mixed>> method -> latest params */
    public array $frames = [];

    public function __construct()
    {
        parent::__construct(['project' => 'proj-1', 'token' => 'tok-1']);
        // send_message defaults the wire `context` to the negotiated protocol.
        $this->protocol = 'default';
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function execute(string $method, array $params = []): array
    {
        $this->frames[$method] = $params;

        if ($method === 'calling.dial') {
            // Resolve the pending dial (dial() registered pendingDials[$tag]
            // before calling execute) so its wait loop exits immediately.
            $tag = is_string($params['tag'] ?? null) ? $params['tag'] : null;
            if ($tag !== null) {
                $this->handleEvent([
                    'event_type' => 'calling.call.dial',
                    'params' => [
                        'tag' => $tag,
                        'dial_state' => 'answered',
                        'call' => ['call_id' => CALL, 'node_id' => NODE],
                    ],
                ]);
            }
            return ['code' => '200', 'message' => 'Dialing'];
        }
        if ($method === 'messaging.send') {
            return ['code' => '200', 'message_id' => 'msg-1'];
        }
        return ['code' => '200'];
    }

    /** No real transport to pump. */
    public function readOnce(): void
    {
    }
}

/**
 * @param array<string,mixed>|null $params
 * @return array{method: string, params: array<string,mixed>}
 */
function frame(string $method, ?array $params): array
{
    return ['method' => $method, 'params' => $params ?? []];
}

/** Short class name of a decoded event (mirrors Python's type(obj).__name__). */
function className(object $obj): string
{
    return (new ReflectionClass($obj))->getShortName();
}

$out = [];

// ================================================================
// Event decoders (pure — no wire)
// ================================================================

$q = QueueEvent::fromPayload([
    'event_type' => 'calling.call.queue',
    'params' => [
        'call_id' => CALL, 'control_id' => CID, 'status' => 'waiting',
        'id' => 'q-42', 'name' => 'support', 'position' => 3, 'size' => 10,
    ],
]);
$out['relay_evt_queue'] = [
    'control_id' => $q->controlId,
    'status' => $q->status,
    'queue_id' => $q->queueId,
    'queue_name' => $q->queueName,
    'position' => $q->position,
    'size' => $q->size,
];

$rec = RecordEvent::fromPayload([
    'event_type' => 'calling.call.record',
    'params' => [
        'call_id' => CALL, 'control_id' => CID, 'state' => 'finished',
        'record' => ['url' => 'https://x/rec.mp3', 'duration' => 12.5, 'size' => 4096],
    ],
]);
$out['relay_evt_record'] = [
    'control_id' => $rec->controlId,
    'state' => $rec->state,
    'url' => $rec->url,
    'duration' => $rec->duration,
    'size' => $rec->size,
];

$obj = RelayEvent::parseEvent([
    'event_type' => 'calling.call.state',
    'params' => [
        'call_id' => CALL, 'call_state' => 'answered', 'direction' => 'inbound', 'end_reason' => '',
    ],
]);
$stateOut = ['_class' => className($obj)];
if ($obj instanceof \SignalWire\Relay\Event\CallStateEvent) {
    $stateOut['call_id'] = $obj->callId;
    $stateOut['call_state'] = $obj->callState;
    $stateOut['direction'] = $obj->direction;
}
$out['relay_evt_state_dispatch'] = $stateOut;

$col = CollectEvent::fromPayload([
    'event_type' => 'calling.call.collect',
    'params' => [
        'call_id' => CALL, 'control_id' => CID, 'state' => 'finished',
        'result' => ['type' => 'digit', 'params' => ['digits' => '1234']],
        'final' => true,
    ],
]);
$out['relay_evt_collect'] = [
    'control_id' => $col->controlId,
    'state' => $col->state,
    'result' => $col->result,
    'final' => $col->final,
];

// ================================================================
// Call command verbs — the (method, params) frame
// ================================================================

$client = new RecordingClient();
$call = new Call(['call_id' => CALL, 'node_id' => NODE], $client);

// relay_play
$call->play(
    [['type' => 'audio', 'params' => ['url' => 'https://x/a.mp3']]],
    ['volume' => 5.0, 'control_id' => CID],
);
$out['relay_play'] = frame('calling.play', $client->frames['calling.play'] ?? null);

// relay_play_tts
$call->playTts('Hello world', ['voice' => 'en-US-Neural']);
$out['relay_play_tts'] = frame('calling.play', $client->frames['calling.play'] ?? null);

// relay_record
$call->record(['format' => 'mp3', 'beep' => true], ['control_id' => CID]);
$out['relay_record'] = frame('calling.record', $client->frames['calling.record'] ?? null);

// relay_connect (Call verb -> calling.connect)
$call->connect([
    'devices' => [[['type' => 'phone', 'params' => ['to_number' => '+15551112222']]]],
    'ringback' => [['type' => 'ringtone', 'params' => ['name' => 'us']]],
    'tag' => 'leg-1',
    'max_duration' => 3600,
]);
$out['relay_connect'] = frame('calling.connect', $client->frames['calling.connect'] ?? null);

// relay_collect
$call->collect([
    'digits' => ['max' => 4, 'terminators' => '#'],
    'speech' => ['language' => 'en-US'],
    'initial_timeout' => 5.0,
    'partial_results' => true,
    'control_id' => CID,
]);
$out['relay_collect'] = frame('calling.collect', $client->frames['calling.collect'] ?? null);

// relay_prompt (play_and_collect via prompt_tts)
$call->promptTts('Enter your PIN', ['digits' => ['max' => 4]], ['voice' => 'en-US-Neural']);
$out['relay_prompt'] = frame('calling.play_and_collect', $client->frames['calling.play_and_collect'] ?? null);

// relay_detect
$call->detect(['type' => 'machine', 'params' => ['initial_timeout' => 4.0]], ['timeout' => 30.0, 'control_id' => CID]);
$out['relay_detect'] = frame('calling.detect', $client->frames['calling.detect'] ?? null);

// relay_detect_amd
$call->detectAnsweringMachine(['initial_timeout' => 4.0, 'machine_words_threshold' => 6, 'timeout' => 30.0]);
$out['relay_detect_amd'] = frame('calling.detect', $client->frames['calling.detect'] ?? null);

// relay_tap
$call->tap(
    ['type' => 'audio', 'params' => ['direction' => 'both']],
    ['type' => 'ws', 'params' => ['uri' => 'wss://x/tap']],
    ['control_id' => CID],
);
$out['relay_tap'] = frame('calling.tap', $client->frames['calling.tap'] ?? null);

// relay_send_fax
$call->sendFax('https://x/doc.pdf', '+15550001111', ['header_info' => 'Hdr', 'control_id' => CID]);
$out['relay_send_fax'] = frame('calling.send_fax', $client->frames['calling.send_fax'] ?? null);

// ---- control-ops (Action methods) ----
// relay_play_stop
$pa = $call->play([['type' => 'audio', 'params' => ['url' => 'https://x/a.mp3']]], ['control_id' => CID]);
$pa->stop();
$out['relay_play_stop'] = frame('calling.play.stop', $client->frames['calling.play.stop'] ?? null);

// relay_play_pause
$pa2 = $call->play([['type' => 'audio', 'params' => ['url' => 'https://x/a.mp3']]], ['control_id' => CID]);
$pa2->pause('silence');
$out['relay_play_pause'] = frame('calling.play.pause', $client->frames['calling.play.pause'] ?? null);

// relay_record_resume
$ra = $call->record(['format' => 'mp3'], ['control_id' => CID]);
$ra->resume();
$out['relay_record_resume'] = frame('calling.record.resume', $client->frames['calling.record.resume'] ?? null);

// relay_play_volume
$pa3 = $call->play([['type' => 'audio', 'params' => ['url' => 'https://x/a.mp3']]], ['control_id' => CID]);
$pa3->volume(3.5);
$out['relay_play_volume'] = frame('calling.play.volume', $client->frames['calling.play.volume'] ?? null);

// ================================================================
// RelayClient-level frames
// ================================================================

// relay_client_execute
$client->execute('calling.answer', ['node_id' => NODE, 'call_id' => CALL]);
$out['relay_client_execute'] = frame('calling.answer', $client->frames['calling.answer'] ?? null);

// relay_send_message
$client->sendMessage([
    'to_number' => '+15551112222',
    'from_number' => '+15553334444',
    'body' => 'hi',
    'tags' => ['t1'],
]);
$out['relay_send_message'] = frame('messaging.send', $client->frames['messaging.send'] ?? null);

// relay_dial
$client->dial(
    [[['type' => 'phone', 'params' => ['to_number' => '+15551112222']]]],
    ['tag' => 'dial-1', 'max_duration' => 600],
);
$out['relay_dial'] = frame('calling.dial', $client->frames['calling.dial'] ?? null);

$encoded = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    fwrite(STDERR, 'wire-relay-dump: json_encode failed: ' . json_last_error_msg() . "\n");
    exit(1);
}
echo $encoded, "\n";
