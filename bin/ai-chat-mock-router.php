<?php

declare(strict_types=1);

/*
 * Copyright (c) 2026 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 *
 * mock-router.php — a tiny PHP built-in-server router that mirrors the shared
 * porting-sdk mock_ai_chat contract (canned per-method results, sentinel-driven
 * errors, the summarize {error} one_of branch, HTTP-Basic identity gate). It
 * backs the AIChatClientTest so the unit tests exercise the REAL cURL transport
 * against a REAL socket without any SDK-internal mocking. Booted via `php -S`.
 */

$canned = [
    'create_conversation' => ['status' => 'created', 'id' => 'conv-1', 'initial_message' => 'hello'],
    'chat' => ['response' => 'hi there', 'user_event' => ['event_type' => 'demo', 'n' => 1]],
    'end_conversation' => ['status' => 'ended', 'id' => 'conv-1'],
    'delete' => ['status' => 'deleted', 'id' => 'conv-1'],
    'chat_log' => ['chat_log' => [['role' => 'user', 'content' => 'm']], 'call_timeline' => [['t' => 1]]],
    'summarize' => ['summary' => 'a concise summary'],
];

$send = static function (array $obj, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($obj);
};

$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
if ($path !== '/api/ai/chat') {
    $send(['jsonrpc' => '2.0', 'error' => ['code' => -32601, 'message' => 'unknown path'], 'id' => null], 404);
    return;
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!is_string($auth) || !str_starts_with($auth, 'Basic ')) {
    $send(['jsonrpc' => '2.0', 'error' => ['code' => -32009, 'message' => 'authentication required'], 'id' => null], 401);
    return;
}

$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    $send(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'invalid JSON'], 'id' => null]);
    return;
}

$rid = $data['id'] ?? null;
$method = $data['method'] ?? '';
$params = is_array($data['params'] ?? null) ? $data['params'] : [];
$cid = $params['id'] ?? null;

// Sentinel-driven forced error: id "__err_<code>".
if (is_string($cid) && str_starts_with($cid, '__err_')) {
    $code = (int) substr($cid, strlen('__err_'));
    $send(
        ['jsonrpc' => '2.0', 'error' => ['code' => $code, 'message' => 'forced error'], 'id' => $rid],
        $code === -32009 ? 401 : 200
    );
    return;
}

// summarize {error} one_of branch rides the SUCCESS envelope.
if ($method === 'summarize' && $cid === '__summarize_error') {
    $send(['jsonrpc' => '2.0', 'result' => ['error' => 'Failed to generate summary'], 'id' => $rid]);
    return;
}

if (!isset($canned[$method]) || !is_string($method)) {
    $send(['jsonrpc' => '2.0', 'error' => ['code' => -32601, 'message' => 'unknown method'], 'id' => $rid]);
    return;
}

$send(['jsonrpc' => '2.0', 'result' => $canned[$method], 'id' => $rid]);
