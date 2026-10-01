<?php

declare(strict_types=1);

/*
 * Copyright (c) 2026 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 *
 * recording-router.php — a `php -S` router that journals each request body the
 * AI Chat mock receives, then hands the request to the shared
 * bin/ai-chat-mock-router.php unchanged. The answers are the shared mock's; this
 * file only adds a journal, so a test can assert what a caller actually put on
 * the wire. The journal path comes from AI_CHAT_MOCK_JOURNAL (one JSON body per
 * line).
 */

$journal = getenv('AI_CHAT_MOCK_JOURNAL');
if (is_string($journal) && $journal !== '') {
    $raw = file_get_contents('php://input');
    $line = str_replace("\n", ' ', is_string($raw) ? $raw : '');
    file_put_contents($journal, $line . "\n", FILE_APPEND | LOCK_EX);
}

require dirname(__DIR__, 3) . '/bin/ai-chat-mock-router.php';
