<?php
/**
 * emit_corpus.php — the PHP port's EMISSION-DUMP program for the cross-port
 * emission differ (porting-sdk/scripts/diff_port_emission.py).
 *
 * It builds the shared FunctionResult corpus
 * (porting-sdk/scripts/emission_corpus.py — the single source of truth) using
 * the PHP SDK's native SignalWire\SWAIG\FunctionResult API, serialises each
 * entry the same way the SDK serialises on the wire (toArray()), and prints
 * ONE JSON object mapping
 *
 *     corpus-id -> emission
 *
 * to stdout. The differ runs this program, parses that object, and byte-compares
 * each entry against Python's to_dict(). See the "per-port dump contract" in the
 * differ's --help and IDIOM_PASS_JOURNAL.md §4 Tier-0. This mirrors the Go
 * reference dump (signalwire-go/cmd/emit-corpus/main.go).
 *
 * CONTRACT (why this file looks the way it does):
 *   - Every corpus id in emission_corpus.corpus_ids() MUST appear here exactly
 *     once (the differ rejects an id-set mismatch as a setup error — a skewed
 *     set would mask real diffs). When the shared corpus grows, add the new id.
 *   - The argument VALUES are the WIRE values (plain strings/numbers/bools/maps).
 *     Where the PHP API types a closed set (RecordFormat, RecordDirection,
 *     TapDirection, Codec) we pass the bare wire string — proving the string arm
 *     emits byte-identically (the typed enums round-trip to the same value).
 *   - Only stdout carries the JSON object; nothing else is printed there.
 *
 * PHP empty-object idiom: an empty PHP array `[]` json_encodes to `[]` (a JSON
 * array), NOT `{}`. Where the wire needs an empty OBJECT (the `{answer: {}}`
 * inside execute_swml.dict), we use `new \stdClass()`. The FunctionResult source
 * already does this for clear_dynamic_hints / stop_record_call / stop_tap.
 *
 * Run from the signalwire-php repo root:
 *
 *     php scripts/emit_corpus.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SignalWire\SWAIG\FunctionResult;

/**
 * fr() is a tiny constructor helper mirroring Go's NewFunctionResult(response).
 */
function fr(string $response = '', bool $postProcess = false): FunctionResult
{
    return new FunctionResult($response, $postProcess);
}

/**
 * The PHP-native mirror of porting-sdk/scripts/emission_corpus.py. Each entry is
 * [id => closure-returning-FunctionResult]; building the result lazily keeps each
 * line a single, readable native call (same shape as the Go dump). The ids and
 * the resulting emission must match the Python oracle exactly (modulo the
 * whole-float artifact the differ normalises: Python 44.0 == PHP 44).
 *
 * @var array<string, callable():FunctionResult> $corpus
 */
$corpus = [
    // ---- envelope edge cases (toArray() shape) ------------------------------
    'envelope.empty' => fn() => fr(''),
    'envelope.response_only' => fn() => fr('Hello, world!'),
    'envelope.post_process_no_action' => fn() => fr('hi', true),
    'envelope.action_only' => fn() => fr('')->hangup(),
    'envelope.post_process_with_action' => fn() => fr('Transferring', true)->hangup(),
    'envelope.response_and_action' => fn() => fr('Goodbye')->hangup(),

    // ---- connect ------------------------------------------------------------
    'connect.final_true' => fn() => fr('')->connect('+15551234567', true),
    'connect.final_false' => fn() => fr('')->connect('+15551234567', false),
    'connect.from_addr' => fn() => fr('')->connect('support@example.com', false, '+15559876543'),

    // ---- swml_transfer ------------------------------------------------------
    'swml_transfer.default' => fn() => fr('')->swmlTransfer('https://dest.example.com/swml', 'Goodbye!'),
    'swml_transfer.final_false' => fn() => fr('')->swmlTransfer(
        'https://dest.example.com/swml',
        'Welcome back. How else can I help?',
        false
    ),

    // ---- simple call-control actions ---------------------------------------
    'hangup' => fn() => fr('')->hangup(),
    'hold.default' => fn() => fr('')->hold(),
    'hold.value' => fn() => fr('')->hold(120),
    'hold.clamp_high' => fn() => fr('')->hold(5000),
    'hold.clamp_low' => fn() => fr('')->hold(-5),
    'stop' => fn() => fr('')->stop(),
    'say' => fn() => fr('')->say('Please hold while I connect you.'),

    // ---- wait_for_user (each branch) ---------------------------------------
    'wait_for_user.default' => fn() => fr('')->waitForUser(),
    'wait_for_user.answer_first' => fn() => fr('')->waitForUser(null, null, true),
    'wait_for_user.timeout' => fn() => fr('')->waitForUser(null, 30),
    'wait_for_user.enabled_true' => fn() => fr('')->waitForUser(true),
    'wait_for_user.enabled_false' => fn() => fr('')->waitForUser(false),

    // ---- global data / metadata --------------------------------------------
    'set_global_data' => fn() => fr('')->updateGlobalData(['plan' => 'premium', 'chips' => 1000]),
    'unset_global_data.list' => fn() => fr('')->removeGlobalData(['plan', 'chips']),
    'unset_global_data.str' => fn() => fr('')->removeGlobalData('plan'),
    'set_metadata' => fn() => fr('')->setMetadata(['token' => 'abc', 'count' => 3]),
    'unset_metadata.list' => fn() => fr('')->removeMetadata(['token', 'count']),
    'unset_metadata.str' => fn() => fr('')->removeMetadata('token'),

    // ---- swml_user_event ----------------------------------------------------
    'swml_user_event' => fn() => fr('')->swmlUserEvent([
        'type' => 'cards_dealt',
        'player_hand' => ['AS', 'KH'],
        'player_score' => 21,
    ]),

    // ---- step / context changes --------------------------------------------
    'change_step' => fn() => fr('')->swmlChangeStep('collect_payment'),
    'change_context' => fn() => fr('')->swmlChangeContext('billing'),

    // ---- switch_context (simple vs object) ---------------------------------
    'switch_context.simple' => fn() => fr('')->switchContext('You are now a billing agent.'),
    'switch_context.object' => fn() => fr('')->switchContext('New system prompt', 'User said something', true, false),
    'switch_context.full_reset' => fn() => fr('')->switchContext('Reset prompt', '', false, true),

    // ---- background file play/stop -----------------------------------------
    'playback_bg.simple' => fn() => fr('')->playBackgroundFile('music.mp3'),
    'playback_bg.wait' => fn() => fr('')->playBackgroundFile('music.mp3', true),
    'stop_playback_bg' => fn() => fr('')->stopBackgroundFile(),

    // ---- join_room / sip_refer ---------------------------------------------
    'join_room' => fn() => fr('')->joinRoom('team-standup'),
    'sip_refer' => fn() => fr('')->sipRefer('sip:agent@example.com'),

    // ---- send_sms -----------------------------------------------------------
    'send_sms.body' => fn() => fr('')->sendSms(
        '+15551112222',
        '+15553334444',
        'Your appointment is confirmed.'
    ),
    'send_sms.full' => fn() => fr('')->sendSms(
        '+15551112222',
        '+15553334444',
        'See attached.',
        ['https://ex.com/a.jpg'],
        ['receipt', 'vip'],
        'us'
    ),

    // ---- pay ----------------------------------------------------------------
    'pay.minimal' => fn() => fr('')->pay('https://pay.example.com/connector'),
    'pay.full' => fn() => fr('')->pay(
        'https://pay.example.com/connector',
        'dtmf',               // input_method
        'https://ex.com/status', // status_url
        'credit-card',        // payment_method
        7,                    // timeout
        2,                    // max_attempts
        false,                // security_code
        '90210',              // postal_code
        5,                    // min_postal_code_length
        'one-time',           // token_type
        '9.99',               // charge_amount
        'usd',                // currency
        'en-US',              // language
        'woman',              // voice
        'Order 42',           // description
        'visa amex',          // valid_card_types
        [['name' => 'order_id', 'value' => '42']], // parameters
        [[                    // prompts
            'for' => 'payment-card-number',
            'actions' => [['type' => 'Say', 'phrase' => 'Enter your card number']],
            'card_type' => 'visa amex',
        ]]
        // ai_response left at the default (the corpus passes the Python default).
    ),
    'pay.postal_bool' => fn() => fr('')->pay(
        'https://pay.example.com/connector',
        postalCode: true
    ),

    // ---- record_call (incl. mp4 + each direction) --------------------------
    'record_call.defaults' => fn() => fr('')->recordCall(),
    'record_call.wav_speak' => fn() => fr('')->recordCall(format: 'wav', direction: 'speak'),
    'record_call.mp3_listen' => fn() => fr('')->recordCall(format: 'mp3', direction: 'listen'),
    'record_call.mp4_both' => fn() => fr('')->recordCall(format: 'mp4', direction: 'both'),
    'record_call.full' => fn() => fr('')->recordCall(
        controlId: 'rec1',
        stereo: true,
        format: 'mp3',
        direction: 'both',
        terminators: '#',
        beep: true,
        inputSensitivity: 30.0,
        initialTimeout: 5.0,
        endSilenceTimeout: 3.0,
        maxLength: 120.0,
        statusUrl: 'https://ex.com/rec'
    ),
    'stop_record_call.bare' => fn() => fr('')->stopRecordCall(),
    'stop_record_call.id' => fn() => fr('')->stopRecordCall('rec1'),

    // ---- tap (each direction / codec) --------------------------------------
    'tap.defaults' => fn() => fr('')->tap('rtp://10.0.0.1:5004'),
    'tap.speak_pcma' => fn() => fr('')->tap('ws://ex.com/tap', direction: 'speak', codec: 'PCMA'),
    'tap.hear_pcmu' => fn() => fr('')->tap('wss://ex.com/tap', direction: 'hear', codec: 'PCMU'),
    'tap.both_full' => fn() => fr('')->tap(
        'rtp://10.0.0.1:5004',
        controlId: 'tap1',
        direction: 'both',
        codec: 'PCMA',
        rtpPtime: 40,
        statusUrl: 'https://ex.com/tapstatus'
    ),
    'stop_tap.bare' => fn() => fr('')->stopTap(),
    'stop_tap.id' => fn() => fr('')->stopTap('tap1'),

    // ---- join_conference (simple + full) -----------------------------------
    'join_conference.simple' => fn() => fr('')->joinConference('sales-floor'),
    'join_conference.full' => fn() => fr('')->joinConference(
        'sales-floor',
        muted: true,
        beep: 'onEnter',
        startOnEnter: false,
        endOnExit: true,
        waitUrl: 'https://ex.com/hold',
        maxParticipants: 50,
        record: 'record-from-start',
        region: 'us-east',
        trim: 'do-not-trim',
        coach: 'call-123',
        statusCallbackEvent: 'start end join leave',
        statusCallback: 'https://ex.com/cb',
        statusCallbackMethod: 'GET',
        recordingStatusCallback: 'https://ex.com/rcb',
        recordingStatusCallbackMethod: 'GET',
        recordingStatusCallbackEvent: 'in-progress completed'
    ),

    // ---- execute_rpc + the three rpc helpers -------------------------------
    'execute_rpc.minimal' => fn() => fr('')->executeRpc('ai_unhold'),
    'execute_rpc.full' => fn() => fr('')->executeRpc(
        'ai_message',
        ['role' => 'system', 'message_text' => 'Hello'],
        'call-abc',
        'node-1'
    ),
    'rpc_dial' => fn() => fr('')->rpcDial('+15551234567', '+15559876543', 'https://ex.com/call-agent'),
    'rpc_ai_message' => fn() => fr('')->rpcAiMessage('call-abc', 'Please take a message.'),
    'rpc_ai_unhold' => fn() => fr('')->rpcAiUnhold('call-abc'),

    // ---- simulate_user_input -----------------------------------------------
    'simulate_user_input' => fn() => fr('')->simulateUserInput("I'd like to pay my bill."),

    // ---- dynamic hints ------------------------------------------------------
    'add_dynamic_hints' => fn() => fr('')->addDynamicHints([
        'Cabby',
        ['pattern' => 'cab bee', 'replace' => 'Cabby', 'ignore_case' => true],
    ]),
    'clear_dynamic_hints' => fn() => fr('')->clearDynamicHints(),

    // ---- toggle_functions / functions-on-timeout ---------------------------
    // The corpus passes a LIST of {function, active} dicts. PHP's toggleFunctions
    // takes an ASSOCIATIVE [name => active] map and rebuilds that same list, so
    // convert here (insertion order preserved → matches the oracle's list order).
    'toggle_functions' => fn() => fr('')->toggleFunctions(['transfer' => false, 'lookup' => true]),
    'functions_on_speaker_timeout.true' => fn() => fr('')->enableFunctionsOnTimeout(),
    'functions_on_speaker_timeout.false' => fn() => fr('')->enableFunctionsOnTimeout(false),

    // ---- extensive_data -----------------------------------------------------
    'extensive_data.true' => fn() => fr('')->enableExtensiveData(),
    'extensive_data.false' => fn() => fr('')->enableExtensiveData(false),

    // ---- replace_in_history (str + bool) -----------------------------------
    'replace_in_history.bool' => fn() => fr('')->replaceInHistory(),
    'replace_in_history.str' => fn() => fr('')->replaceInHistory('Summarized the order.'),

    // ---- settings -----------------------------------------------------------
    'settings' => fn() => fr('')->updateSettings(['temperature' => 0.7, 'max-tokens' => 256, 'top-p' => 0.9]),

    // ---- speech timeouts ----------------------------------------------------
    'end_of_speech_timeout' => fn() => fr('')->setEndOfSpeechTimeout(800),
    'speech_event_timeout' => fn() => fr('')->setSpeechEventTimeout(1200),

    // ---- execute_swml (dict + JSON-string + transfer) ----------------------
    // The {answer: {}} / {hangup: {}} inner values must serialise as empty
    // OBJECTS, not empty arrays — hence new \stdClass() for the dict forms. The
    // json_string form decodes in object mode inside executeSwml(), so its inner
    // {} is preserved automatically.
    'execute_swml.dict' => fn() => fr('')->executeSwml([
        'version' => '1.0.0',
        'sections' => ['main' => [['answer' => new \stdClass()]]],
    ]),
    'execute_swml.dict_transfer' => fn() => fr('')->executeSwml([
        'version' => '1.0.0',
        'sections' => ['main' => [['answer' => new \stdClass()]]],
    ], true),
    'execute_swml.json_string' => fn() => fr('')->executeSwml(
        '{"version": "1.0.0", "sections": {"main": [{"hangup": {}}]}}'
    ),
];

$out = [];
foreach ($corpus as $id => $build) {
    if (array_key_exists($id, $out)) {
        fwrite(STDERR, "emit-corpus: duplicate corpus id {$id}\n");
        exit(1);
    }
    /** @var FunctionResult $result */
    $result = $build();
    $out[$id] = $result->toArray();
}

// Encode to ONE JSON object on stdout. UNESCAPED_SLASHES/UNICODE keeps '/' and
// non-ASCII literal (matches Python json.dumps defaults); the differ parses to
// JSON so this is cosmetic, but it keeps the dump human-diffable against the
// oracle. PRESERVE_ZERO_FRACTION is intentionally OFF: the differ folds 44.0≡44,
// and emitting 44 (PHP's default for a whole float) matches the Go reference.
$encoded = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    fwrite(STDERR, 'emit-corpus: json_encode failed: ' . json_last_error_msg() . "\n");
    exit(1);
}

echo $encoded, "\n";
