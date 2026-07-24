#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Copyright (c) 2026 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 *
 * ai-chat-dump.php — the PHP port's AI-CHAT dump program for the cross-port
 * wire-behavioral gate (porting-sdk/scripts/diff_port_ai_chat.py, on the
 * `ai-chat-client` branch — a COORDINATED pass).
 *
 * The gate boots the in-process mock_ai_chat server, exports MOCK_AI_CHAT_URL +
 * SIGNALWIRE_PROJECT_ID / SIGNALWIRE_API_TOKEN into this program's env, runs it,
 * and asserts the JSON it prints (+ the wire requests the mock recorded) speak
 * the AI Chat protocol per the vendored spec (ai-chat-specs/ai-chat.yaml).
 *
 * This mirrors porting-sdk/scripts/ai_chat_dump_reference.py EXACTLY: it drives
 * the PHP AIChatClient through the shared ai_chat_corpus and emits ONE JSON
 * object to stdout (nothing else), keyed by corpus step:
 *
 *   success steps (create/chat/end/delete/log/summarize):
 *       { wire_method, decoded: { <spec result fields> } }
 *   summarize_failed (the summarize {error} one_of branch — must SURFACE, not swallow):
 *       { wire_method:"summarize", raised:true, error_type, message }
 *   error steps (err_notfound/err_ratelimit/err_inprogress/err_auth/err_unmapped):
 *       { raised:true, error_code, error_type }
 *
 * The corpus (steps + SUMMARIZE_ERROR_ID + ERROR_STEPS + force_error_id) is data,
 * identical for every language; it is mirrored inline here from ai_chat_corpus.py.
 *
 * Run from the signalwire-php repo root against a running mock:
 *
 *   MOCK_AI_CHAT_URL=http://127.0.0.1:PORT/api/ai/chat php bin/ai-chat-dump.php
 *
 * Nothing but the JSON object is written to stdout on success.
 */

use SignalWire\AIChat\AIChatClient;
use SignalWire\AIChat\AIChatError;
use SignalWire\AIChat\SummaryError;

// Keep stdout PURE JSON: the gate does json.loads(proc.stdout). Buffer any stray
// byte written during autoload so it can never corrupt the parse; we discard the
// buffer and print only the final JSON object.
ob_start();

require dirname(__DIR__) . '/vendor/autoload.php';

// ── the shared corpus (mirror of porting-sdk/scripts/ai_chat_corpus.py) ──────

/** The sentinel conversation id that makes summarize return its {error} branch. */
const SUMMARIZE_ERROR_ID = '__summarize_error';

/** error step id -> the JSON-RPC code the port's raised error MUST carry. */
const ERROR_STEPS = [
    'err_notfound' => -32001,   // ConversationNotFound
    'err_ratelimit' => -32005,  // RateLimit
    'err_inprogress' => -32007, // ChatInProgress
    'err_auth' => -32009,       // Authentication
    'err_unmapped' => -32602,   // base AIChatError (unmapped code)
];

/** The sentinel conversation id that makes the mock return <code>. */
function force_error_id(int $code): string
{
    return '__err_' . $code;
}

/**
 * Drive the AIChatClient through every corpus step against the mock.
 *
 * @return array<string, array<string, mixed>>
 */
function run(string $url): array
{
    $out = [];
    $client = new AIChatClient(url: $url);

    // ── success steps ────────────────────────────────────────────────────
    $info = $client->createConversation('conv-1', configUrl: 'http://cfg', timeout: 30, reinit: true);
    $out['create'] = [
        'wire_method' => 'create_conversation',
        'decoded' => ['status' => $info->status, 'id' => $info->id, 'initial_message' => $info->initialMessage],
    ];

    $reply = $client->chat('conv-1', 'hello', timeout: 30, reinit: true);
    $out['chat'] = [
        'wire_method' => 'chat',
        'decoded' => ['response' => $reply->text, 'user_event' => $reply->userEvent],
    ];

    // end/delete return bool idiomatically; the wire result also carries the
    // conversation id (the caller's own input, echoed). Report both the derived
    // status and the id operated on — mirroring the reference dump.
    $ended = $client->end('conv-1');
    $out['end'] = [
        'wire_method' => 'end_conversation',
        'decoded' => ['status' => $ended ? 'ended' : '?', 'id' => 'conv-1'],
    ];

    $deleted = $client->delete('conv-1');
    $out['delete'] = [
        'wire_method' => 'delete',
        'decoded' => ['status' => $deleted ? 'deleted' : '?', 'id' => 'conv-1'],
    ];

    $log = $client->log('conv-1');
    $out['log'] = [
        'wire_method' => 'chat_log',
        'decoded' => ['chat_log' => $log->messages, 'call_timeline' => $log->callTimeline],
    ];

    $summary = $client->summarize('conv-1');
    $out['summarize'] = ['wire_method' => 'summarize', 'decoded' => ['summary' => $summary]];

    // ── summarize one_of {error} branch: must SURFACE, not swallow ───────
    try {
        $swallowed = $client->summarize(SUMMARIZE_ERROR_ID);
        $out['summarize_failed'] = [
            'wire_method' => 'summarize',
            'raised' => false,
            'decoded' => ['summary' => $swallowed],
        ];
    } catch (SummaryError $e) {
        $out['summarize_failed'] = [
            'wire_method' => 'summarize',
            'raised' => true,
            'error_type' => (new \ReflectionClass($e))->getShortName(),
            'message' => $e->getServerMessage(),
        ];
    }

    // ── error-code steps (JSON-RPC error object) ─────────────────────────
    foreach (ERROR_STEPS as $step => $code) {
        try {
            $client->chat(force_error_id($code), 'x');
            $out[$step] = ['raised' => false];
        } catch (AIChatError $e) {
            $out[$step] = [
                'raised' => true,
                'error_code' => $e->getErrorCode(),
                'error_type' => (new \ReflectionClass($e))->getShortName(),
            ];
        }
    }

    return $out;
}

$url = getenv('MOCK_AI_CHAT_URL');
if (!is_string($url) || $url === '') {
    fwrite(STDERR, "MOCK_AI_CHAT_URL not set\n");
    exit(2);
}

try {
    $out = run($url);
} catch (\Throwable $err) {
    fwrite(STDERR, 'ai-chat-dump: ' . $err . "\n");
    exit(1);
}

// Discard anything buffered during autoload; emit ONLY the JSON object.
ob_end_clean();
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit(0);
