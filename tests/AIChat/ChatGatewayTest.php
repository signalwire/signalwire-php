<?php

declare(strict_types=1);

/*
 * Copyright (c) 2026 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

namespace SignalWire\Tests\AIChat;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignalWire\AIChat\AIChatClient;
use SignalWire\AIChat\ChatGateway;
use SignalWire\AIChat\GatewayRejection;

/**
 * ChatGateway: what a browser holding a publishable key can and cannot do.
 *
 * The gateway exists so a widget never holds a SignalWire API token. These
 * tests pin the boundary that makes that safe — what the browser may name,
 * what the gateway overwrites, and what the caps bound — against the shared
 * AI Chat mock (a real `php -S` socket, journalled so the upstream call can be
 * read back).
 */
final class ChatGatewayTest extends TestCase
{
    private const CONFIG_URL = 'https://agent.example.com/swml';
    private const KEY = 'pk_test_key';
    private const ORIGIN = 'https://shop.example.com';

    /** Page context a widget volunteers. */
    private const PAGE = [
        'capabilities' => ['widget' => 'signalwire-address', 'medium' => 'chat'],
        'metadata' => ['page' => ['url' => 'https://shop.example.com/pricing', 'title' => 'Pricing']],
    ];

    private static ?RecordingAIChatMock $mock = null;

    public static function setUpBeforeClass(): void
    {
        self::$mock = RecordingAIChatMock::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$mock?->stop();
        self::$mock = null;
    }

    private static function mock(): RecordingAIChatMock
    {
        if (self::$mock === null) {
            self::fail('mock not started');
        }
        return self::$mock;
    }

    private static function client(): AIChatClient
    {
        return new AIChatClient(project: 'p', token: 't', url: self::mock()->url, readIdleTimeoutSeconds: 0);
    }

    /** The gateway most tests use: one listed origin, a fixed secret. */
    private static function gateway(): ChatGateway
    {
        return new ChatGateway(
            configUrl: self::CONFIG_URL,
            key: self::KEY,
            allowedOrigins: [self::ORIGIN],
            client: self::client(),
            secret: 'test-secret',
        );
    }

    /**
     * A gateway wired to the mock, with overrides.
     *
     * @param list<string> $allowedOrigins
     */
    private static function makeGateway(
        string $secret = 's',
        int $handleTtl = ChatGateway::DEFAULT_HANDLE_TTL,
        ?int $conversationTimeout = null,
        int $maxNewConversations = ChatGateway::DEFAULT_MAX_NEW_CONVERSATIONS,
        int $maxTurns = ChatGateway::DEFAULT_MAX_TURNS,
        array $allowedOrigins = [],
    ): ChatGateway {
        return new ChatGateway(
            configUrl: self::CONFIG_URL,
            key: self::KEY,
            allowedOrigins: $allowedOrigins,
            client: self::client(),
            secret: $secret,
            handleTtl: $handleTtl,
            conversationTimeout: $conversationTimeout,
            maxNewConversations: $maxNewConversations,
            maxTurns: $maxTurns,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array{string, array<string, mixed>, string|null}
     */
    private static function prep(ChatGateway $gw, array $body, ?string $origin = self::ORIGIN): array
    {
        return $gw->prepare($body, origin: $origin, key: self::KEY);
    }

    private static function rejection(callable $fn): GatewayRejection
    {
        try {
            $fn();
        } catch (GatewayRejection $rej) {
            return $rej;
        }
        self::fail('expected a GatewayRejection');
    }

    /**
     * POST through the mounted router.
     *
     * @param array<string, mixed>|string $body
     * @param array<string, string>        $headers
     * @return array{int, array<string, string>, string}
     */
    private static function post(ChatGateway $gw, array|string $body, array $headers, string $path = '/'): array
    {
        $raw = is_string($body) ? $body : (string) json_encode($body);
        return ($gw->router())('POST', $path, $headers, $raw);
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return ['Authorization' => 'Bearer ' . self::KEY, 'Origin' => self::ORIGIN];
    }

    /**
     * @param array<string, string> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }

    /** @return array<array-key, mixed> */
    private static function json(string $body): array
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, 'body is not a JSON object: ' . $body);
        return $decoded;
    }

    // ── Construction ─────────────────────────────────────────────────

    public function testConfigUrlIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChatGateway(configUrl: '', key: self::KEY, client: self::client());
    }

    public function testAKeyIsGeneratedWhenNoneIsGiven(): void
    {
        $saved = getenv('SIGNALWIRE_CHAT_GATEWAY_KEY');
        putenv('SIGNALWIRE_CHAT_GATEWAY_KEY');
        try {
            $gw = new ChatGateway(configUrl: self::CONFIG_URL, client: self::client());
            $this->assertStringStartsWith('pk_', $gw->key);
            $this->assertGreaterThan(20, strlen($gw->key));
        } finally {
            if ($saved !== false) {
                putenv('SIGNALWIRE_CHAT_GATEWAY_KEY=' . $saved);
            }
        }
    }

    public function testConfigurationIsReadable(): void
    {
        $gw = new ChatGateway(
            configUrl: self::CONFIG_URL,
            key: self::KEY,
            allowedOrigins: ['https://shop.example.com/'],
            client: self::client(),
            handleTtl: 10,
            conversationTimeout: 900,
            maxNewConversations: 5,
            maxTurns: 7,
            windowSeconds: 30,
        );
        $this->assertSame(self::CONFIG_URL, $gw->configUrl);
        $this->assertSame(self::KEY, $gw->key);
        $this->assertSame(['https://shop.example.com'], $gw->allowedOrigins);
        $this->assertSame(10, $gw->handleTtl);
        $this->assertSame(900, $gw->conversationTimeout);
        $this->assertSame(5, $gw->maxNewConversations);
        $this->assertSame(7, $gw->maxTurns);
        $this->assertSame(30, $gw->windowSeconds);
        $this->assertSame(900, $gw->effectiveTimeout());
    }

    public function testCloseLeavesACallerOwnedClientUsable(): void
    {
        $client = self::client();
        $gw = new ChatGateway(configUrl: self::CONFIG_URL, key: self::KEY, client: $client);
        $gw->close();
        $this->assertSame('created', $client->createConversation('conv-x', configUrl: self::CONFIG_URL)->status);
    }

    // ── Handles ──────────────────────────────────────────────────────

    public function testAHandleRoundTrips(): void
    {
        $gw = self::gateway();
        $this->assertStringStartsWith('chat-', $gw->readHandle($gw->mintHandle()));
    }

    public function testAMintedHandleCarriesTheGivenConversationId(): void
    {
        $gw = self::gateway();
        $this->assertSame('conv-root.5', $gw->readHandle($gw->mintHandle('conv-root.5')));
    }

    public function testTheBrowserCannotForgeAConversation(): void
    {
        $gw = self::gateway();
        $tampered = explode('.', $gw->mintHandle())[0] . '.AAAA';
        $rej = self::rejection(static fn () => $gw->readHandle($tampered));
        $this->assertSame(403, $rej->status);
        $this->assertSame('invalid handle', $rej->reason);
    }

    public function testAHandleFromAnotherGatewayIsRefused(): void
    {
        $gw = self::gateway();
        $other = self::makeGateway(secret: 'different');
        $rej = self::rejection(static fn () => $gw->readHandle($other->mintHandle()));
        $this->assertSame(403, $rej->status);
    }

    public function testAnExpiredHandleIsRefused(): void
    {
        $gw = self::makeGateway(handleTtl: -1);
        $rej = self::rejection(static fn () => $gw->readHandle($gw->mintHandle()));
        $this->assertSame(403, $rej->status);
        $this->assertSame('expired handle', $rej->reason);
    }

    public function testAMalformedHandleIsNamedMalformed(): void
    {
        $rej = self::rejection(static fn () => self::gateway()->readHandle('not-a-handle'));
        $this->assertSame(400, $rej->status);
        $this->assertSame('malformed handle', $rej->reason);
    }

    public function testGarbageIsRefused(): void
    {
        $gw = self::gateway();
        foreach (['', 'not-a-handle', 'a.b.c', '!!!.!!!'] as $bad) {
            $rej = self::rejection(static fn () => $gw->readHandle($bad));
            $this->assertContains($rej->status, [400, 403], $bad);
        }
    }

    // ── Origin ───────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function localOrigins(): array
    {
        return [
            'localhost' => ['http://localhost:3000'],
            'loopback' => ['http://127.0.0.1:8080'],
            'subdomain' => ['http://app.localhost'],
            'ipv6' => ['http://[::1]:3000'],
        ];
    }

    #[DataProvider('localOrigins')]
    public function testLocalhostNeverNeedsListing(string $origin): void
    {
        $gw = self::gateway();
        $gw->checkOrigin($origin);
        // The exemption is localhost-specific, not open-by-default.
        $rej = self::rejection(static fn () => $gw->checkOrigin('https://evil.example.com'));
        $this->assertSame(403, $rej->status);
    }

    public function testAListedOriginIsAllowedButANearMissIsNot(): void
    {
        $gw = self::gateway();
        $gw->checkOrigin(self::ORIGIN);
        $gw->checkOrigin(self::ORIGIN . '/');
        $rej = self::rejection(static fn () => $gw->checkOrigin('https://shop.example.com.evil.test'));
        $this->assertSame(403, $rej->status);
        $this->assertSame('origin not allowed', $rej->reason);
    }

    public function testAMissingOriginIsAllowed(): void
    {
        $gw = self::gateway();
        $gw->checkOrigin(null);
        $rej = self::rejection(static fn () => $gw->checkOrigin('https://evil.example.com'));
        $this->assertSame(403, $rej->status);
    }

    // ── Key ──────────────────────────────────────────────────────────

    public function testTheKeyIsRequired(): void
    {
        $gw = self::gateway();
        foreach ([null, '', 'pk_wrong'] as $bad) {
            $rej = self::rejection(static fn () => $gw->checkKey($bad));
            $this->assertSame(401, $rej->status);
            $this->assertSame('bad key', $rej->reason);
        }
        $gw->checkKey(self::KEY);
    }

    // ── What the browser may ask for ─────────────────────────────────

    public function testConfigUrlIsOursNotTheirs(): void
    {
        [, $params] = self::prep(self::gateway(), ['message' => 'hi', 'config_url' => 'https://evil/swml']);
        $this->assertSame(self::CONFIG_URL, $params['config_url']);
    }

    public function testTheBrowserCannotNameTheConversation(): void
    {
        $gw = self::gateway();
        [, $params, $minted] = self::prep($gw, ['message' => 'hi', 'id' => 'someone-elses-chat']);
        $this->assertNotSame('someone-elses-chat', $params['id']);
        $this->assertNotNull($minted);
        $this->assertSame($gw->readHandle($minted), $params['id']);
    }

    public function testOnlyTheFourBrowserMethodsPass(): void
    {
        $gw = self::gateway();
        foreach (['chat_log', 'summarize', 'delete', 'create_conversation', null, 5] as $method) {
            $rej = self::rejection(static fn () => self::prep($gw, ['method' => $method, 'message' => 'hi']));
            $this->assertSame(400, $rej->status);
            $this->assertSame('method not allowed', $rej->reason);
        }
    }

    public function testTheFirstChatMintsAndLaterOnesReuse(): void
    {
        $gw = self::gateway();
        [, $first, $minted] = self::prep($gw, ['message' => 'one']);
        $this->assertNotNull($minted);
        [, $second, $again] = self::prep($gw, ['message' => 'two', 'handle' => $minted]);
        $this->assertNull($again);
        $this->assertSame($first['id'], $second['id']);
    }

    public function testEndAndLogNeedAHandle(): void
    {
        $gw = self::gateway();
        foreach (['end', 'log'] as $method) {
            $rej = self::rejection(static fn () => self::prep($gw, ['method' => $method]));
            $this->assertSame(400, $rej->status);
            $this->assertSame($method . ' requires a handle', $rej->reason);
        }
    }

    public function testEndMapsToTheServiceMethod(): void
    {
        $gw = self::gateway();
        $minted = $gw->mintHandle();
        [$method, $params, $again] = self::prep($gw, ['method' => 'end', 'handle' => $minted]);
        $this->assertSame('end_conversation', $method);
        $this->assertSame(['id' => $gw->readHandle($minted)], $params);
        $this->assertNull($again);
    }

    public function testAnEmptyMessageIsRefused(): void
    {
        $gw = self::gateway();
        foreach ([null, '', '   ', 5] as $bad) {
            $rej = self::rejection(static fn () => self::prep($gw, ['message' => $bad]));
            $this->assertSame(400, $rej->status);
            $this->assertSame('message is required', $rej->reason);
        }
    }

    public function testANonStringHandleIsMalformed(): void
    {
        $rej = self::rejection(static fn () => self::prep(self::gateway(), ['message' => 'hi', 'handle' => 5]));
        $this->assertSame(400, $rej->status);
        $this->assertSame('malformed handle', $rej->reason);
    }

    // ── The caps ─────────────────────────────────────────────────────

    public function testMintingIsCapped(): void
    {
        $gw = self::makeGateway(maxNewConversations: 3);
        for ($i = 0; $i < 3; $i++) {
            $gw->prepare(['message' => 'hi'], origin: null, key: self::KEY);
        }
        $rej = self::rejection(static fn () => $gw->prepare(['message' => 'hi'], origin: null, key: self::KEY));
        $this->assertSame(429, $rej->status);
        $this->assertSame('too many new conversations', $rej->reason);
    }

    public function testTurnsAreCappedPerConversation(): void
    {
        $gw = self::makeGateway(maxTurns: 2);
        $handle = $gw->mintHandle();
        for ($i = 0; $i < 2; $i++) {
            $gw->prepare(['message' => 'hi', 'handle' => $handle], origin: null, key: self::KEY);
        }
        $rej = self::rejection(
            static fn () => $gw->prepare(['message' => 'hi', 'handle' => $handle], origin: null, key: self::KEY),
        );
        $this->assertSame(429, $rej->status);
        $this->assertSame('conversation turn limit reached', $rej->reason);
    }

    public function testOneConversationHittingItsCapDoesNotStopAnother(): void
    {
        $gw = self::makeGateway(maxTurns: 1);
        $a = $gw->mintHandle();
        $b = $gw->mintHandle();
        $gw->prepare(['message' => 'hi', 'handle' => $a], origin: null, key: self::KEY);
        $gw->prepare(['message' => 'hi', 'handle' => $b], origin: null, key: self::KEY);
        $rej = self::rejection(
            static fn () => $gw->prepare(['message' => 'again', 'handle' => $a], origin: null, key: self::KEY),
        );
        $this->assertSame(429, $rej->status);
    }

    // ── start / log ──────────────────────────────────────────────────

    public function testStartMintsAndOpensWithNoMessage(): void
    {
        $gw = self::gateway();
        [$method, $params, $minted] = self::prep($gw, ['method' => 'start']);
        $this->assertSame('create_conversation', $method);
        $this->assertNotNull($minted);
        $this->assertSame(['id' => $gw->readHandle($minted), 'config_url' => self::CONFIG_URL], $params);
    }

    public function testStartCarriesTheConfiguredTimeout(): void
    {
        $gw = self::makeGateway(conversationTimeout: 900, allowedOrigins: [self::ORIGIN]);
        [, $params] = self::prep($gw, ['method' => 'start']);
        $this->assertSame(900, $params['conversation_timeout']);
        [, $chat] = self::prep($gw, ['message' => 'hi']);
        $this->assertSame(900, $chat['conversation_timeout']);
    }

    public function testLogIsScopedToTheHandleNotTheBody(): void
    {
        $gw = self::gateway();
        $handle = $gw->mintHandle();
        [$method, $params] = self::prep($gw, ['method' => 'log', 'handle' => $handle, 'id' => 'someone-elses-chat']);
        $this->assertSame('chat_log', $method);
        $this->assertSame(['id' => $gw->readHandle($handle)], $params);
    }

    public function testTheTranscriptHidesEverythingButTheDialogue(): void
    {
        $raw = [
            ['role' => 'system', 'content' => 'You are Sigmond. Secret instructions.'],
            ['role' => 'user', 'content' => 'hi', 'timestamp' => 123],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1']]],
            ['role' => 'tool', 'content' => '{"internal": "result"}'],
            ['role' => 'assistant', 'content' => 'Hello!', 'timestamp' => 124],
            ['role' => 'assistant', 'content' => '   '],
        ];
        $out = ChatGateway::visibleMessages($raw);
        $this->assertSame([
            ['role' => 'user', 'content' => 'hi', 'timestamp' => 123 / 1_000_000],
            ['role' => 'assistant', 'content' => 'Hello!', 'timestamp' => 124 / 1_000_000],
        ], $out);
        $blob = (string) json_encode($out);
        $this->assertStringNotContainsString('Secret instructions', $blob);
        $this->assertStringNotContainsString('tool_calls', $blob);
        $this->assertStringNotContainsString('internal', $blob);
    }

    public function testTheTranscriptReportsSecondsNotMicroseconds(): void
    {
        $tsUs = 1_786_258_737_756_596;
        $out = ChatGateway::visibleMessages([['role' => 'user', 'content' => 'hi', 'timestamp' => $tsUs]]);
        $this->assertEqualsWithDelta(1_786_258_737.756596, $out[0]['timestamp'], 1e-6);
        $this->assertEqualsWithDelta(
            1_786_258_737.756596,
            ChatGateway::lastActivity([['role' => 'user', 'content' => 'hi', 'timestamp' => $tsUs]]),
            1e-6,
        );
    }

    public function testLastActivityTakesTheNewestMessageOfAnyRole(): void
    {
        $this->assertSame(5.0, ChatGateway::lastActivity([
            ['role' => 'user', 'content' => 'first', 'timestamp' => 1_000_000],
            ['role' => 'assistant', 'content' => 'second', 'timestamp' => 3_000_000],
            ['role' => 'tool', 'content' => 'internal', 'timestamp' => 5_000_000],
        ]));
    }

    public function testLastActivityIsNullWhenNothingIsDated(): void
    {
        $this->assertNull(ChatGateway::lastActivity([['role' => 'user', 'content' => 'hi']]));
        $this->assertNull(ChatGateway::lastActivity([]));
        $this->assertNull(ChatGateway::lastActivity(null));
        $this->assertNull(ChatGateway::lastActivity([['role' => 'user', 'timestamp' => 'not a number']]));
    }

    public function testEffectiveTimeoutIsAlwaysANumber(): void
    {
        $this->assertSame(3600, self::gateway()->effectiveTimeout());
    }

    public function testTheTranscriptSurvivesJunk(): void
    {
        $this->assertSame([], ChatGateway::visibleMessages([]));
        $this->assertSame([], ChatGateway::visibleMessages(null));
        $this->assertSame([], ChatGateway::visibleMessages(['not a dict', ['role' => 'user']]));
    }

    // ── Page context the browser volunteers ──────────────────────────

    public function testPageContextReachesTheCreateParams(): void
    {
        [$method, $params] = self::prep(self::gateway(), ['method' => 'start', 'user_meta_data' => self::PAGE]);
        $this->assertSame('create_conversation', $method);
        $this->assertSame(self::PAGE, $params['user_meta_data']);
    }

    public function testPageContextRidesTheChatPathToo(): void
    {
        $gw = self::gateway();
        [, $first, $handle] = self::prep($gw, ['method' => 'start', 'user_meta_data' => self::PAGE]);
        $this->assertSame(self::PAGE, $first['user_meta_data']);
        $moved = ['metadata' => ['page' => ['url' => 'https://shop.example.com/docs']]];
        [, $later] = self::prep($gw, ['message' => 'and now?', 'handle' => $handle, 'user_meta_data' => $moved]);
        $this->assertSame($moved, $later['user_meta_data']);
    }

    public function testPageContextIsOptional(): void
    {
        $gw = self::gateway();
        foreach ([['method' => 'start'], ['method' => 'start', 'user_meta_data' => null], ['method' => 'start', 'user_meta_data' => []]] as $body) {
            [, $params] = self::prep($gw, $body);
            $this->assertArrayNotHasKey('user_meta_data', $params);
        }
        $this->assertNull($gw->readUserMetadata([]));
    }

    public function testPageContextMustBeAnObject(): void
    {
        $gw = self::gateway();
        foreach (['a string', 42, ['a', 'list'], true] as $bad) {
            $rej = self::rejection(static fn () => self::prep($gw, ['method' => 'start', 'user_meta_data' => $bad]));
            $this->assertSame(400, $rej->status);
            $this->assertSame('user_meta_data must be an object', $rej->reason);
        }
    }

    public function testPageContextMustBeSerializable(): void
    {
        $rej = self::rejection(static fn () => self::gateway()->readUserMetadata(['user_meta_data' => ['x' => NAN]]));
        $this->assertSame(400, $rej->status);
        $this->assertSame('user_meta_data must be JSON-serializable', $rej->reason);
    }

    public function testPageContextIsBounded(): void
    {
        $gw = self::gateway();
        $fat = ['junk' => str_repeat('x', ChatGateway::MAX_USER_METADATA_BYTES + 1)];
        $rej = self::rejection(static fn () => self::prep($gw, ['method' => 'start', 'user_meta_data' => $fat]));
        $this->assertSame(413, $rej->status);
        $this->assertSame('user_meta_data too large', $rej->reason);
        [, $params] = self::prep($gw, ['method' => 'start', 'user_meta_data' => self::PAGE]);
        $this->assertSame(self::PAGE, $params['user_meta_data']);
    }

    public function testPageContextIsRejectedBeforeAConversationIsCharged(): void
    {
        $gw = self::makeGateway(maxNewConversations: 1);
        self::rejection(static fn () => $gw->prepare(['method' => 'start', 'user_meta_data' => 'nope'], origin: null, key: self::KEY));
        [, , $minted] = $gw->prepare(['method' => 'start'], origin: null, key: self::KEY);
        $this->assertNotNull($minted);
    }

    public function testPageContextCannotDisplaceWhatTheGatewayOwns(): void
    {
        $gw = self::gateway();
        $hostile = ['id' => 'someone-elses-chat', 'config_url' => 'https://evil/swml'];
        [, $params, $minted] = self::prep($gw, ['message' => 'hi', 'user_meta_data' => $hostile]);
        $this->assertNotNull($minted);
        $this->assertSame($gw->readHandle($minted), $params['id']);
        $this->assertSame(self::CONFIG_URL, $params['config_url']);
        $this->assertSame($hostile, $params['user_meta_data']);
    }

    // ── Size limits ──────────────────────────────────────────────────

    public function testAMessageOverTheLimitIsRefused(): void
    {
        $rej = self::rejection(
            static fn () => self::prep(self::gateway(), ['message' => str_repeat('x', ChatGateway::MAX_MESSAGE_BYTES + 1)]),
        );
        $this->assertSame(413, $rej->status);
        $this->assertSame('message too large', $rej->reason);
    }

    public function testAMessageAtTheLimitPasses(): void
    {
        $atLimit = str_repeat('x', ChatGateway::MAX_MESSAGE_BYTES);
        [, $params] = self::prep(self::gateway(), ['message' => $atLimit]);
        $this->assertSame($atLimit, $params['message']);
    }

    public function testTheMessageLimitCountsUtf8BytesNotCharacters(): void
    {
        $wide = str_repeat("\u{00e9}", intdiv(ChatGateway::MAX_MESSAGE_BYTES, 2) + 1);
        $this->assertLessThan(ChatGateway::MAX_MESSAGE_BYTES, mb_strlen($wide));
        $rej = self::rejection(static fn () => self::prep(self::gateway(), ['message' => $wide]));
        $this->assertSame(413, $rej->status);
    }

    public function testAnOversizedMessageMintsNothing(): void
    {
        $gw = self::makeGateway(maxNewConversations: 1);
        self::rejection(
            static fn () => $gw->prepare(['message' => str_repeat('x', ChatGateway::MAX_MESSAGE_BYTES + 1)], origin: null, key: self::KEY),
        );
        [, , $minted] = $gw->prepare(['message' => 'hi'], origin: null, key: self::KEY);
        $this->assertNotNull($minted);
    }

    public function testAnOversizedMessageChargesNoTurn(): void
    {
        $gw = self::makeGateway(maxTurns: 1);
        $handle = $gw->mintHandle();
        $rej = self::rejection(static fn () => $gw->prepare(
            ['message' => str_repeat('x', ChatGateway::MAX_MESSAGE_BYTES + 1), 'handle' => $handle],
            origin: null,
            key: self::KEY,
        ));
        $this->assertSame(413, $rej->status);
        [, $params] = $gw->prepare(['message' => 'hi', 'handle' => $handle], origin: null, key: self::KEY);
        $this->assertSame('hi', $params['message']);
    }

    // ── End to end through the mounted router ────────────────────────

    public function testAFullExchangeOverHttp(): void
    {
        $gw = self::gateway();
        [$status, $headers, $body] = self::post($gw, ['message' => 'hello'], self::headers());
        $this->assertSame(200, $status, $body);
        $handle = self::header($headers, 'X-Chat-Handle');
        $this->assertNotNull($handle);
        $decoded = self::json($body);
        $this->assertIsArray($decoded['result']);
        $this->assertSame('hi there', $decoded['result']['response']);

        // What actually went upstream: our config_url, our conversation id,
        // and a Basic credential the browser never saw.
        $sent = self::mock()->lastParams();
        $this->assertSame('chat', self::mock()->last()['method']);
        $this->assertSame(self::CONFIG_URL, $sent['config_url']);
        $this->assertSame($gw->readHandle($handle), $sent['id']);
        $this->assertStringNotContainsString('token', (string) json_encode($sent));

        [$status2, , $body2] = self::post($gw, ['method' => 'end', 'handle' => $handle], self::headers());
        $this->assertSame(200, $status2);
        $this->assertSame(['status' => 'ended'], self::json($body2));
        $this->assertSame('end_conversation', self::mock()->last()['method']);
    }

    public function testASecondTurnReusesTheHandle(): void
    {
        $gw = self::gateway();
        [, $headers] = self::post($gw, ['message' => 'one'], self::headers());
        $handle = self::header($headers, 'X-Chat-Handle');
        $this->assertNotNull($handle);
        [, $second] = self::post($gw, ['message' => 'two', 'handle' => $handle], self::headers());
        $this->assertNull(self::header($second, 'X-Chat-Handle'));
        $this->assertSame($gw->readHandle($handle), self::mock()->lastParams()['id']);
    }

    public function testAChatRelaysTheServiceBodyVerbatim(): void
    {
        $gw = self::gateway();
        [$status, $headers, $body] = self::post($gw, ['message' => 'hello'], self::headers());
        $this->assertSame(200, $status);
        $this->assertSame('application/json', self::header($headers, 'Content-Type'));
        $decoded = self::json($body);
        // The JSON-RPC envelope arrives untouched, id and all.
        $this->assertSame('2.0', $decoded['jsonrpc']);
        $this->assertIsString($decoded['id']);
        $this->assertIsArray($decoded['result']);
        $this->assertSame(['event_type' => 'demo', 'n' => 1], $decoded['result']['user_event'] ?? null);
    }

    public function testHttpRefusesABadKey(): void
    {
        [$status, , $body] = self::post(self::gateway(), ['message' => 'hi'], ['Authorization' => 'Bearer nope']);
        $this->assertSame(401, $status);
        $this->assertSame(['error' => 'bad key'], self::json($body));
    }

    public function testHttpRefusesAMissingBearer(): void
    {
        [$status] = self::post(self::gateway(), ['message' => 'hi'], ['Authorization' => self::KEY]);
        $this->assertSame(401, $status);
    }

    public function testHttpRefusesAnUnlistedOrigin(): void
    {
        [$status, $headers] = self::post(
            self::gateway(),
            ['message' => 'hi'],
            ['Authorization' => 'Bearer ' . self::KEY, 'Origin' => 'https://evil.test'],
        );
        $this->assertSame(403, $status);
        $this->assertNull(self::header($headers, 'Access-Control-Allow-Origin'));
    }

    public function testHeaderNamesAreCaseInsensitive(): void
    {
        [$status, $headers] = self::post(
            self::gateway(),
            ['method' => 'start'],
            ['authorization' => 'bearer ' . self::KEY, 'origin' => self::ORIGIN],
        );
        $this->assertSame(200, $status);
        $this->assertSame(self::ORIGIN, self::header($headers, 'Access-Control-Allow-Origin'));
    }

    public function testPreflightAnswersAListedOrigin(): void
    {
        [$status, $headers, $body] = (self::gateway()->router())('OPTIONS', '/', ['Origin' => self::ORIGIN], null);
        $this->assertSame(204, $status);
        $this->assertSame('', $body);
        $this->assertSame(self::ORIGIN, self::header($headers, 'Access-Control-Allow-Origin'));
        $this->assertStringContainsString('X-Chat-Handle', (string) self::header($headers, 'Access-Control-Expose-Headers'));
        $this->assertSame('POST, OPTIONS', self::header($headers, 'Access-Control-Allow-Methods'));
    }

    public function testPreflightGivesAnUnlistedOriginNothing(): void
    {
        [$status, $headers] = (self::gateway()->router())('OPTIONS', '/', ['Origin' => 'https://evil.test'], null);
        $this->assertSame(204, $status);
        $this->assertNull(self::header($headers, 'Access-Control-Allow-Origin'));
    }

    public function testOtherPathsAreNotFound(): void
    {
        [$status] = self::post(self::gateway(), ['message' => 'hi'], self::headers(), '/handoff');
        $this->assertSame(404, $status);
    }

    public function testTheRouteAnswersAtTheBarePrefixToo(): void
    {
        [$status] = self::post(self::gateway(), ['method' => 'start'], self::headers(), '');
        $this->assertSame(200, $status);
    }

    public function testStartThenReloadReplaysTheSameConversation(): void
    {
        $gw = self::gateway();
        [$status, $headers, $body] = self::post($gw, ['method' => 'start'], self::headers());
        $this->assertSame(200, $status);
        $handle = self::header($headers, 'X-Chat-Handle');
        $this->assertNotNull($handle);
        $started = self::json($body);
        $this->assertSame('hello', $started['greeting']);
        $this->assertSame('created', $started['status']);
        $this->assertSame(3600, $started['timeout']);

        [$status2, , $body2] = self::post($gw, ['method' => 'log', 'handle' => $handle], self::headers());
        $this->assertSame(200, $status2);
        $replay = self::json($body2);
        $this->assertSame([['role' => 'user', 'content' => 'm']], $replay['messages']);
        $this->assertSame(3600, $replay['timeout']);
        $this->assertArrayHasKey('last_activity', $replay);
        $this->assertNull($replay['last_activity']);
        $this->assertSame($gw->readHandle($handle), self::mock()->lastParams()['id']);
    }

    public function testStartForwardsTheConfiguredTimeoutUpstream(): void
    {
        $gw = self::makeGateway(conversationTimeout: 900, allowedOrigins: [self::ORIGIN]);
        [$status, $headers, $body] = self::post($gw, ['method' => 'start'], self::headers());
        $this->assertSame(200, $status);
        $this->assertSame(900, self::json($body)['timeout']);
        $this->assertSame('create_conversation', self::mock()->last()['method']);
        $this->assertSame(900, self::mock()->lastParams()['conversation_timeout']);

        // The chat path auto-creates too, and takes the same timeout.
        $handle = (string) self::header($headers, 'X-Chat-Handle');
        [$chatted] = self::post($gw, ['message' => 'hi', 'handle' => $handle], self::headers());
        $this->assertSame(200, $chatted);
        $this->assertSame('chat', self::mock()->last()['method']);
        $this->assertSame(900, self::mock()->lastParams()['conversation_timeout']);
    }

    public function testPageContextSurvivesTheHttpDispatch(): void
    {
        $gw = self::gateway();
        [$status, $headers] = self::post($gw, ['method' => 'start', 'user_meta_data' => self::PAGE], self::headers());
        $this->assertSame(200, $status);
        $this->assertSame('create_conversation', self::mock()->last()['method']);
        $this->assertSame(self::PAGE, self::mock()->lastParams()['user_meta_data']);

        $handle = (string) self::header($headers, 'X-Chat-Handle');
        [$chatted] = self::post($gw, ['message' => 'hi', 'handle' => $handle, 'user_meta_data' => self::PAGE], self::headers());
        $this->assertSame(200, $chatted);
        $this->assertSame('chat', self::mock()->last()['method']);
        $this->assertSame(self::PAGE, self::mock()->lastParams()['user_meta_data']);
    }

    public function testAMalformedBagIsACleanRejection(): void
    {
        [$status, , $body] = self::post(
            self::gateway(),
            ['method' => 'start', 'user_meta_data' => ['not', 'an', 'object']],
            self::headers(),
        );
        $this->assertSame(400, $status);
        $this->assertSame(['error' => 'user_meta_data must be an object'], self::json($body));
    }

    public function testABodyThatIsNotAnObjectIsRefused(): void
    {
        [$status, , $body] = self::post(self::gateway(), '["message"]', self::headers());
        $this->assertSame(400, $status);
        $this->assertSame(['error' => 'body must be an object'], self::json($body));
    }

    public function testABodyThatIsNotJsonIsABadRequest(): void
    {
        [$status, $headers, $body] = self::post(self::gateway(), '{nope', self::headers());
        $this->assertSame(400, $status);
        $this->assertSame(['error' => 'bad request'], self::json($body));
        $this->assertSame(self::ORIGIN, self::header($headers, 'Access-Control-Allow-Origin'));
    }

    public function testTheBrowserReceivesTheHandleReason(): void
    {
        $gw = self::makeGateway(handleTtl: -1);
        [$status, , $body] = self::post(
            $gw,
            ['method' => 'chat', 'message' => 'hi', 'handle' => $gw->mintHandle()],
            ['Authorization' => 'Bearer ' . self::KEY],
        );
        $this->assertSame(403, $status);
        $this->assertSame(['error' => 'expired handle'], self::json($body));
    }

    public function testAnUpstreamFailureIsA502WithCors(): void
    {
        $gw = self::gateway();
        $handle = $gw->mintHandle('__err_-32001');
        [$status, $headers, $body] = self::post($gw, ['method' => 'log', 'handle' => $handle], self::headers());
        $this->assertSame(502, $status);
        $this->assertSame(['error' => 'chat service error'], self::json($body));
        $this->assertSame(self::ORIGIN, self::header($headers, 'Access-Control-Allow-Origin'));
    }

    public function testAChatErrorRidesTheRelayedBody(): void
    {
        // A JSON-RPC error on the chat path is the service's answer, relayed
        // as-is: the browser parses the envelope, exactly as it would direct.
        $gw = self::gateway();
        $handle = $gw->mintHandle('__err_-32007');
        [$status, , $body] = self::post($gw, ['message' => 'hi', 'handle' => $handle], self::headers());
        $this->assertSame(200, $status);
        $decoded = self::json($body);
        $this->assertIsArray($decoded['error']);
        $this->assertSame(-32007, $decoded['error']['code']);
    }

    public function testHttpRefusesAnOversizedBodyBeforeParsing(): void
    {
        $before = count(self::mock()->seen());
        [$status, $headers, $body] = self::post(
            self::gateway(),
            str_repeat('{', ChatGateway::MAX_REQUEST_BODY_BYTES + 1),
            self::headers() + ['Content-Type' => 'application/json'],
        );
        $this->assertSame(413, $status);
        $this->assertSame(['error' => 'request too large'], self::json($body));
        $this->assertSame(self::ORIGIN, self::header($headers, 'Access-Control-Allow-Origin'));
        $this->assertCount($before, self::mock()->seen());
    }

    public function testHttpRefusesAnOversizedDeclaredLength(): void
    {
        [$status, , $body] = self::post(
            self::gateway(),
            ['message' => 'hi'],
            self::headers() + ['Content-Length' => (string) (ChatGateway::MAX_REQUEST_BODY_BYTES + 1)],
        );
        $this->assertSame(413, $status);
        $this->assertSame(['error' => 'request too large'], self::json($body));
    }

    public function testHttpRefusesAnOversizedMessage(): void
    {
        $before = count(self::mock()->seen());
        [$status, $headers, $body] = self::post(
            self::gateway(),
            ['message' => str_repeat('x', ChatGateway::MAX_MESSAGE_BYTES + 1)],
            self::headers(),
        );
        $this->assertSame(413, $status);
        $this->assertSame(['error' => 'message too large'], self::json($body));
        $this->assertNull(self::header($headers, 'X-Chat-Handle'));
        $this->assertCount($before, self::mock()->seen());
    }

    public function testHttpAcceptsABodyUnderTheLimit(): void
    {
        $bag = ['junk' => str_repeat('y', ChatGateway::MAX_USER_METADATA_BYTES - 20)];
        [$status] = self::post(
            self::gateway(),
            ['message' => str_repeat('x', ChatGateway::MAX_MESSAGE_BYTES), 'user_meta_data' => $bag],
            self::headers(),
        );
        $this->assertSame(200, $status);
        $this->assertSame(str_repeat('x', ChatGateway::MAX_MESSAGE_BYTES), self::mock()->lastParams()['message']);
    }

    // ── GatewayRejection ─────────────────────────────────────────────

    public function testARejectionCarriesItsStatusAndReason(): void
    {
        $rej = new GatewayRejection(429, 'too many new conversations');
        $this->assertSame(429, $rej->status);
        $this->assertSame('too many new conversations', $rej->reason);
        $this->assertSame('429: too many new conversations', $rej->getMessage());
    }
}
