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
use SignalWire\AIChat\HandoffRouter;
use SignalWire\AIChat\NonceEntry;
use SignalWire\Logging\Logger;

/**
 * HandoffRouter: moving one conversation between voice and text.
 *
 * Three properties matter more than the happy path:
 *
 * - The nonce is proof of having placed a call. It is never a call id, and an
 *   unknown nonce is answered exactly like an expired one, so the route can't
 *   be used to probe whether a given call is live.
 * - Ordering. A medium never starts until the one it replaces has finished and
 *   been recorded.
 * - Typing is repeatable but bounded: each injected message is a billable turn.
 *
 * No request here reaches the chat service, so the gateway's client points at
 * an address that is never dialled.
 */
final class HandoffRouterTest extends TestCase
{
    private const SECRET = 'ssssssssssssssssssssssssssssssss';

    /** @var list<array<int, mixed>> */
    private array $events = [];

    protected function setUp(): void
    {
        Logger::getLogger('signalwire.ai_chat.handoff')->setSuppressed(true);
    }

    protected function tearDown(): void
    {
        Logger::getLogger('signalwire.ai_chat.handoff')->setSuppressed(false);
    }

    private static function gateway(int $handleTtl = ChatGateway::DEFAULT_HANDLE_TTL): ChatGateway
    {
        return new ChatGateway(
            configUrl: 'https://agent.example.com/swml',
            key: 'pk_test',
            client: new AIChatClient(project: 'p', token: 't', url: 'https://service.example.invalid/aichat'),
            secret: self::SECRET,
            handleTtl: $handleTtl,
        );
    }

    /**
     * An empty nonce table.
     *
     * @return \ArrayObject<string, NonceEntry>
     */
    private static function table(): \ArrayObject
    {
        return new \ArrayObject();
    }

    /**
     * The router most tests use: every callback records into $this->events.
     *
     * @param \ArrayObject<string, NonceEntry>|null $registry
     */
    private function handoff(?ChatGateway $gateway = null, ?\ArrayObject $registry = null): HandoffRouter
    {
        return new HandoffRouter(
            gateway: $gateway ?? self::gateway(),
            captureLeg: function (string $conversationId, string $medium): bool {
                $this->events[] = ['capture', $conversationId, $medium];
                return true;
            },
            endCall: function (string $callId): void {
                $this->events[] = ['end_call', $callId];
            },
            sendMessage: function (string $callId, string $text): bool {
                $this->events[] = ['say', $callId, $text];
                return true;
            },
            registry: $registry,
        );
    }

    /**
     * A sender that records just the text.
     *
     * @param list<string> $sent
     */
    private static function recordingSender(array &$sent): \Closure
    {
        return static function (string $callId, string $text) use (&$sent): bool {
            $sent[] = $text;
            return true;
        };
    }

    /**
     * POST to the mounted router.
     *
     * @param array<string, mixed>|string $body
     * @param array<string, string>        $headers
     * @return array{int, array<string, string>, string}
     */
    private static function post(HandoffRouter $router, string $path, array|string $body, array $headers = []): array
    {
        $raw = is_string($body) ? $body : (string) json_encode((object) $body);
        return ($router->router())('POST', $path, $headers + ['Content-Type' => 'application/json'], $raw);
    }

    /** @return array<array-key, mixed> */
    private static function json(string $body): array
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, 'body is not a JSON object: ' . $body);
        return $decoded;
    }

    // ── Construction ─────────────────────────────────────────────────

    public function testConfigurationIsReadable(): void
    {
        $gateway = self::gateway();
        $router = new HandoffRouter(gateway: $gateway);
        $this->assertSame($gateway, $router->gateway);
        $this->assertNull($router->captureLeg);
        $this->assertNull($router->endCall);
        $this->assertNull($router->sendMessage);
        $this->assertSame(HandoffRouter::DEFAULT_NONCE_TTL, $router->nonceTtl);
        $this->assertSame(HandoffRouter::DEFAULT_MAX_MESSAGES_PER_CALL, $router->maxMessagesPerCall);
        $this->assertSame(HandoffRouter::DEFAULT_CAPTURE_TIMEOUT, $router->captureTimeout);
    }

    public function testANonceEntryDefaultsToNowAndUnused(): void
    {
        $entry = new NonceEntry('conv-1');
        $this->assertSame('conv-1', $entry->conversationId);
        $this->assertNull($entry->callId);
        $this->assertSame(0, $entry->messages);
        $this->assertFalse($entry->redeemed);
        $this->assertEqualsWithDelta(hrtime(true) / 1e9, $entry->issuedAt, 5.0);

        $explicit = new NonceEntry('c', callId: 'call-1', issuedAt: 12.5, messages: 3, redeemed: true);
        $this->assertSame('call-1', $explicit->callId);
        $this->assertSame(12.5, $explicit->issuedAt);
        $this->assertSame(3, $explicit->messages);
        $this->assertTrue($explicit->redeemed);
    }

    // ── Redemption ───────────────────────────────────────────────────

    public function testReturnsAHandleTheGatewayCanRead(): void
    {
        $gateway = self::gateway();
        $router = $this->handoff($gateway);
        $router->register('n1', conversationId: 'conv-root', callId: 'call-9');
        [$status, , $body] = self::post($router, '/handoff', ['nonce' => 'n1']);
        $this->assertSame(200, $status);
        $handle = self::json($body)['handle'];
        $this->assertIsString($handle);
        $this->assertNotSame('', $gateway->readHandle($handle));
    }

    public function testCallEndsBeforeTheLegIsCaptured(): void
    {
        $router = $this->handoff();
        $router->register('n1', conversationId: 'conv-root', callId: 'call-9');
        self::post($router, '/handoff', ['nonce' => 'n1']);
        $this->assertSame([['end_call', 'call-9'], ['capture', 'conv-root', 'voice']], $this->events);
    }

    public function testNewLegGetsAFreshDottedId(): void
    {
        $gateway = self::gateway();
        $router = $this->handoff($gateway);
        $router->register('n1', conversationId: 'conv-root', callId: 'call-9');
        [, , $body] = self::post($router, '/handoff', ['nonce' => 'n1']);
        $handle = self::json($body)['handle'];
        $this->assertIsString($handle);
        $this->assertSame('conv-root.1', $gateway->readHandle($handle));
    }

    public function testLegIdsIncrement(): void
    {
        $router = $this->handoff();
        $this->assertSame('root.3', ($router->nextConversationId)('root.2'));
        $this->assertSame('root.1', ($router->nextConversationId)('root'));
        $this->assertSame('root.x.1', ($router->nextConversationId)('root.x'));
    }

    public function testACustomNextIdIsUsed(): void
    {
        $gateway = self::gateway();
        $router = new HandoffRouter(gateway: $gateway, nextConversationId: static fn (string $id): string => $id . '.voice2text');
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $handle = $router->redeem('n');
        $this->assertNotNull($handle);
        $this->assertSame('c.voice2text', $gateway->readHandle($handle));
    }

    public function testANonceIsSingleUse(): void
    {
        $router = $this->handoff();
        $router->register('n1', conversationId: 'conv-root', callId: 'call-9');
        $this->assertSame(200, self::post($router, '/handoff', ['nonce' => 'n1'])[0]);
        $this->assertSame(404, self::post($router, '/handoff', ['nonce' => 'n1'])[0]);
    }

    public function testUnknownAndSpentNoncesAreIndistinguishable(): void
    {
        $router = $this->handoff();
        $router->register('n1', conversationId: 'conv-root', callId: 'call-9');
        self::post($router, '/handoff', ['nonce' => 'n1']);
        $spent = self::post($router, '/handoff', ['nonce' => 'n1']);
        $unknown = self::post($router, '/handoff', ['nonce' => 'never-existed']);
        $this->assertSame(404, $spent[0]);
        $this->assertSame($spent[0], $unknown[0]);
        $this->assertSame($spent[2], $unknown[2]);
    }

    public function testExpiredNoncesAreNotRedeemable(): void
    {
        $router = new HandoffRouter(gateway: self::gateway(), nonceTtl: -1);
        $router->register('n1', conversationId: 'conv-root', callId: 'call-9');
        $this->assertNull($router->redeem('n1'));
    }

    public function testMissingNonceIsRejected(): void
    {
        $this->assertSame(404, self::post($this->handoff(), '/handoff', [])[0]);
        $this->assertSame(404, self::post($this->handoff(), '/handoff', ['nonce' => 5])[0]);
    }

    public function testAMintFailureIsAnUnknownNonce(): void
    {
        $router = new HandoffRouter(
            gateway: self::gateway(),
            nextConversationId: static fn (string $id): string => throw new \RuntimeException('no id'),
        );
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $this->assertNull($router->redeem('n'));
    }

    public function testAFailingEndCallDoesNotBlockTheSwitch(): void
    {
        $router = new HandoffRouter(
            gateway: self::gateway(),
            endCall: static function (string $callId): void {
                throw new \RuntimeException('platform down');
            },
        );
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $this->assertNotNull($router->redeem('n'));
    }

    // ── Registration ─────────────────────────────────────────────────

    public function testARepeatRegistrationKeepsTheTypingCount(): void
    {
        $sent = [];
        $router = new HandoffRouter(gateway: self::gateway(), sendMessage: self::recordingSender($sent), maxMessagesPerCall: 1);
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $this->assertTrue($router->say('n', 'one'));
        $this->assertFalse($router->say('n', 'two'));
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $this->assertFalse($router->say('n', 'three'));
        $this->assertSame(['one'], $sent);
    }

    public function testARepeatRegistrationKeepsTheRegistrationTime(): void
    {
        $registry = self::table();
        $router = $this->handoff(registry: $registry);
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $entry = $registry['n'];
        $this->assertInstanceOf(NonceEntry::class, $entry);
        $first = $entry->issuedAt;
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $again = $registry['n'];
        $this->assertInstanceOf(NonceEntry::class, $again);
        $this->assertSame($first, $again->issuedAt);
    }

    public function testALiveNonceCannotBeMovedToAnotherCall(): void
    {
        $router = $this->handoff();
        $router->register('n', conversationId: 'conv-a', callId: 'call-a');
        $router->register('n', conversationId: 'conv-b', callId: 'call-b');
        $this->assertSame(200, self::post($router, '/say', ['nonce' => 'n', 'text' => 'hi'])[0]);
        $this->assertSame([['say', 'call-a', 'hi']], $this->events);
    }

    public function testARedeemedNonceCannotBeRegisteredAndRedeemedAgain(): void
    {
        $router = $this->handoff();
        $router->register('n', conversationId: 'conv-root', callId: 'call-9');
        $this->assertSame(200, self::post($router, '/handoff', ['nonce' => 'n'])[0]);
        $router->register('n', conversationId: 'conv-root', callId: 'call-10');
        [$status, , $body] = self::post($router, '/handoff', ['nonce' => 'n']);
        $this->assertSame(404, $status);
        $this->assertSame(['error' => 'not found'], self::json($body));
    }

    public function testARedeemedNonceCannotType(): void
    {
        $router = $this->handoff();
        $router->register('n', conversationId: 'conv-root', callId: 'call-9');
        self::post($router, '/handoff', ['nonce' => 'n']);
        $this->events = [];
        $this->assertSame(404, self::post($router, '/say', ['nonce' => 'n', 'text' => 'late'])[0]);
        $this->assertSame([], $this->events);
    }

    public function testRedemptionIsKeptUntilTheTtlPasses(): void
    {
        $registry = self::table();
        $router = $this->handoff(registry: $registry);
        $router->register('n', conversationId: 'conv-root', callId: 'call-9');
        $this->assertNotNull($router->redeem('n'));
        $entry = $registry['n'];
        $this->assertInstanceOf(NonceEntry::class, $entry);
        $this->assertTrue($entry->redeemed);
        // Once the entry would have expired it is pruned, and the nonce can be
        // registered afresh.
        $entry->issuedAt -= $router->nonceTtl + 1;
        $router->register('n', conversationId: 'conv-new', callId: 'call-11');
        $fresh = $registry['n'];
        $this->assertInstanceOf(NonceEntry::class, $fresh);
        $this->assertFalse($fresh->redeemed);
        $this->assertSame('conv-new', $fresh->conversationId);
    }

    public function testASharedRegistryStoresTheRedemption(): void
    {
        $registry = new RecordingRegistry();
        $router = new HandoffRouter(gateway: self::gateway(), registry: $registry);
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $this->assertNotNull($router->redeem('n'));
        $this->assertSame([['n', false], ['n', true]], $registry->assigned);
    }

    public function testEmptyNoncesAreNotRegistered(): void
    {
        $registry = self::table();
        $router = $this->handoff(registry: $registry);
        $router->register('', conversationId: 'c', callId: 'call-1');
        $this->assertCount(0, $registry);
    }

    // ── Reentrancy (PHP's analogue of the overlapping-request tests) ──

    public function testARegistrationInterleavedWithARedemptionCantReviveTheNonce(): void
    {
        $handles = [];
        $registry = new InterleavingRegistry();
        $router = new HandoffRouter(gateway: self::gateway(), registry: $registry);
        $registry->interleave = static function () use ($router, &$handles): void {
            $router->register('n', conversationId: 'late', callId: 'call-2');
            $handles[] = $router->redeem('n');
        };
        $router->register('n', conversationId: 'first', callId: 'call-1');
        $handles[] = $router->redeem('n');
        $this->assertSame(1, count(array_filter($handles, static fn (?string $h): bool => $h !== null)));
    }

    public function testASayDeliveredWhileAnotherIsInFlightCantPassTheCap(): void
    {
        $send = new class () {
            public ?HandoffRouter $router = null;

            /** @var list<string> */
            public array $delivered = [];

            /** @var list<bool> */
            public array $results = [];

            public function __invoke(string $callId, string $text): bool
            {
                if ($text === 'm0' && $this->router !== null) {
                    // Two more arrive while the first is still being delivered.
                    $this->results[] = $this->router->say('n', 'm1');
                    $this->results[] = $this->router->say('n', 'm2');
                }
                $this->delivered[] = $text;
                return true;
            }
        };
        $router = new HandoffRouter(gateway: self::gateway(), sendMessage: $send, maxMessagesPerCall: 1);
        $send->router = $router;
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $first = $router->say('n', 'm0');
        $results = [$first, ...$send->results];
        $this->assertSame(1, count(array_filter($results)));
        $this->assertSame(['m0'], $send->delivered);
    }

    public function testAFailedDeliveryGivesItsSlotBack(): void
    {
        $this->assertFailedDeliveryGivesItsSlotBack(null);
    }

    public function testAFailedDeliveryGivesItsSlotBackWithACopyingRegistry(): void
    {
        $this->assertFailedDeliveryGivesItsSlotBack(new CopyingRegistry());
    }

    /**
     * @param \ArrayObject<string, NonceEntry>|null $registry
     */
    private function assertFailedDeliveryGivesItsSlotBack(?\ArrayObject $registry): void
    {
        $attempts = [];
        $send = static function (string $callId, string $text) use (&$attempts): bool {
            $attempts[] = $text;
            if (count($attempts) === 1) {
                throw new \RuntimeException('platform unavailable');
            }
            return true;
        };
        $router = new HandoffRouter(gateway: self::gateway(), sendMessage: $send, maxMessagesPerCall: 1, registry: $registry);
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $this->assertFalse($router->say('n', 'first'));
        $this->assertTrue($router->say('n', 'again'));
        $this->assertFalse($router->say('n', 'over the cap'));
        $this->assertSame(['first', 'again'], $attempts);
    }

    public function testARedeemedNonceStaysRedeemedWithACopyingRegistry(): void
    {
        $router = new HandoffRouter(gateway: self::gateway(), registry: new CopyingRegistry());
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $this->assertNotNull($router->redeem('n'));
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $this->assertNull($router->redeem('n'));
    }

    // ── Escalate ─────────────────────────────────────────────────────

    public function testEscalateCapturesTheChatLegBeforeReturning(): void
    {
        $gateway = self::gateway();
        $router = $this->handoff($gateway);
        $handle = $gateway->mintHandle('conv-root.5');
        [$status, , $body] = self::post($router, '/escalate', ['handle' => $handle]);
        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true], self::json($body));
        $this->assertSame([['capture', 'conv-root.5', 'chat']], $this->events);
    }

    public function testEscalateRefusesAForgedHandle(): void
    {
        $this->assertSame(404, self::post($this->handoff(), '/escalate', ['handle' => 'forged'])[0]);
        $this->assertFalse($this->handoff()->escalate('forged'));
    }

    public function testEscalateWithAMissingHandleIsABadRequest(): void
    {
        [$status, , $body] = self::post($this->handoff(), '/escalate', []);
        $this->assertSame(400, $status);
        $this->assertSame(['error' => 'bad request'], self::json($body));
    }

    // ── Say ──────────────────────────────────────────────────────────

    public function testSayDeliversTrimmedTextToTheCallTheNonceNames(): void
    {
        $router = $this->handoff();
        $router->register('n2', conversationId: 'conv-root', callId: 'call-9');
        $this->assertSame(200, self::post($router, '/say', ['nonce' => 'n2', 'text' => '  hello  '])[0]);
        $this->assertSame([['say', 'call-9', 'hello']], $this->events);
    }

    public function testSayIsRepeatable(): void
    {
        $router = $this->handoff();
        $router->register('n2', conversationId: 'conv-root', callId: 'call-9');
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, self::post($router, '/say', ['nonce' => 'n2', 'text' => 'x'])[0]);
        }
    }

    public function testSayIsCappedPerCall(): void
    {
        $sent = [];
        $router = new HandoffRouter(gateway: self::gateway(), sendMessage: self::recordingSender($sent), maxMessagesPerCall: 2);
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $this->assertTrue($router->say('n', 'one'));
        $this->assertTrue($router->say('n', 'two'));
        $this->assertFalse($router->say('n', 'three'));
    }

    public function testEmptyTextIsRefused(): void
    {
        $router = $this->handoff();
        $router->register('n2', conversationId: 'conv-root', callId: 'call-9');
        $this->assertSame(404, self::post($router, '/say', ['nonce' => 'n2', 'text' => '   '])[0]);
    }

    public function testAnUnknownNonceCannotInject(): void
    {
        $this->assertSame(404, self::post($this->handoff(), '/say', ['nonce' => 'guessed', 'text' => 'hello'])[0]);
    }

    public function testANonceWithoutACallCannotType(): void
    {
        $router = $this->handoff();
        $router->register('n', conversationId: 'c');
        $this->assertFalse($router->say('n', 'hello'));
    }

    public function testSayIsDisabledWhenNoSenderIsConfigured(): void
    {
        $router = new HandoffRouter(gateway: self::gateway());
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $this->assertFalse($router->say('n', 'hello'));
    }

    // ── Typing lifetime ──────────────────────────────────────────────

    public function testTypingStopsWhenTheNonceExpires(): void
    {
        $sent = [];
        $registry = self::table();
        $router = new HandoffRouter(gateway: self::gateway(), sendMessage: self::recordingSender($sent), nonceTtl: 600, registry: $registry);
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $this->assertTrue($router->say('n', 'before'));
        $entry = $registry['n'];
        $this->assertInstanceOf(NonceEntry::class, $entry);
        $entry->issuedAt -= 601;
        $this->assertFalse($router->say('n', 'after'));
        $this->assertSame(['before'], $sent);
    }

    public function testTypingStopsOnceTheNonceIsRedeemed(): void
    {
        $sent = [];
        $router = new HandoffRouter(gateway: self::gateway(), sendMessage: self::recordingSender($sent));
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $this->assertTrue($router->say('n', 'before'));
        $this->assertNotNull($router->redeem('n'));
        $this->assertFalse($router->say('n', 'after'));
        $this->assertSame(['before'], $sent);
    }

    // ── Size limits ──────────────────────────────────────────────────

    public function testSayRefusesTextOverTheMessageLimit(): void
    {
        $router = $this->handoff();
        $router->register('n', conversationId: 'conv-root', callId: 'call-9');
        [$status, , $body] = self::post($router, '/say', ['nonce' => 'n', 'text' => str_repeat('x', ChatGateway::MAX_MESSAGE_BYTES + 1)]);
        $this->assertSame(413, $status);
        $this->assertSame(['error' => 'message too large'], self::json($body));
        $this->assertSame([], $this->events);
    }

    public function testTheSizeAnswerDoesNotDependOnTheNonce(): void
    {
        [$status, , $body] = self::post(
            $this->handoff(),
            '/say',
            ['nonce' => 'never-existed', 'text' => str_repeat('x', ChatGateway::MAX_MESSAGE_BYTES + 1)],
        );
        $this->assertSame(413, $status);
        $this->assertSame(['error' => 'message too large'], self::json($body));
    }

    public function testSayAcceptsTextAtTheLimit(): void
    {
        $router = $this->handoff();
        $router->register('n', conversationId: 'conv-root', callId: 'call-9');
        $text = str_repeat('x', ChatGateway::MAX_MESSAGE_BYTES);
        $this->assertSame(200, self::post($router, '/say', ['nonce' => 'n', 'text' => $text])[0]);
        $this->assertSame([['say', 'call-9', $text]], $this->events);
    }

    public function testSayCalledDirectlyRefusesOversizedText(): void
    {
        $sent = [];
        $router = new HandoffRouter(gateway: self::gateway(), sendMessage: self::recordingSender($sent));
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $this->assertFalse($router->say('n', str_repeat('x', ChatGateway::MAX_MESSAGE_BYTES + 1)));
        $this->assertSame([], $sent);
    }

    /** @return array<string, array{string}> */
    public static function routes(): array
    {
        return ['handoff' => ['/handoff'], 'escalate' => ['/escalate'], 'say' => ['/say']];
    }

    #[DataProvider('routes')]
    public function testAnOversizedBodyIsRefused(string $path): void
    {
        [$status, , $body] = self::post($this->handoff(), $path, str_repeat(' ', ChatGateway::MAX_REQUEST_BODY_BYTES + 1));
        $this->assertSame(413, $status);
        $this->assertSame(['error' => 'request too large'], self::json($body));
    }

    public function testAnOversizedHandoffLeavesTheNonceRedeemable(): void
    {
        $router = $this->handoff();
        $router->register('n', conversationId: 'conv-root', callId: 'call-9');
        $padded = '{"nonce": "n", "pad": "' . str_repeat('x', ChatGateway::MAX_REQUEST_BODY_BYTES) . '"}';
        $this->assertSame(413, self::post($router, '/handoff', $padded)[0]);
        $this->assertSame(200, self::post($router, '/handoff', ['nonce' => 'n'])[0]);
    }

    // ── Origin and routing ───────────────────────────────────────────

    #[DataProvider('routes')]
    public function testADisallowedOriginIsRefused(string $path): void
    {
        [$status, , $body] = self::post($this->handoff(), $path, ['nonce' => 'n'], ['Origin' => 'https://evil.test']);
        $this->assertSame(403, $status);
        $this->assertSame(['error' => 'origin not allowed'], self::json($body));
    }

    public function testUnknownPathsAreNotFound(): void
    {
        $this->assertSame(404, self::post($this->handoff(), '/', ['nonce' => 'n'])[0]);
    }

    // ── Capture failures ─────────────────────────────────────────────

    public function testACaptureOverrunDoesNotBlockTheSwitch(): void
    {
        $gateway = self::gateway();
        $router = new HandoffRouter(
            gateway: $gateway,
            captureLeg: static function (string $conversationId, string $medium): bool {
                usleep(100_000);
                return true;
            },
            captureTimeout: 0.05,
        );
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $handle = $router->redeem('n');
        $this->assertNotNull($handle);
        $this->assertSame('c.1', $gateway->readHandle($handle));
    }

    public function testARaisingCaptureDoesNotBlockTheSwitch(): void
    {
        $gateway = self::gateway();
        $router = new HandoffRouter(
            gateway: $gateway,
            captureLeg: static function (string $conversationId, string $medium): bool {
                throw new \RuntimeException('storage down');
            },
        );
        $router->register('n', conversationId: 'c', callId: 'call-1');
        $handle = $router->redeem('n');
        $this->assertNotNull($handle);
        $this->assertSame('c.1', $gateway->readHandle($handle));
    }
}
