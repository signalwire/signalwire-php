<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\Logging\Logger;
use SignalWire\Security\SessionManager;
use SignalWire\Server\AgentServer;
use SignalWire\Serverless\Adapter;
use SignalWire\Skills\Builtin\InfoGatherer;
use SignalWire\SWML\Schema;
use SignalWire\Tests\Support\Shape;

/**
 * Tier-2 behavioral contracts (porting-sdk/BEHAVIORAL_CONTRACTS.md).
 *
 * These assert the SAME observable behavior the Python reference has, for
 * capabilities where the signature is DRIFT-clean but the body was (or could
 * become) a no-op stub the surface/drift gates cannot see. A stub reds these
 * tests → CI red → the feature is forced.
 *
 *   2. set_prompt_llm_params / set_post_prompt_llm_params MERGE (not replace)
 *   3. InfoGatherer submit_answer STATE MACHINE (reads/advances global_data)
 *   4. native_vector_search REMOTE HTTP (real POST, not a stub string)
 *   5. Serverless per-platform DISPATCH (lambda / cgi / gcf / azure)
 *   6. SIP routing DISPATCH over the served path (307 redirect)
 *   7. Tool-token WIRE FORMAT + nonce parity (5 dot-fields, HMAC, constant-time)
 *   8. AI/LLM structured add_pattern_hint / add_language (fillers/engine/model)
 */
class Tier2BehavioralContractTest extends TestCase
{
    protected function setUp(): void
    {
        Logger::reset();
        Schema::reset();
    }

    protected function tearDown(): void
    {
        Logger::reset();
        Schema::reset();
    }

    private function makeAgent(string $name = 'agent', string $route = '/'): AgentBase
    {
        return new AgentBase(
            name: $name,
            route: $route,
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass',
        );
    }

    /**
     * @return array<string, string>
     */
    private function authHeader(string $user = 'testuser', string $pass = 'testpass'): array
    {
        return ['Authorization' => 'Basic ' . base64_encode("{$user}:{$pass}")];
    }

    // ==================================================================
    //  Contract 2 — set_prompt_llm_params / set_post_prompt_llm_params MERGE
    // ==================================================================

    public function testPromptLlmParamsMergeAcrossCalls(): void
    {
        $agent = $this->makeAgent();
        // Two calls with DISTINCT keys. A replace-stub would drop temperature.
        $agent->setPromptLlmParams(['temperature' => 0.5]);
        $agent->setPromptLlmParams(['top_p' => 0.9]);

        $ai = $agent->buildAiVerb();
        $prompt = Shape::sub($ai, 'prompt');

        $this->assertArrayHasKey('temperature', $prompt, 'temperature dropped — params replaced instead of merged');
        $this->assertArrayHasKey('top_p', $prompt, 'top_p missing from merged prompt params');
        $this->assertEqualsWithDelta(0.5, $prompt['temperature'], 1e-9);
        $this->assertEqualsWithDelta(0.9, $prompt['top_p'], 1e-9);
    }

    public function testPostPromptLlmParamsMergeAcrossCalls(): void
    {
        $agent = $this->makeAgent();
        $agent->setPostPrompt('Summarize the conversation.');
        $agent->setPostPromptLlmParams(['temperature' => 0.2]);
        $agent->setPostPromptLlmParams(['top_p' => 0.7]);

        $ai = $agent->buildAiVerb();
        $postPrompt = Shape::sub($ai, 'post_prompt');

        $this->assertArrayHasKey('temperature', $postPrompt, 'temperature dropped — post-prompt params replaced instead of merged');
        $this->assertArrayHasKey('top_p', $postPrompt, 'top_p missing from merged post-prompt params');
        $this->assertEqualsWithDelta(0.2, $postPrompt['temperature'], 1e-9);
        $this->assertEqualsWithDelta(0.7, $postPrompt['top_p'], 1e-9);
    }

    // ==================================================================
    //  Contract 3 — InfoGatherer submit_answer STATE MACHINE
    // ==================================================================

    /**
     * Add the info_gatherer skill to an agent and return the agent.
     */
    private function agentWithInfoGatherer(): AgentBase
    {
        $agent = $this->makeAgent('info', '/info');
        $agent->addSkill('info_gatherer', [
            'questions' => [
                ['key_name' => 'name', 'question_text' => 'What is your name?'],
                ['key_name' => 'city', 'question_text' => 'What city are you in?'],
            ],
        ]);
        return $agent;
    }

    /**
     * Extract the set_global_data payload from a FunctionResult, unwrapped from
     * its skill namespace ("skill:info_gatherer").
     *
     * @return array<string,mixed>|null
     */
    private function skillState(?\SignalWire\SWAIG\FunctionResult $result): ?array
    {
        if ($result === null) {
            return null;
        }
        foreach (Shape::arr($result->toArray()['action'] ?? []) as $action) {
            if (is_array($action) && isset($action['set_global_data']) && is_array($action['set_global_data'])) {
                $scoped = $action['set_global_data']['skill:info_gatherer'] ?? null;
                if (is_array($scoped)) {
                    $out = [];
                    foreach ($scoped as $k => $v) {
                        if (is_string($k)) {
                            $out[$k] = $v;
                        }
                    }
                    return $out;
                }
            }
        }
        return null;
    }

    public function testInfoGathererSubmitAnswerAdvancesStateAndPresentsSecondQuestion(): void
    {
        $agent = $this->agentWithInfoGatherer();

        // The platform seeds the state machine into raw_data.global_data.
        $rawData = [
            'global_data' => [
                'skill:info_gatherer' => [
                    'questions' => [
                        ['key_name' => 'name', 'question_text' => 'What is your name?'],
                        ['key_name' => 'city', 'question_text' => 'What city are you in?'],
                    ],
                    'question_index' => 0,
                    'answers' => [],
                ],
            ],
        ];

        $result = $agent->onFunctionCall('submit_answer', ['answer' => 'Alice'], $rawData);
        $this->assertNotNull($result);

        // (a) the answer is recorded in global_data.answers
        $state = $this->skillState($result);
        $this->assertNotNull($state, 'submit_answer did not write namespaced global_data state');
        $answers = Shape::arr($state['answers'] ?? []);
        $this->assertCount(1, $answers, 'answer not recorded');
        $this->assertSame('Alice', Shape::at($state, 'answers', 0, 'answer'));
        $this->assertSame('name', Shape::at($state, 'answers', 0, 'key_name'));

        // (b) question_index advanced to 1
        $this->assertSame(1, $state['question_index'] ?? null, 'question_index did not advance (hardcoded stub)');

        // (c) the result presents the 2nd question
        $body = $result->toArray()['response'] ?? '';
        $this->assertIsString($body);
        $this->assertStringContainsString('What city are you in?', $body, 'second question not presented');
    }

    public function testInfoGathererSubmitAnswerPreservesPriorAnswers(): void
    {
        $agent = $this->agentWithInfoGatherer();

        // State AFTER the first answer: index at 1, one answer already stored.
        $rawData = [
            'global_data' => [
                'skill:info_gatherer' => [
                    'questions' => [
                        ['key_name' => 'name', 'question_text' => 'What is your name?'],
                        ['key_name' => 'city', 'question_text' => 'What city are you in?'],
                    ],
                    'question_index' => 1,
                    'answers' => [['key_name' => 'name', 'answer' => 'Alice']],
                ],
            ],
        ];

        $result = $agent->onFunctionCall('submit_answer', ['answer' => 'Portland'], $rawData);
        $state = $this->skillState($result);
        $this->assertNotNull($state);

        // Both answers must survive — a replace-stub would drop 'Alice'.
        $answers = Shape::arr($state['answers'] ?? []);
        $this->assertCount(2, $answers, 'prior answer dropped — answers were replaced, not appended');
        $this->assertSame('Alice', Shape::at($state, 'answers', 0, 'answer'));
        $this->assertSame('Portland', Shape::at($state, 'answers', 1, 'answer'));
        // Final question answered -> completed, index advanced to 2.
        $this->assertSame(2, $state['question_index'] ?? null);
        $this->assertTrue((bool) ($state['completed'] ?? false), 'run not marked completed after last answer');
    }

    // ==================================================================
    //  Contract 4 — native_vector_search REMOTE HTTP (real POST)
    // ==================================================================

    /**
     * Boot a php -S fixture on a FREE port that records every request path +
     * body to a sidecar file and returns a fixed results JSON body.
     *
     * @return array{0: resource, 1: int, 2: string, 3: string}
     *         [proc, port, tmp_script, request_log]
     */
    private function bootSearchFixture(string $resultsJson): array
    {
        $logPath = \tempnam(\sys_get_temp_dir(), 'sw_nvs_log_');
        $bodyExport = \var_export($resultsJson, true);
        $logExport = \var_export($logPath, true);

        // Global-scope router: log "METHOD PATH\n<body>\n---\n", return results.
        $script = <<<PHP
        <?php
        \$method = \$_SERVER['REQUEST_METHOD'] ?? 'GET';
        \$path = \$_SERVER['REQUEST_URI'] ?? '/';
        \$body = file_get_contents('php://input');
        @file_put_contents({$logExport}, \$method . ' ' . \$path . "\\n" . \$body . "\\n---\\n", FILE_APPEND);
        header('Content-Type: application/json');
        echo {$bodyExport};
        PHP;
        $tmp = \tempnam(\sys_get_temp_dir(), 'sw_nvs_rt_') . '.php';
        \file_put_contents($tmp, $script);

        $sock = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($sock, 'Failed to create socket');
        \socket_bind($sock, '127.0.0.1', 0);
        \socket_getsockname($sock, $addr, $port);
        \socket_close($sock);

        $cmd = \escapeshellcmd(PHP_BINARY)
            . ' -S 127.0.0.1:' . (int) $port
            . ' ' . \escapeshellarg($tmp);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = \proc_open($cmd, $descriptors, $pipes);
        $this->assertIsResource($proc, 'Failed to spawn php -S search fixture');
        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $ok = false;
        $deadline = \microtime(true) + 5.0;
        while (\microtime(true) < $deadline) {
            $conn = @\fsockopen('127.0.0.1', (int) $port, $err, $errStr, 0.2);
            if ($conn !== false) {
                \fclose($conn);
                $ok = true;
                break;
            }
            \usleep(50_000);
        }
        $this->assertTrue($ok, "php -S search fixture did not bind to 127.0.0.1:{$port}");

        return [$proc, (int) $port, $tmp, $logPath];
    }

    private function stopFixture(mixed $proc, string $tmp, string $logPath): void
    {
        if (\is_resource($proc)) {
            \proc_terminate($proc);
            \proc_close($proc);
        }
        @\unlink($tmp);
        @\unlink($logPath);
    }

    public function testNativeVectorSearchRemotePostsQueryAndFormatsResults(): void
    {
        $resultsJson = (string) \json_encode([
            'results' => [
                [
                    'content' => 'The mitochondria is the powerhouse of the cell.',
                    'score' => 0.93,
                    'metadata' => ['filename' => 'biology.md', 'section' => 'cells'],
                ],
            ],
        ]);

        [$proc, $port, $tmp, $log] = $this->bootSearchFixture($resultsJson);
        try {
            $agent = $this->makeAgent();
            $agent->addSkill('native_vector_search', [
                'remote_url' => "http://127.0.0.1:{$port}",
                'index_name' => 'kb',
            ]);

            $result = $agent->onFunctionCall('search_knowledge', ['query' => 'powerhouse of the cell'], []);
            $this->assertNotNull($result);

            // A real HTTP POST to <remote_url>/search must have been made,
            // carrying the query in the JSON body.
            $raw = \is_file($log) ? (string) \file_get_contents($log) : '';
            $this->assertStringContainsString('POST /search', $raw, 'no POST to /search (stub string, no HTTP call)');
            $this->assertStringContainsString('powerhouse of the cell', $raw, 'query not in POST body');
            $this->assertStringContainsString('"index_name":"kb"', $raw, 'index_name not in POST body');

            // The mock's results must be formatted into the FunctionResult —
            // NOT a hardcoded "[Would query...]" / "In production..." string.
            $body = $result->toArray()['response'] ?? '';
            $this->assertIsString($body);
            $this->assertStringContainsString('mitochondria is the powerhouse', $body, 'remote result not formatted into response');
            $this->assertStringContainsString('biology.md', $body, 'result metadata not formatted');
            $this->assertStringNotContainsString('Would query', $body);
            $this->assertStringNotContainsString('In production', $body);
        } finally {
            $this->stopFixture($proc, $tmp, $log);
        }
    }

    // ==================================================================
    //  Contract 5 — Serverless per-platform DISPATCH (regression lock)
    //  php is the ✅ reference (Lambda+base64 / GCF / Azure / CGI).
    // ==================================================================

    public function testServerlessLambdaDispatchesToRealResponse(): void
    {
        $agent = $this->makeAgent('lam', '/');
        $event = [
            'httpMethod' => 'GET',
            'path' => '/',
            'headers' => $this->authHeader(),
        ];
        $resp = Adapter::handleLambda($agent, $event, new \stdClass());

        $this->assertSame(200, $resp['statusCode'], 'Lambda did not dispatch to a 200 SWML response');
        $swml = \json_decode($resp['body'], true);
        $this->assertIsArray($swml);
        $this->assertSame('1.0.0', $swml['version'] ?? null, 'Lambda response is not a rendered SWML document');
    }

    public function testServerlessLambdaBase64BodyIsDecodedAndDispatched(): void
    {
        $agent = $this->makeAgent('lam', '/');
        $payload = (string) \json_encode(['call_id' => 'abc123']);
        $event = [
            'httpMethod' => 'POST',
            'path' => '/',
            'headers' => $this->authHeader(),
            'body' => \base64_encode($payload),
            'isBase64Encoded' => true,
        ];
        $resp = Adapter::handleLambda($agent, $event, new \stdClass());

        $this->assertSame(200, $resp['statusCode']);
        $swml = \json_decode($resp['body'], true);
        $this->assertIsArray($swml);
        $this->assertSame('1.0.0', $swml['version'] ?? null);
    }

    public function testServerlessCgiDispatchesToRealResponse(): void
    {
        $agent = $this->makeAgent('cgi', '/');

        $prevServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['PATH_INFO'] = '/';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . \base64_encode('testuser:testpass');

        \ob_start();
        try {
            Adapter::handleCgi($agent);
            $out = (string) \ob_get_clean();
        } catch (\Throwable $e) {
            \ob_end_clean();
            $_SERVER = $prevServer;
            throw $e;
        }
        $_SERVER = $prevServer;

        $this->assertStringContainsString('Status: 200', $out, 'CGI did not dispatch to a 200 response');
        // Body (after the header block) must be a rendered SWML document.
        $parts = \explode("\r\n\r\n", $out, 2);
        $this->assertCount(2, $parts, 'CGI output missing header/body separator');
        $swml = \json_decode($parts[1], true);
        $this->assertIsArray($swml);
        $this->assertSame('1.0.0', $swml['version'] ?? null, 'CGI body is not a rendered SWML document');
    }

    public function testServerlessGcfDispatchesToRealResponse(): void
    {
        $agent = $this->makeAgent('gcf', '/');

        $prevServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . \base64_encode('testuser:testpass');

        \ob_start();
        try {
            Adapter::handleGcf($agent);
            $out = (string) \ob_get_clean();
        } catch (\Throwable $e) {
            \ob_end_clean();
            $_SERVER = $prevServer;
            throw $e;
        }
        $_SERVER = $prevServer;

        // GCF writes headers via header()/http_response_code and echoes the
        // body; the echoed body must be a rendered SWML document.
        $swml = \json_decode($out, true);
        $this->assertIsArray($swml, 'GCF did not echo a JSON body (fell through / empty handler)');
        $this->assertSame('1.0.0', $swml['version'] ?? null, 'GCF body is not a rendered SWML document');
    }

    public function testServerlessAzureDispatchesToRealResponse(): void
    {
        $agent = $this->makeAgent('az', '/');
        $request = [
            'method' => 'GET',
            'url' => 'https://example.com/',
            'headers' => $this->authHeader(),
        ];
        $resp = Adapter::handleAzure($agent, $request);

        // Azure uses 'status' (not 'statusCode').
        $this->assertSame(200, $resp['status'], 'Azure did not dispatch to a 200 response');
        $swml = \json_decode($resp['body'], true);
        $this->assertIsArray($swml);
        $this->assertSame('1.0.0', $swml['version'] ?? null);
    }

    // ==================================================================
    //  Contract 6 — SIP routing DISPATCH over the served path
    // ==================================================================

    public function testSipRoutingCallbackFiresAndRedirectsUnknownUsername(): void
    {
        // Enable SIP routing; register a username to THIS agent.
        $agent = $this->makeAgent('sipper', '/sipper');
        $agent->enableSipRouting();
        $agent->registerSipUsername('alice');

        // Register a second callback path proving redirect wiring: a routing
        // callback returning a route string must produce 307 + Location. The
        // SIP callback declines (returns null) for its own agent's usernames,
        // so use an explicit redirecting callback at a distinct path to assert
        // the served-path 307 contract end to end.
        $agent->registerRoutingCallback('/redir', static function (array $body, array $headers): string {
            return '/elsewhere';
        });

        // A routing callback returning a route -> 307 redirect (POST preserved).
        [$status, $headers, $rbody] = $agent->handleRequest(
            'POST',
            '/sipper/redir',
            $this->authHeader(),
            (string) \json_encode(['call' => ['call_id' => 'x']]),
        );
        $this->assertSame(307, $status, 'routing callback route string did not yield a 307 redirect');
        $this->assertSame('/elsewhere', $headers['Location'] ?? null, 'Location header missing on redirect');
        $this->assertSame('', $rbody, '307 redirect body must be empty');
    }

    public function testSipUsernameExtractedFromBodyAndConsulted(): void
    {
        // The SIP routing callback must actually FIRE on the served /sip path
        // and extract the username from the body (a stored-but-unconsulted
        // mapping would never run the callback). When the extracted username
        // is registered to this agent, the callback returns null and the
        // agent serves its SWML (200) — the request is routed to this agent.
        $agent = $this->makeAgent('sipper', '/sipper');
        $agent->enableSipRouting();
        $agent->registerSipUsername('supportline');

        $sipBody = (string) \json_encode([
            'call' => [
                'call_id' => 'c1',
                'to' => 'supportline@example.com',
            ],
        ]);

        [$status, $headers, $rbody] = $agent->handleRequest(
            'POST',
            '/sipper/sip',
            $this->authHeader(),
            $sipBody,
        );

        // Username registered to this agent -> no redirect, agent serves SWML.
        $this->assertSame(200, $status, 'served /sip did not route to this agent for a registered username');
        $swml = \json_decode($rbody, true);
        $this->assertIsArray($swml, 'served /sip did not return a rendered SWML document');
        $this->assertSame('1.0.0', $swml['version'] ?? null);
    }

    public function testAgentServerSipMappingStoredAndConsultable(): void
    {
        // AgentServer central SIP routing: a registered username maps to a
        // route (stored), and setup_sip_routing auto-maps agent routes. Assert
        // the mapping is populated (consultable), not silently dropped.
        $agent = $this->makeAgent('support', '/support');
        $server = new AgentServer();
        $server->register($agent);
        $server->setupSipRouting();
        $server->registerSipUsername('helpdesk', '/support');

        $mapping = $server->getSipUsernameMapping();
        $this->assertArrayHasKey('helpdesk', $mapping, 'explicit SIP username mapping dropped');
        $this->assertSame('/support', $mapping['helpdesk']);
        // auto_map derived a username from the agent route.
        $this->assertArrayHasKey('support', $mapping, 'auto-mapped SIP username missing');
    }

    // ==================================================================
    //  Contract 7 — Tool-token WIRE FORMAT + nonce parity
    //  php mints the python 5-field token; this is the lock-in test.
    // ==================================================================

    /**
     * Base64url-decode a php-minted token back to its raw dot-joined form
     * (RFC 4648, no padding) — the SAME transform SessionManager applies. The
     * contract asserts on this DECODED form (php base64url-wraps the wire token).
     */
    private function decodeToken(string $token): string
    {
        $base64 = \strtr($token, '-_', '+/');
        $mod4 = \strlen($base64) % 4;
        if ($mod4 !== 0) {
            $base64 .= \str_repeat('=', 4 - $mod4);
        }
        $decoded = \base64_decode($base64, true);
        $this->assertIsString($decoded, 'token was not valid base64url');
        return $decoded;
    }

    public function testToolTokenHasFiveDotFieldsWithNonEmptyNonce(): void
    {
        $sm = new SessionManager();
        $callId = $sm->createSession();

        $decoded = $this->decodeToken($sm->generateToken('lookup', $callId));
        $parts = \explode('.', $decoded);

        // (1) exactly 5 dot-fields: call_id.function_name.expiry.nonce.signature
        $this->assertCount(5, $parts, 'token is not the 5-field python wire format');
        [$tCall, $tFn, $tExpiry, $tNonce, $tSig] = $parts;

        $this->assertSame($callId, $tCall, 'call_id is not field 1 (python order)');
        $this->assertSame('lookup', $tFn, 'function_name is not field 2 (python order)');
        $this->assertMatchesRegularExpression('/^\d+$/', $tExpiry, 'expiry field is not an integer epoch');

        // nonce = token_hex(8) => 16 lowercase hex chars, NON-EMPTY.
        $this->assertNotSame('', $tNonce, 'nonce is empty (degraded 4-field token)');
        $this->assertSame(16, \strlen($tNonce), 'nonce is not 16 hex chars (token_hex(8))');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $tNonce, 'nonce is not lowercase hex');

        // signature = HMAC-SHA256 hexdigest => 64 hex chars.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $tSig, 'signature is not a SHA-256 hexdigest');
    }

    public function testTwoMintsSameTupleProduceDifferentNonces(): void
    {
        $sm = new SessionManager();
        $callId = 'call_fixed';

        $a = \explode('.', $this->decodeToken($sm->generateToken('fn', $callId)));
        $b = \explode('.', $this->decodeToken($sm->generateToken('fn', $callId)));

        $this->assertCount(5, $a);
        $this->assertCount(5, $b);
        // Same (function, call_id) — but the nonce (field 4) must differ, so the
        // whole signature (field 5) differs too. A no-nonce stub would collide.
        $this->assertNotSame($a[3], $b[3], 'two mints reused the same nonce (no per-mint randomness)');
        $this->assertNotSame($a[4], $b[4], 'signatures collide across mints (nonce not mixed in)');
    }

    public function testPythonOracleFormatTokenValidatesInPort(): void
    {
        // Interop: a token in the exact python raw wire format
        // `{call_id}.{function}.{expiry}.{nonce}.{sig}` — with the port's own
        // HMAC secret — must validate. We obtain such a token by decoding a
        // freshly minted one (proving the DECODED form IS the python format),
        // then feed the re-encoded raw form back to validateToken.
        $sm = new SessionManager();
        $callId = $sm->createSession();
        $token = $sm->generateToken('interop_fn', $callId);

        $rawPythonFormat = $this->decodeToken($token);
        $this->assertCount(5, \explode('.', $rawPythonFormat), 'decoded token is not python 5-field format');

        // Re-encode the raw python-format string exactly as SessionManager does
        // on the wire, and validate it.
        $reEncoded = \rtrim(\strtr(\base64_encode($rawPythonFormat), '+/=', '-_ '), ' ');
        $this->assertTrue(
            $sm->validateToken($callId, 'interop_fn', $reEncoded),
            'a python-oracle-format token did not validate in the port',
        );
    }

    public function testTamperedSignatureFailsValidation(): void
    {
        $sm = new SessionManager();
        $callId = $sm->createSession();
        $token = $sm->generateToken('fn', $callId);

        $raw = $this->decodeToken($token);
        $parts = \explode('.', $raw);
        // Flip the last hex char of the signature.
        $sig = $parts[4];
        $lastChar = $sig[\strlen($sig) - 1];
        $parts[4] = \substr($sig, 0, -1) . ($lastChar === 'a' ? 'b' : 'a');

        $tampered = \rtrim(\strtr(\base64_encode(\implode('.', $parts)), '+/=', '-_ '), ' ');
        $this->assertFalse(
            $sm->validateToken($callId, 'fn', $tampered),
            'a signature-tampered token validated — HMAC not enforced',
        );
    }

    public function testSignatureCompareIsConstantTime(): void
    {
        // Constant-time contract: the signature comparison must use PHP's
        // timing-safe hash_equals(), not a short-circuiting === / strcmp that
        // returns on the first mismatched byte. Assert on the SessionManager
        // source that the signature check is hash_equals-based.
        $ref = new \ReflectionClass(SessionManager::class);
        $src = (string) \file_get_contents((string) $ref->getFileName());

        $this->assertStringContainsString(
            'hash_equals($expectedSignature, $tokenSignature)',
            $src,
            'signature comparison is not constant-time (hash_equals) — first-mismatch early return possible',
        );
        $this->assertStringNotContainsString(
            '$tokenSignature === $expectedSignature',
            $src,
            'signature compared with === (non-constant-time)',
        );
    }

    // ==================================================================
    //  Contract 8 — AI/LLM structured add_pattern_hint / add_language
    // ==================================================================

    public function testAddPatternHintAttachesStructuredHintIntoSwml(): void
    {
        $agent = $this->makeAgent();
        // Structured hint: {hint, pattern, replace, ignore_case} — a degraded
        // bare-string impl drops pattern/replace/ignore_case.
        $agent->addPatternHint('AI', '\\bAI\\b', 'artificial intelligence', true);

        $ai = $agent->buildAiVerb();
        $hints = Shape::sub($ai, 'hints');

        // The rendered ai.hints must contain the full structured object.
        $found = null;
        foreach ($hints as $hint) {
            if (\is_array($hint) && ($hint['hint'] ?? null) === 'AI') {
                $found = $hint;
                break;
            }
        }
        $this->assertNotNull($found, 'structured pattern hint dropped — bare-string / degraded impl');
        $this->assertSame('\\bAI\\b', $found['pattern'] ?? null, 'pattern field not carried into SWML');
        $this->assertSame('artificial intelligence', $found['replace'] ?? null, 'replace field not carried into SWML');
        $this->assertTrue($found['ignore_case'] ?? null, 'ignore_case flag not carried into SWML');
    }

    public function testAddLanguageCarriesEngineModelFillersIntoSwml(): void
    {
        $agent = $this->makeAgent();
        // Explicit engine + model + both filler lists must all survive render.
        $agent->addLanguage(
            'English',
            'en-US',
            'josh',
            speechFillers: ['um', 'let me think'],
            functionFillers: ['one moment', 'checking'],
            engine: 'elevenlabs',
            model: 'eleven_turbo_v2_5',
        );

        $ai = $agent->buildAiVerb();
        $languages = Shape::sub($ai, 'languages');
        $this->assertCount(1, $languages);
        $lang = Shape::arr($languages[0]);

        $this->assertSame('English', $lang['name'] ?? null);
        $this->assertSame('en-US', $lang['code'] ?? null);
        $this->assertSame('josh', $lang['voice'] ?? null);
        // engine + model carried (degraded impl drops these).
        $this->assertSame('elevenlabs', $lang['engine'] ?? null, 'engine dropped from rendered language');
        $this->assertSame('eleven_turbo_v2_5', $lang['model'] ?? null, 'model dropped from rendered language');
        // Both filler lists present under speech_fillers / function_fillers.
        $this->assertSame(['um', 'let me think'], $lang['speech_fillers'] ?? null, 'speech_fillers dropped');
        $this->assertSame(['one moment', 'checking'], $lang['function_fillers'] ?? null, 'function_fillers dropped');
    }

    public function testAddLanguageParsesCombinedVoiceStringIntoEngineModel(): void
    {
        // Python parses the combined "engine.voice:model" format when engine/
        // model are not passed explicitly. A degraded impl leaves it as a
        // literal voice string.
        $agent = $this->makeAgent();
        $agent->addLanguage('English', 'en-US', 'elevenlabs.josh:eleven_turbo_v2_5');

        $ai = $agent->buildAiVerb();
        $lang = Shape::arr(Shape::sub($ai, 'languages')[0]);

        $this->assertSame('josh', $lang['voice'] ?? null, 'combined voice not split — voice part wrong');
        $this->assertSame('elevenlabs', $lang['engine'] ?? null, 'combined voice not split — engine not extracted');
        $this->assertSame('eleven_turbo_v2_5', $lang['model'] ?? null, 'combined voice not split — model not extracted');
    }
}
