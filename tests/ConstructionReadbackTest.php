<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\AIChat\AIChatClient;
use SignalWire\AIChat\AIChatError;
use SignalWire\Contexts\GatherQuestion;
use SignalWire\Core\ConfigLoader;
use SignalWire\Core\SecurityConfig;
use SignalWire\DataMap\DataMap;
use SignalWire\Logging\Logger;
use SignalWire\POM\Section;
use SignalWire\Relay\Message;
use SignalWire\Relay\RelayError;
use SignalWire\Security\AuthHandler;
use SignalWire\Security\SessionManager;
use SignalWire\Server\AgentServer;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\SWAIG\SwaigFunction;
use SignalWire\SWML\Schema;
use SignalWire\SWML\Service;
use SignalWire\SWML\SWMLBuilder;
use SignalWire\Utils\SchemaUtils;

/**
 * CONSTRUCTION-READBACK coverage: a value the caller supplies at construction
 * must be readable back at PUBLIC visibility.
 *
 * The failure this guards is not a missing feature — it is a value the port
 * ACCEPTS and then hides, so the gate goes green while the capability is gone.
 * ``AgentBase::getAgentId()`` was ``protected`` here (cpp made the same two
 * members protected purely to pass SURFACE-DIFF, and its callers then could not
 * read what Python callers can); ``SkillBase::$agent``/``$params``,
 * ``AgentBase::$nativeFunctions`` and ``AIChatClient``'s ``url`` were the same
 * shape. Each assertion below reads back a value the constructor was given.
 */
class ConstructionReadbackTest extends TestCase
{
    protected function setUp(): void
    {
        Logger::reset();
        Schema::reset();
    }

    public function testAgentBaseAgentIdIsPubliclyReadable(): void
    {
        $agent = new AgentBase(
            name: 'readback',
            agentId: 'agent-abc-123',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
        );

        // PUBLIC — no subclass probe, no reflection. That is the whole point.
        $this->assertSame('agent-abc-123', $agent->getAgentId());
    }

    public function testAgentBaseNativeFunctionsReadBack(): void
    {
        $agent = new AgentBase(
            name: 'readback',
            nativeFunctions: ['check_time', 'wait_for_user'],
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
        );

        $this->assertSame(['check_time', 'wait_for_user'], $agent->getNativeFunctions());
    }

    public function testAgentServerLogLevelIsAppliedAndReadable(): void
    {
        // The reference lower-cases the level (agent_server.py:63) and hands it to
        // the serving runtime; php stored the raw string and applied it nowhere.
        $server = new AgentServer(host: '127.0.0.1', port: 8099, logLevel: 'DEBUG');

        $this->assertSame('debug', $server->getLogLevel());
        $this->assertSame('127.0.0.1', $server->getHost());
        $this->assertSame(8099, $server->getPort());
    }

    public function testSessionManagerSecretKeyReadsBackAndKeysTheHmac(): void
    {
        $explicit = new SessionManager(tokenExpirySecs: 120, secretKey: 'my-shared-key');
        $this->assertSame('my-shared-key', $explicit->getSecretKey());
        $this->assertSame(120, $explicit->getTokenExpirySecs());

        // Reading the key back is what makes cross-instance interop possible: a
        // second manager given the SAME key must validate the first's token.
        $token = $explicit->createToolToken('do_thing', 'call-1');
        $peer = new SessionManager(tokenExpirySecs: 120, secretKey: $explicit->getSecretKey());
        $this->assertTrue($peer->validateToolToken('do_thing', $token, 'call-1'));
    }

    public function testSessionManagerDefaultSecretIsA64CharHexString(): void
    {
        // Reference default is secrets.token_hex(32) — a 64-CHAR HEX STRING keyed
        // as its own bytes (session_manager.py:40, 79). A port storing 32 RAW
        // bytes mints tokens no other implementation can verify; that defect was
        // found in cpp, java, dotnet and go.
        $sm = new SessionManager();
        $secret = $sm->getSecretKey();

        $this->assertSame(64, strlen($secret));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $secret);
    }

    public function testSessionManagerDefaultExpiryMatchesTheReference(): void
    {
        // 900, not 3600 (session_manager.py:30). php defaulted to 3600, so a bare
        // construction minted tokens with four times the reference's lifetime.
        $this->assertSame(900, (new SessionManager())->getTokenExpirySecs());
    }

    public function testAgentBaseStillForwardsItsOwn3600Default(): void
    {
        // The two defaults are DIFFERENT in the reference and both are contract:
        // AgentBase defaults to 3600 and FORWARDS it (agent_base.py:130, 247),
        // SessionManager's own bare default is 900. Lowering SessionManager's must
        // not change what AgentBase forwards.
        // Asserted on the LIFETIME BAKED INTO A REAL TOKEN, not a getter: the
        // token payload carries its own absolute expiry, so this measures what the
        // agent's SessionManager was actually constructed with.
        $agent = new AgentBase(name: 'expiry', basicAuthUser: 'u', basicAuthPassword: 'p');
        $agent->defineTool(
            name: 'fn',
            description: 'd',
            parameters: [],
            handler: static fn (array $a, array $r): FunctionResult => new FunctionResult('ok'),
            secure: true,
        );
        $before = time();
        $token = $agent->createToolToken('fn', 'call-expiry');
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        $this->assertIsString($decoded);
        $parts = explode('.', $decoded);
        $this->assertGreaterThanOrEqual(5, count($parts));
        $expiry = (int) $parts[2];

        // 3600 forwarded, not SessionManager's own bare 900.
        $this->assertGreaterThanOrEqual($before + 3600, $expiry);
        $this->assertLessThanOrEqual(time() + 3600, $expiry);
    }

    public function testGatherQuestionReadsBackEveryConstructionValue(): void
    {
        $q = new GatherQuestion(
            key: 'colour',
            question: 'Favourite colour?',
            type: 'string',
            confirm: true,
            prompt: 'Be brief.',
            functions: ['lookup_colour'],
            isolated: false,
        );

        $this->assertSame('colour', $q->getKey());
        $this->assertSame('Favourite colour?', $q->getQuestion());
        $this->assertSame('string', $q->getType());
        $this->assertTrue($q->getConfirm());
        $this->assertSame('Be brief.', $q->getPrompt());
        $this->assertSame(['lookup_colour'], $q->getFunctions());
        $this->assertFalse($q->getIsolated());
    }

    public function testGatherQuestionIsolatedTriStateDistinguishesNullFromFalse(): void
    {
        // null INHERITS the gather default; false OVERRIDES it. Collapsing the two
        // would silently drop a caller's explicit override.
        $inherit = new GatherQuestion(key: 'a', question: 'A?');
        $override = new GatherQuestion(key: 'b', question: 'B?', isolated: false);

        $this->assertNull($inherit->getIsolated());
        $this->assertFalse($override->getIsolated());
        $this->assertArrayNotHasKey('isolated', $inherit->toArray());
        $this->assertFalse($override->toArray()['isolated']);
    }

    public function testFunctionResultResponseAndPostProcessReadBack(): void
    {
        $r = new FunctionResult('all done', true);

        $this->assertSame('all done', $r->getResponse());
        $this->assertTrue($r->getPostProcess());
    }

    public function testSwaigFunctionHandlerReadsBackAndIsTheSameCallable(): void
    {
        $handler = static fn (array $args, ?array $raw = null): string => 'ok';
        $fn = new SwaigFunction(
            name: 'do_thing',
            handler: $handler,
            description: 'Does a thing',
        );

        $readBack = $fn->getHandler();
        $this->assertSame('ok', $readBack([], null));
    }

    public function testDataMapFunctionNameReadsBack(): void
    {
        $this->assertSame('lookup', (new DataMap('lookup'))->getFunctionName());
    }

    public function testSchemaUtilsSchemaPathReadsBack(): void
    {
        $path = __DIR__ . '/../src/SignalWire/SWML/schema.json';
        $this->assertSame($path, (new SchemaUtils($path))->getSchemaPath());
        // Null when the caller relied on the bundled default.
        $this->assertNull((new SchemaUtils())->getSchemaPath());
    }

    public function testConfigLoaderConfigPathsReadBack(): void
    {
        $paths = ['/nonexistent/a.json', '/nonexistent/b.json'];
        $this->assertSame($paths, (new ConfigLoader($paths))->getConfigPaths());
        // Defaults are still readable when the caller passed none.
        $this->assertNotEmpty((new ConfigLoader())->getConfigPaths());
    }

    public function testAuthHandlerSecurityConfigReadsBackAndSeedsBasicAuth(): void
    {
        $cfg = new SecurityConfig();
        $handler = new AuthHandler(securityConfig: $cfg);

        $this->assertSame($cfg, $handler->getSecurityConfig());

        // The reference derives basic-auth FROM the config (auth_handler.py:77),
        // so the credentials the config yields must actually authenticate.
        [$user, $pass] = $cfg->getBasicAuth();
        $this->assertTrue($handler->verifyBasicAuth($user, $pass));
        $this->assertFalse($handler->verifyBasicAuth($user, $pass . 'x'));

        // Null when the caller supplied credentials directly instead.
        $this->assertNull((new AuthHandler(apiKey: 'k'))->getSecurityConfig());
    }

    public function testSwmlBuilderServiceReadsBack(): void
    {
        $svc = new Service(name: 'svc', basicAuthUser: 'u', basicAuthPassword: 'p');
        $this->assertSame($svc, (new SWMLBuilder($svc))->getService());
    }

    public function testSectionNumberedBulletsReadsBackUnderTheWireSpelling(): void
    {
        // ``numberedBullets`` is a WIRE KEY — it round-trips through the POM dict
        // verbatim (pom.py:345, 361, 371), so the camelCase spelling IS the name.
        $s = new Section('T', ['bullets' => ['a'], 'numberedBullets' => true]);

        $this->assertTrue($s->numberedBullets);
        $this->assertTrue($s->toArray()['numberedBullets'] ?? null);
    }

    public function testRelayErrorCodeAndMessageReadBackUndecorated(): void
    {
        $e = new RelayError(-32601, 'method not found');

        // The RAW server message, without the "RELAY error <code>: " decoration —
        // java discarded it, which loses the server's own wording.
        $this->assertSame(-32601, $e->relayCode);
        $this->assertSame('method not found', $e->relayMessage);
        $this->assertStringContainsString('method not found', $e->getMessage());
    }

    public function testAiChatErrorCodeAndMessageReadBack(): void
    {
        $e = new AIChatError(-32000, 'conversation is busy');

        // php cannot name these getCode()/getMessage(): \Throwable already
        // declares getCode(): int (unwidenable to the reference's int|None) and
        // getMessage() carries the "[code] " prefix. Different spelling, same
        // capability — a rename, not an omission.
        $this->assertSame(-32000, $e->getErrorCode());
        $this->assertSame('conversation is busy', $e->getServerMessage());
        $this->assertSame(0, (new AIChatError(null, 'no code'))->getCode());
        $this->assertNull((new AIChatError(null, 'no code'))->getErrorCode());
    }

    public function testAiChatClientUrlReadsBack(): void
    {
        $client = new AIChatClient(
            project: 'proj',
            token: 'tok',
            url: 'https://example.test/api/ai/chat',
        );

        $this->assertSame('https://example.test/api/ai/chat', $client->url);
    }

    public function testMessageSegmentsReadsBackFromTheWirePayload(): void
    {
        // A multi-segment SMS: the reference reads ``segments`` off the params
        // (relay/message.py:53, 65); php dropped it, so a caller could not tell a
        // multi-segment message from a single one.
        $m = new Message(['message_id' => 'm1', 'body' => 'x', 'segments' => 3]);
        $this->assertSame(3, $m->getSegments());

        // Absent on the wire -> 0, matching the reference default.
        $this->assertSame(0, (new Message(['message_id' => 'm2']))->getSegments());
    }
}
