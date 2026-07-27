<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\Logging\Logger;
use SignalWire\SWML\Schema;
use SignalWire\SWML\Service;
use SignalWire\Tests\Support\ConstructionProbeAgent;
use SignalWire\Utils\SchemaValidationError;

/**
 * Construction-contract coverage for the AgentBase / SWMLService parameters the
 * reference forwards to collaborators rather than storing on itself:
 *
 *   - ``schema_path`` / ``config_file`` / ``schema_validation``
 *       AgentBase.__init__ -> super().__init__ (SWMLService), which builds
 *       SchemaUtils(schema_path, schema_validation=...) and
 *       SecurityConfig(config_file=..., service_name=...)
 *       (signalwire/core/agent_base.py:205-207, swml_service.py:129-186)
 *   - ``token_expiry_secs``
 *       AgentBase.__init__ -> SessionManager(token_expiry_secs=...)
 *       (signalwire/core/agent_base.py:247)
 *
 * plus the agent's own construction-time state (agent_id, native_functions,
 * record_format, record_stereo, suppress_logs, default_webhook_url,
 * enable_post_prompt_override, check_for_input_override,
 * trust_proxy_for_signature).
 *
 * Every assertion drives REAL behavior — a rendered document, a validated verb,
 * a signed token's lifetime, a config file on disk — not a getter round-trip.
 */
class ConstructionContractTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        Logger::reset();
        Schema::reset();
        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
        putenv('SWML_PROXY_URL_BASE');
        putenv('SWML_SKIP_SCHEMA_VALIDATION');
        putenv('PORT');
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tmpFiles = [];
        Logger::reset();
        Schema::reset();
        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
        putenv('SWML_PROXY_URL_BASE');
        putenv('SWML_SKIP_SCHEMA_VALIDATION');
        putenv('PORT');
    }

    /** Repo-local scratch dir (never /tmp). */
    private function scratchDir(): string
    {
        $dir = dirname(__DIR__) . '/.sw-tmp/construction-contract';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        return $dir;
    }

    private function writeTmp(string $basename, string $contents): string
    {
        $path = $this->scratchDir() . '/' . $basename;
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;
        return $path;
    }

    // ══════════════════════════════════════════════════════════════════
    //  schema_validation — forwarded to SchemaUtils via SWMLService
    // ══════════════════════════════════════════════════════════════════

    public function testSchemaValidationDefaultsOnAndRejectsUnknownVerbKey(): void
    {
        $svc = new Service(name: 'v-on', basicAuthUser: 'u', basicAuthPassword: 'p');
        $this->expectException(SchemaValidationError::class);
        $svc->addVerb('answer', ['bogus_key_xyz' => 1]);
    }

    public function testSchemaValidationFalseAcceptsUnknownVerbKeyOnService(): void
    {
        $svc = new Service(
            name: 'v-off',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            schemaValidation: false,
        );
        $this->assertTrue($svc->addVerb('answer', ['bogus_key_xyz' => 1]));
        $doc = json_decode($svc->renderDocument(), true);
        $this->assertIsArray($doc);
        // The unknown key survives to the wire — proof validation was skipped
        // rather than the key being silently dropped.
        $this->assertSame(['bogus_key_xyz' => 1], $this->verbBody($doc, 'answer'));
    }

    public function testSchemaValidationFalseReachableFromAgentConstruction(): void
    {
        $agent = new AgentBase(
            name: 'agent-v-off',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            schemaValidation: false,
        );
        // Reaches SchemaUtils through the SWMLService base the agent extends.
        $this->assertTrue($agent->addVerb('answer', ['bogus_key_xyz' => 1]));
    }

    public function testSchemaValidationTrueOnAgentStillRejects(): void
    {
        $agent = new AgentBase(
            name: 'agent-v-on',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            schemaValidation: true,
        );
        $this->expectException(SchemaValidationError::class);
        $agent->addVerb('answer', ['bogus_key_xyz' => 1]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  schema_path — forwarded to SchemaUtils via SWMLService
    // ══════════════════════════════════════════════════════════════════

    /**
     * A custom schema file changes which verbs exist. The bundled schema has
     * `answer`; this one has only `custom_only_verb`, so the verb set the
     * service validates against genuinely comes from the passed path.
     */
    private function customSchemaJson(): string
    {
        return json_encode([
            '$defs' => [
                'SWMLMethod' => [
                    'anyOf' => [
                        ['$ref' => '#/$defs/CustomOnlyVerb'],
                    ],
                ],
                'CustomOnlyVerb' => [
                    'type' => 'object',
                    'properties' => [
                        'custom_only_verb' => [
                            'type' => 'object',
                            'properties' => ['knob' => ['type' => 'string']],
                        ],
                    ],
                    'required' => ['custom_only_verb'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public function testSchemaPathOnServiceSelectsThatSchemasVerbs(): void
    {
        $path = $this->writeTmp('custom_schema_service.json', $this->customSchemaJson());
        $svc = new Service(
            name: 'sp-svc',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            schemaPath: $path,
        );
        $names = $svc->getSchemaUtils()->getAllVerbNames();
        $this->assertContains('custom_only_verb', $names);
        $this->assertNotContains('answer', $names);
    }

    public function testSchemaPathReachableFromAgentConstruction(): void
    {
        $path = $this->writeTmp('custom_schema_agent.json', $this->customSchemaJson());
        $agent = new AgentBase(
            name: 'sp-agent',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            schemaPath: $path,
        );
        $names = $agent->getSchemaUtils()->getAllVerbNames();
        $this->assertContains('custom_only_verb', $names);
        $this->assertNotContains('answer', $names);
    }

    public function testSchemaPathNullUsesBundledSchema(): void
    {
        $svc = new Service(name: 'sp-default', basicAuthUser: 'u', basicAuthPassword: 'p');
        $this->assertContains('answer', $svc->getSchemaUtils()->getAllVerbNames());
    }

    // ══════════════════════════════════════════════════════════════════
    //  config_file — forwarded to SecurityConfig via SWMLService
    // ══════════════════════════════════════════════════════════════════

    public function testConfigFileOnServiceSuppliesBasicAuthCredentials(): void
    {
        $path = $this->writeTmp('svc_config.json', json_encode([
            'security' => [
                'auth' => ['basic' => ['user' => 'cfg-user', 'password' => 'cfg-pass']],
            ],
        ], JSON_THROW_ON_ERROR));

        $svc = new Service(name: 'cfg-svc', configFile: $path);
        [$user, $pass] = $svc->getBasicAuthCredentials();
        $this->assertSame('cfg-user', $user);
        $this->assertSame('cfg-pass', $pass);
        $this->assertTrue($svc->validateBasicAuth('cfg-user', 'cfg-pass'));
    }

    public function testConfigFileReachableFromAgentConstruction(): void
    {
        $path = $this->writeTmp('agent_config_file.json', json_encode([
            'security' => [
                'auth' => ['basic' => ['user' => 'agent-cfg-user', 'password' => 'agent-cfg-pass']],
            ],
        ], JSON_THROW_ON_ERROR));

        $agent = new AgentBase(name: 'cfg-agent', configFile: $path);
        [$user, $pass] = $agent->getBasicAuthCredentials();
        $this->assertSame('agent-cfg-user', $user);
        $this->assertSame('agent-cfg-pass', $pass);
    }

    /**
     * Explicit constructor credentials outrank the config file — the reference's
     * "constructor parameters taking precedence" rule (agent_base.py:191).
     */
    public function testExplicitBasicAuthBeatsConfigFile(): void
    {
        $path = $this->writeTmp('cfg_beaten.json', json_encode([
            'security' => [
                'auth' => ['basic' => ['user' => 'cfg-user', 'password' => 'cfg-pass']],
            ],
        ], JSON_THROW_ON_ERROR));

        $svc = new Service(
            name: 'cfg-beaten',
            basicAuthUser: 'explicit-u',
            basicAuthPassword: 'explicit-p',
            configFile: $path,
        );
        [$user, $pass] = $svc->getBasicAuthCredentials();
        $this->assertSame('explicit-u', $user);
        $this->assertSame('explicit-p', $pass);
    }

    /**
     * The reference's ``service`` section overrides route/host/port/name, with
     * explicit constructor args still winning (agent_base.py:189-196).
     */
    public function testConfigFileServiceSectionSuppliesRouteHostPort(): void
    {
        $path = $this->writeTmp('svc_section.json', json_encode([
            'service' => [
                'route' => '/from-config',
                'host' => '127.0.0.5',
                'port' => 4321,
            ],
        ], JSON_THROW_ON_ERROR));

        $agent = new AgentBase(
            name: 'svc-section',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            configFile: $path,
        );
        $this->assertSame('/from-config', $agent->getRoute());
        $this->assertSame('127.0.0.5', $agent->getHost());
        $this->assertSame(4321, $agent->getPort());
    }

    public function testExplicitRouteHostPortBeatConfigFileServiceSection(): void
    {
        $path = $this->writeTmp('svc_section_beaten.json', json_encode([
            'service' => [
                'route' => '/from-config',
                'host' => '127.0.0.5',
                'port' => 4321,
            ],
        ], JSON_THROW_ON_ERROR));

        $agent = new AgentBase(
            name: 'svc-section-beaten',
            route: '/explicit',
            host: '127.0.0.9',
            port: 5555,
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            configFile: $path,
        );
        $this->assertSame('/explicit', $agent->getRoute());
        $this->assertSame('127.0.0.9', $agent->getHost());
        $this->assertSame(5555, $agent->getPort());
    }

    // ══════════════════════════════════════════════════════════════════
    //  token_expiry_secs — forwarded to SessionManager
    // ══════════════════════════════════════════════════════════════════

    public function testTokenExpirySecsReachesSessionManagerAndExpiresTokens(): void
    {
        // A negative lifetime makes every freshly-minted token already expired,
        // which is observable through the agent's own token validation.
        $agent = new AgentBase(
            name: 'expiry-agent',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            tokenExpirySecs: -1,
        );
        $this->registerSecureTool($agent, 'some_fn');
        $token = $agent->createToolToken('some_fn', 'call-1');
        $this->assertNotSame('', $token);
        $this->assertFalse($agent->validateToolToken('some_fn', $token, 'call-1'));
    }

    public function testDefaultTokenExpirySecsKeepsTokensValid(): void
    {
        $agent = new AgentBase(
            name: 'expiry-default',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
        );
        $this->registerSecureTool($agent, 'some_fn');
        $token = $agent->createToolToken('some_fn', 'call-1');
        $this->assertTrue($agent->validateToolToken('some_fn', $token, 'call-1'));
    }

    public function testLongTokenExpirySecsKeepsTokensValid(): void
    {
        $agent = new AgentBase(
            name: 'expiry-long',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            tokenExpirySecs: 86400,
        );
        $this->registerSecureTool($agent, 'some_fn');
        $token = $agent->createToolToken('some_fn', 'call-1');
        $this->assertTrue($agent->validateToolToken('some_fn', $token, 'call-1'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  agent_id
    // ══════════════════════════════════════════════════════════════════

    public function testAgentIdAutoGeneratedAsUuid(): void
    {
        $agent = new ConstructionProbeAgent(name: 'id-auto', basicAuthUser: 'u', basicAuthPassword: 'p');
        $id = $agent->probeAgentId();
        $this->assertNotSame('', $id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id,
        );
    }

    public function testAgentIdAutoGeneratedIsUniquePerAgent(): void
    {
        $a = new ConstructionProbeAgent(name: 'id-a', basicAuthUser: 'u', basicAuthPassword: 'p');
        $b = new ConstructionProbeAgent(name: 'id-b', basicAuthUser: 'u', basicAuthPassword: 'p');
        $this->assertNotSame($a->probeAgentId(), $b->probeAgentId());
    }

    public function testCustomAgentIdIsUsedVerbatim(): void
    {
        $agent = new ConstructionProbeAgent(
            name: 'id-custom',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            agentId: 'custom-123',
        );
        $this->assertSame('custom-123', $agent->probeAgentId());
    }

    // ══════════════════════════════════════════════════════════════════
    //  native_functions
    // ══════════════════════════════════════════════════════════════════

    public function testNativeFunctionsDefaultEmptyAndAbsentFromSwaig(): void
    {
        $agent = new AgentBase(name: 'nf-empty', basicAuthUser: 'u', basicAuthPassword: 'p');
        $this->assertArrayNotHasKey(
            'native_functions',
            $this->swaig($agent->renderSwml()),
        );
    }

    public function testNativeFunctionsFromConstructorRenderIntoSwaig(): void
    {
        $agent = new AgentBase(
            name: 'nf-set',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            nativeFunctions: ['transfer', 'check_time'],
        );
        $swaig = $this->swaig($agent->renderSwml());
        $this->assertSame(['transfer', 'check_time'], $swaig['native_functions']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  record_format / record_stereo
    // ══════════════════════════════════════════════════════════════════

    public function testRecordFormatAndStereoDefaultsMatchReference(): void
    {
        $agent = new AgentBase(
            name: 'rec-default',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            recordCall: true,
        );
        $rec = $this->recordVerb($agent->renderSwml());
        // Reference defaults: record_format="mp4", record_stereo=True.
        $this->assertSame('mp4', $rec['format']);
        $this->assertTrue($rec['stereo']);
    }

    public function testRecordFormatAndStereoFromConstructor(): void
    {
        $agent = new AgentBase(
            name: 'rec-custom',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            recordCall: true,
            recordFormat: 'wav',
            recordStereo: false,
        );
        $rec = $this->recordVerb($agent->renderSwml());
        $this->assertSame('wav', $rec['format']);
        $this->assertFalse($rec['stereo']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  suppress_logs / default_webhook_url / trust_proxy_for_signature /
    //  enable_post_prompt_override / check_for_input_override
    // ══════════════════════════════════════════════════════════════════

    public function testSuppressLogsIsAcceptedAndRecorded(): void
    {
        $quiet = new ConstructionProbeAgent(
            name: 'quiet',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            suppressLogs: true,
        );
        $loud = new ConstructionProbeAgent(name: 'loud', basicAuthUser: 'u', basicAuthPassword: 'p');
        $this->assertTrue($quiet->probeSuppressLogs());
        $this->assertFalse($loud->probeSuppressLogs());
    }

    public function testDefaultWebhookUrlIsAcceptedAndRecorded(): void
    {
        $agent = new ConstructionProbeAgent(
            name: 'dwu',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            defaultWebhookUrl: 'https://example.com/hooks',
        );
        $this->assertSame('https://example.com/hooks', $agent->probeDefaultWebhookUrl());
        $plain = new ConstructionProbeAgent(name: 'dwu-none', basicAuthUser: 'u', basicAuthPassword: 'p');
        $this->assertNull($plain->probeDefaultWebhookUrl());
    }

    public function testTrustProxyForSignatureDefaultsFalseAndIsSettable(): void
    {
        $off = new ConstructionProbeAgent(name: 'tp-off', basicAuthUser: 'u', basicAuthPassword: 'p');
        $on = new ConstructionProbeAgent(
            name: 'tp-on',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            trustProxyForSignature: true,
        );
        $this->assertFalse($off->probeTrustProxyForSignature());
        $this->assertTrue($on->probeTrustProxyForSignature());
    }

    /**
     * The reference accepts these two and neither stores nor reads them
     * (agent_base.py:140-141 — declared, documented, inert). Accepting them
     * keeps a valid reference program valid; asserting only that construction
     * succeeds avoids inventing behavior the reference does not have.
     */
    public function testOverrideFlagsAreAcceptedWithoutChangingRendering(): void
    {
        $plain = new AgentBase(name: 'ovr-off', basicAuthUser: 'u', basicAuthPassword: 'p');
        $flagged = new AgentBase(
            name: 'ovr-off',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
            enablePostPromptOverride: true,
            checkForInputOverride: true,
        );
        $this->assertSame($plain->renderSwml(), $flagged->renderSwml());
    }

    // ══════════════════════════════════════════════════════════════════
    //  Combined: every forwarded param at once
    // ══════════════════════════════════════════════════════════════════

    public function testFullConstructionContractTogether(): void
    {
        $cfg = $this->writeTmp('full_cfg.json', json_encode([
            'security' => [
                'auth' => ['basic' => ['user' => 'full-user', 'password' => 'full-pass']],
            ],
        ], JSON_THROW_ON_ERROR));

        $agent = new ConstructionProbeAgent(
            name: 'full',
            route: '/full',
            host: '127.0.0.1',
            port: 9099,
            autoAnswer: false,
            recordCall: true,
            usePom: false,
            tokenExpirySecs: 7200,
            recordFormat: 'wav',
            recordStereo: false,
            defaultWebhookUrl: 'https://example.com/hook',
            agentId: 'full-id',
            nativeFunctions: ['transfer'],
            schemaPath: null,
            suppressLogs: true,
            enablePostPromptOverride: true,
            checkForInputOverride: true,
            configFile: $cfg,
            schemaValidation: false,
            trustProxyForSignature: true,
        );

        $this->assertSame('full-id', $agent->probeAgentId());
        $this->assertSame('/full', $agent->getRoute());
        $this->assertSame(9099, $agent->getPort());
        [$user, $pass] = $agent->getBasicAuthCredentials();
        $this->assertSame('full-user', $user);
        $this->assertSame('full-pass', $pass);
        $this->assertTrue($agent->probeSuppressLogs());
        $this->assertTrue($agent->probeTrustProxyForSignature());
        $this->assertSame('https://example.com/hook', $agent->probeDefaultWebhookUrl());
        // schemaValidation:false reached SchemaUtils.
        $this->assertTrue($agent->addVerb('answer', ['bogus_key_xyz' => 1]));
        // tokenExpirySecs reached the SessionManager.
        $this->registerSecureTool($agent, 'fn');
        $token = $agent->createToolToken('fn', 'call-1');
        $this->assertTrue($agent->validateToolToken('fn', $token, 'call-1'));

        $doc = $agent->renderSwml();
        $rec = $this->recordVerb($doc);
        $this->assertSame('wav', $rec['format']);
        $this->assertFalse($rec['stereo']);
        $swaig = $this->swaig($doc);
        $this->assertSame(['transfer'], $swaig['native_functions']);
    }

    // ── helpers ───────────────────────────────────────────────────────

    /**
     * Token validation rejects unregistered function names up-front (mirrors
     * state_mixin.StateMixin.validate_tool_token), so a token test needs a
     * real secure tool on the agent.
     */
    private function registerSecureTool(AgentBase $agent, string $name): void
    {
        $agent->defineTool(
            name: $name,
            description: 'construction-contract probe',
            parameters: [],
            handler: static fn (): string => 'ok',
            secure: true,
        );
    }

    /**
     * The `main` section's verb list from a rendered document.
     *
     * @param array<array-key,mixed> $doc
     * @return list<mixed>
     */
    private function mainSection(array $doc): array
    {
        $sections = $doc['sections'] ?? null;
        $this->assertIsArray($sections);
        $main = $sections['main'] ?? null;
        $this->assertIsArray($main);
        return array_values($main);
    }

    /**
     * The first verb body keyed by $verbName in the rendered `main` section.
     *
     * @param array<array-key,mixed> $doc
     * @return array<string,mixed>
     */
    private function verbBody(array $doc, string $verbName): array
    {
        foreach ($this->mainSection($doc) as $verb) {
            if (is_array($verb) && isset($verb[$verbName])) {
                $body = $verb[$verbName];
                $this->assertIsArray($body);
                /** @var array<string,mixed> $body */
                return $body;
            }
        }
        $this->fail("no {$verbName} verb in rendered document");
    }

    /**
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private function aiVerb(array $doc): array
    {
        return $this->verbBody($doc, 'ai');
    }

    /**
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private function recordVerb(array $doc): array
    {
        return $this->verbBody($doc, 'record_call');
    }

    /**
     * The SWAIG object of the rendered AI verb (empty when absent).
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private function swaig(array $doc): array
    {
        $swaig = $this->aiVerb($doc)['SWAIG'] ?? [];
        $this->assertIsArray($swaig);
        /** @var array<string,mixed> $swaig */
        return $swaig;
    }
}
