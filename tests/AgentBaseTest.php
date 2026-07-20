<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\Logging\Logger;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\SWML\Schema;
use SignalWire\Tests\Support\Shape;

class AgentBaseTest extends TestCase
{
    protected function setUp(): void
    {
        Logger::reset();
        Schema::reset();
        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
        putenv('SWML_PROXY_URL_BASE');
        putenv('PORT');
    }

    protected function tearDown(): void
    {
        Logger::reset();
        Schema::reset();
        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
        putenv('SWML_PROXY_URL_BASE');
        putenv('PORT');
    }

    /**
     * @param array{
     *   name?: string,
     *   route?: string,
     *   host?: string|null,
     *   port?: int|null,
     *   basic_auth_user?: string|null,
     *   basic_auth_password?: string|null,
     *   auto_answer?: bool,
     *   record_call?: bool,
     *   use_pom?: bool,
     * } $opts
     */
    private function makeAgent(array $opts = []): AgentBase
    {
        return new AgentBase(
            name: $opts['name'] ?? 'test-agent',
            route: $opts['route'] ?? '/',
            host: $opts['host'] ?? null,
            port: $opts['port'] ?? null,
            basicAuthUser: $opts['basic_auth_user'] ?? 'testuser',
            basicAuthPassword: $opts['basic_auth_password'] ?? 'testpass',
            autoAnswer: $opts['auto_answer'] ?? true,
            recordCall: $opts['record_call'] ?? false,
            usePom: $opts['use_pom'] ?? true,
        );
    }

    /** @return array<string,string> */
    private function authHeader(string $user = 'testuser', string $pass = 'testpass'): array
    {
        return ['Authorization' => 'Basic ' . base64_encode("{$user}:{$pass}")];
    }

    /**
     * The ordered verb names of a rendered SWML document's `main` section
     * (each verb is a single-key map like `['answer' => ...]`).
     *
     * @param array<string,mixed> $swml
     * @return list<int|string|null>
     */
    private function mainVerbNames(array $swml): array
    {
        return array_map(
            static fn (mixed $v): int|string|null => is_array($v) ? array_key_first($v) : null,
            array_values(Shape::sub($swml, 'sections', 'main'))
        );
    }

    // ------------------------------------------------------------------
    // 1. Construction
    // ------------------------------------------------------------------

    public function testConstructionDefaults(): void
    {
        $agent = $this->makeAgent();

        $this->assertSame('test-agent', $agent->getName());
        $this->assertSame('/', $agent->getRoute());
    }

    public function testConstructionAutoAnswerDefaultTrue(): void
    {
        $agent = $this->makeAgent();
        $swml = $agent->renderSwml();

        $verbs = $this->mainVerbNames($swml);
        $this->assertContains('answer', $verbs);
    }

    public function testConstructionRecordCallDefaultFalse(): void
    {
        $agent = $this->makeAgent();
        $swml = $agent->renderSwml();

        $verbs = $this->mainVerbNames($swml);
        $this->assertNotContains('record_call', $verbs);
    }

    // ------------------------------------------------------------------
    // 2. Custom route
    // ------------------------------------------------------------------

    public function testCustomRoute(): void
    {
        $agent = $this->makeAgent(['route' => '/myagent']);
        $this->assertSame('/myagent', $agent->getRoute());
    }

    // ------------------------------------------------------------------
    // 3. Prompt POM mode
    // ------------------------------------------------------------------

    public function testPromptPomMode(): void
    {
        $agent = $this->makeAgent();
        $agent->promptAddSection('Role', 'You are a helpful assistant.');

        $prompt = $agent->getPrompt();
        $this->assertIsArray($prompt);
        $this->assertCount(1, $prompt);
        $this->assertSame('Role', $prompt[0]['title']);
        $this->assertSame('You are a helpful assistant.', $prompt[0]['body']);
    }

    // ------------------------------------------------------------------
    // 4. Prompt raw text
    // ------------------------------------------------------------------

    public function testPromptRawText(): void
    {
        $agent = $this->makeAgent(['use_pom' => false]);
        $agent->setPromptText('You are a helpful assistant.');

        $prompt = $agent->getPrompt();
        $this->assertIsString($prompt);
        $this->assertSame('You are a helpful assistant.', $prompt);
    }

    // ------------------------------------------------------------------
    // 5. Prompt subsections
    // ------------------------------------------------------------------

    public function testPromptSubsections(): void
    {
        $agent = $this->makeAgent();
        $agent->promptAddSection('Main', 'Main body text.');
        $agent->promptAddSubsection('Main', 'Detail', 'Detail body text.');

        $prompt = $agent->getPrompt();
        $this->assertIsArray($prompt);
        $this->assertArrayHasKey('subsections', Shape::sub($prompt, 0));
        $this->assertCount(1, Shape::sub($prompt, 0, 'subsections'));
        $this->assertSame('Detail', Shape::at($prompt, 0, 'subsections', 0, 'title'));
        $this->assertSame('Detail body text.', Shape::at($prompt, 0, 'subsections', 0, 'body'));
    }

    // ------------------------------------------------------------------
    // 6. Prompt addToSection
    // ------------------------------------------------------------------

    public function testPromptAddToSection(): void
    {
        $agent = $this->makeAgent();
        $agent->promptAddSection('Rules', 'Base rules.');
        $agent->promptAddToSection('Rules', ' Extra rules.', ['bullet one', 'bullet two']);

        $prompt = $agent->getPrompt();
        $this->assertSame('Base rules. Extra rules.', Shape::at($prompt, 0, 'body'));
        $this->assertSame(['bullet one', 'bullet two'], Shape::at($prompt, 0, 'bullets'));
    }

    // ------------------------------------------------------------------
    // 7. Prompt hasSection
    // ------------------------------------------------------------------

    public function testPromptHasSectionTrue(): void
    {
        $agent = $this->makeAgent();
        $agent->promptAddSection('Greeting', 'Hello.');
        $this->assertTrue($agent->promptHasSection('Greeting'));
    }

    public function testPromptHasSectionFalse(): void
    {
        $agent = $this->makeAgent();
        $this->assertFalse($agent->promptHasSection('Nonexistent'));
    }

    // ------------------------------------------------------------------
    // 8. Tool registration
    // ------------------------------------------------------------------

    public function testDefineTool(): void
    {
        $agent = $this->makeAgent();
        $agent->defineTool(
            'lookup',
            'Look up a customer',
            ['id' => ['type' => 'string', 'description' => 'Customer ID']],
            fn (array $args, array $raw) => new FunctionResult('found'),
        );

        $swml = $agent->renderSwml();
        $ai = $this->extractAiVerb($swml);
        $functions = Shape::sub($ai, 'SWAIG', 'functions');

        $this->assertCount(1, $functions);
        $this->assertSame('lookup', Shape::at($functions, 0, 'function'));
        $this->assertSame('Look up a customer', Shape::at($functions, 0, 'purpose'));
        // Handler must be stripped from the output
        $this->assertArrayNotHasKey('_handler', Shape::sub($functions, 0));
        $this->assertArrayNotHasKey('_secure', Shape::sub($functions, 0));
    }

    // ------------------------------------------------------------------
    // 9. Tool dispatch
    // ------------------------------------------------------------------

    public function testOnFunctionCall(): void
    {
        $agent = $this->makeAgent();
        $agent->defineTool(
            'greet',
            'Greet the user',
            [],
            function (array $args, array $raw): FunctionResult {
                return new FunctionResult('Hello, ' . ($args['name'] ?? 'stranger'));
            },
        );

        $result = $agent->onFunctionCall('greet', ['name' => 'Alice'], []);
        $this->assertInstanceOf(FunctionResult::class, $result);
        $this->assertSame('Hello, Alice', $result->toArray()['response']);
    }

    public function testOnFunctionCallUnknownReturnsNull(): void
    {
        $agent = $this->makeAgent();
        $result = $agent->onFunctionCall('nonexistent', [], []);
        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // 10. registerSwaigFunction
    // ------------------------------------------------------------------

    public function testRegisterSwaigFunction(): void
    {
        $agent = $this->makeAgent();
        $agent->registerSwaigFunction([
            'function' => 'data_map_tool',
            'purpose' => 'A data map tool',
            'data_map' => ['webhooks' => []],
        ]);

        $swml = $agent->renderSwml();
        $ai = $this->extractAiVerb($swml);
        $functions = Shape::sub($ai, 'SWAIG', 'functions');

        $this->assertCount(1, $functions);
        $this->assertSame('data_map_tool', Shape::at($functions, 0, 'function'));
        $this->assertArrayHasKey('data_map', Shape::sub($functions, 0));
    }

    public function testRegisterSwaigFunctionEmptyNameIgnored(): void
    {
        $agent = $this->makeAgent();
        $agent->registerSwaigFunction(['purpose' => 'no name']);

        $swml = $agent->renderSwml();
        $ai = $this->extractAiVerb($swml);
        $this->assertArrayNotHasKey('SWAIG', $ai);
    }

    // ------------------------------------------------------------------
    // 11. defineTools
    // ------------------------------------------------------------------

    public function testDefineTools(): void
    {
        $agent = $this->makeAgent();
        $agent->defineTools([
            ['function' => 'tool_a', 'purpose' => 'Tool A'],
            ['function' => 'tool_b', 'purpose' => 'Tool B'],
        ]);

        $swml = $agent->renderSwml();
        $ai = $this->extractAiVerb($swml);
        $functions = Shape::sub($ai, 'SWAIG', 'functions');

        $this->assertCount(2, $functions);
        $this->assertSame('tool_a', Shape::at($functions, 0, 'function'));
        $this->assertSame('tool_b', Shape::at($functions, 1, 'function'));
    }

    // ------------------------------------------------------------------
    // 12. AI config methods
    // ------------------------------------------------------------------

    public function testAddHint(): void
    {
        $agent = $this->makeAgent();
        $agent->addHint('SignalWire');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertContains('SignalWire', Shape::sub($ai, 'hints'));
    }

    public function testAddHints(): void
    {
        $agent = $this->makeAgent();
        $agent->addHints(['hello', 'world']);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['hello', 'world'], Shape::at($ai, 'hints'));
    }

    public function testAddPatternHint(): void
    {
        $agent = $this->makeAgent();
        $agent->addPatternHint('SW', '\\bSW\\b', 'SignalWire');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertContains(
            ['hint' => 'SW', 'pattern' => '\\bSW\\b', 'replace' => 'SignalWire', 'ignore_case' => false],
            Shape::sub($ai, 'hints'),
        );
    }

    public function testAddLanguage(): void
    {
        $agent = $this->makeAgent();
        $agent->addLanguage('English', 'en-US', 'rachel');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertCount(1, Shape::sub($ai, 'languages'));
        $this->assertSame('English', Shape::at($ai, 'languages', 0, 'name'));
        $this->assertSame('en-US', Shape::at($ai, 'languages', 0, 'code'));
        $this->assertSame('rachel', Shape::at($ai, 'languages', 0, 'voice'));
    }

    // ------------------------------------------------------------------
    // 12a2. setMultilingual (ASR-driven Mode B).
    //
    //       Mirrors Python AIConfigMixin.set_multilingual + the go/TS ports:
    //       stores the config, empty config is a no-op, and the AI verb
    //       renders a top-level "multilingual" object.
    // ------------------------------------------------------------------

    public function testSetMultilingualStoresAndChains(): void
    {
        $agent = $this->makeAgent();
        $ret = $agent->setMultilingual(['start_language' => 'en-US', 'min_switch_words' => 2]);

        $this->assertSame($agent, $ret, 'setMultilingual should return $this for chaining');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(
            ['start_language' => 'en-US', 'min_switch_words' => 2],
            Shape::at($ai, 'multilingual'),
        );
    }

    public function testSetMultilingualEmptyIsNoop(): void
    {
        $agent = $this->makeAgent();
        $agent->setMultilingual([]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertArrayNotHasKey('multilingual', $ai);
    }

    // ------------------------------------------------------------------
    // 12b. Per-language params: addLanguage(params=...),
    //      setLanguageParams, getLanguageParams.
    //
    //      Mirrors Python tests/unit/core/mixins/test_ai_config_mixin.py
    //      ::TestPerLanguageParams. SWML wire key stays snake_case
    //      ("params"); PHP method names are camelCase.
    // ------------------------------------------------------------------

    public function testAddLanguageWithParamsAttachesParams(): void
    {
        $agent = $this->makeAgent();
        $agent->addLanguage(
            'English',
            'en-US',
            'josh',
            params: ['stability' => 0.5, 'similarity_boost' => 0.75],
        );

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(
            ['stability' => 0.5, 'similarity_boost' => 0.75],
            Shape::at($ai, 'languages', 0, 'params'),
        );
    }

    public function testAddLanguageWithoutParamsOmitsKey(): void
    {
        $agent = $this->makeAgent();
        $agent->addLanguage('French', 'fr-FR', 'fr-FR-Neural2-A');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertArrayNotHasKey('params', Shape::sub($ai, 'languages', 0));
    }

    public function testAddLanguageWithEmptyParamsOmitsKey(): void
    {
        $agent = $this->makeAgent();
        $agent->addLanguage('French', 'fr-FR', 'v', params: []);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertArrayNotHasKey('params', Shape::sub($ai, 'languages', 0));
    }

    public function testGetLanguageParamsReturnsSetDict(): void
    {
        $agent = $this->makeAgent();
        $agent->addLanguage('English', 'en-US', 'v', params: ['a' => 1]);
        $this->assertSame(['a' => 1], $agent->getLanguageParams('en-US'));
    }

    public function testGetLanguageParamsReturnsNullWhenUnset(): void
    {
        $agent = $this->makeAgent();
        $agent->addLanguage('English', 'en-US', 'v');
        $this->assertNull($agent->getLanguageParams('en-US'));
    }

    public function testGetLanguageParamsReturnsNullForUnknownCode(): void
    {
        $agent = $this->makeAgent();
        $this->assertNull($agent->getLanguageParams('zh-CN'));
    }

    public function testSetLanguageParamsReplacesExisting(): void
    {
        $agent = $this->makeAgent();
        $agent->addLanguage('English', 'en-US', 'v', params: ['a' => 1]);
        $agent->setLanguageParams('en-US', ['b' => 2]);

        $this->assertSame(['b' => 2], $agent->getLanguageParams('en-US'));
        // And the SWML output reflects the replacement.
        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['b' => 2], Shape::at($ai, 'languages', 0, 'params'));
    }

    public function testSetLanguageParamsAddsWhenUnset(): void
    {
        $agent = $this->makeAgent();
        $agent->addLanguage('English', 'en-US', 'v');
        $agent->setLanguageParams('en-US', ['c' => 3]);

        $this->assertSame(['c' => 3], $agent->getLanguageParams('en-US'));
        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['c' => 3], Shape::at($ai, 'languages', 0, 'params'));
    }

    public function testSetLanguageParamsEmptyArrayRemovesKey(): void
    {
        $agent = $this->makeAgent();
        $agent->addLanguage('English', 'en-US', 'v', params: ['a' => 1]);
        $agent->setLanguageParams('en-US', []);

        $this->assertNull($agent->getLanguageParams('en-US'));
        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertArrayNotHasKey('params', Shape::sub($ai, 'languages', 0));
    }

    public function testSetLanguageParamsUnknownCodeIsNoop(): void
    {
        $agent = $this->makeAgent();
        $agent->addLanguage('English', 'en-US', 'v');
        $agent->setLanguageParams('zh-CN', ['a' => 1]);

        // The known language remains untouched.
        $this->assertNull($agent->getLanguageParams('en-US'));
        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertArrayNotHasKey('params', Shape::sub($ai, 'languages', 0));
    }

    public function testSetLanguageParamsReturnsSelfForChaining(): void
    {
        $agent = $this->makeAgent();
        $agent->addLanguage('English', 'en-US', 'v');
        $this->assertSame($agent, $agent->setLanguageParams('en-US', ['a' => 1]));
    }

    public function testAddPronunciation(): void
    {
        $agent = $this->makeAgent();
        $agent->addPronunciation('SW', 'SignalWire');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertCount(1, Shape::sub($ai, 'pronounce'));
        $this->assertSame('SW', Shape::at($ai, 'pronounce', 0, 'replace'));
        $this->assertSame('SignalWire', Shape::at($ai, 'pronounce', 0, 'with'));
        // ignore_case omitted when false (Python parity)
        $rule = Shape::arr(Shape::at($ai, 'pronounce', 0));
        $this->assertArrayNotHasKey('ignore_case', $rule);
        $this->assertArrayNotHasKey('ignore', $rule);
    }

    public function testAddPronunciationIgnoreCase(): void
    {
        $agent = $this->makeAgent();
        $agent->addPronunciation('sw', 'SignalWire', ignoreCase: true);

        // Wire parity with Python: emits `ignore_case: true` (bool), not `ignore`.
        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertTrue(Shape::at($ai, 'pronounce', 0, 'ignore_case'));
    }

    public function testSetParam(): void
    {
        $agent = $this->makeAgent();
        $agent->setParam('temperature', 0.7);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(0.7, Shape::at($ai, 'params', 'temperature'));
    }

    public function testSetParams(): void
    {
        $agent = $this->makeAgent();
        $agent->setParams(['temperature' => 0.5, 'top_p' => 0.9]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(0.5, Shape::at($ai, 'params', 'temperature'));
        $this->assertSame(0.9, Shape::at($ai, 'params', 'top_p'));
    }

    public function testSetGlobalData(): void
    {
        $agent = $this->makeAgent();
        $agent->setGlobalData(['key' => 'value']);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['key' => 'value'], Shape::at($ai, 'global_data'));
    }

    public function testUpdateGlobalData(): void
    {
        $agent = $this->makeAgent();
        $agent->setGlobalData(['a' => 1]);
        $agent->updateGlobalData(['b' => 2]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['a' => 1, 'b' => 2], Shape::at($ai, 'global_data'));
    }

    public function testSetNativeFunctions(): void
    {
        $agent = $this->makeAgent();
        $agent->setNativeFunctions(['check_voicemail', 'send_digits']);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['check_voicemail', 'send_digits'], Shape::at($ai, 'SWAIG', 'native_functions'));
    }

    // ------------------------------------------------------------------
    // 13. Internal fillers
    // ------------------------------------------------------------------

    public function testSetInternalFillers(): void
    {
        $agent = $this->makeAgent();
        // Expected format: ['function_name' => ['language_code' => ['phrase', ...]]]
        // Mirrors the Python reference test_sets_internal_fillers_with_valid_dict.
        $fillers = ['next_step' => ['en-US' => ['Moving on...', "Let's continue..."]]];
        $agent->setInternalFillers($fillers);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame($fillers, Shape::at($ai, 'params', 'internal_fillers'));
    }

    public function testAddInternalFiller(): void
    {
        $agent = $this->makeAgent();
        $agent->addInternalFiller('hmm');
        $agent->addInternalFiller('uh');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['hmm', 'uh'], Shape::at($ai, 'params', 'internal_fillers'));
    }

    // ------------------------------------------------------------------
    // 14. Debug events
    // ------------------------------------------------------------------

    public function testEnableDebugEvents(): void
    {
        $agent = $this->makeAgent();
        $agent->enableDebugEvents();

        // Wire parity with Python: default level 1 emitted as the AI param
        // `debug_webhook_level` (int), not a `debug_events` string.
        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(1, Shape::at($ai, 'params', 'debug_webhook_level'));
    }

    public function testEnableDebugEventsCustomLevel(): void
    {
        $agent = $this->makeAgent();
        $agent->enableDebugEvents(2);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(2, Shape::at($ai, 'params', 'debug_webhook_level'));
    }

    // ------------------------------------------------------------------
    // 15. Function includes
    // ------------------------------------------------------------------

    public function testAddFunctionInclude(): void
    {
        $agent = $this->makeAgent();
        $agent->addFunctionInclude([
            'url' => 'https://example.com/funcs',
            'functions' => ['func_a'],
        ]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertCount(1, Shape::sub($ai, 'SWAIG', 'includes'));
        $this->assertSame('https://example.com/funcs', Shape::at($ai, 'SWAIG', 'includes', 0, 'url'));
    }

    public function testSetFunctionIncludes(): void
    {
        $agent = $this->makeAgent();
        $agent->addFunctionInclude(['url' => 'https://first.com', 'functions' => ['x']]);
        $agent->setFunctionIncludes([
            ['url' => 'https://replaced.com', 'functions' => ['y']],
        ]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertCount(1, Shape::sub($ai, 'SWAIG', 'includes'));
        $this->assertSame('https://replaced.com', Shape::at($ai, 'SWAIG', 'includes', 0, 'url'));
    }

    // ------------------------------------------------------------------
    // 16. LLM params
    // ------------------------------------------------------------------

    public function testSetPromptLlmParams(): void
    {
        $agent = $this->makeAgent();
        $agent->setPromptText('Hello');
        $agent->setPromptLlmParams(['temperature' => 0.3, 'top_p' => 0.8]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(0.3, Shape::at($ai, 'prompt', 'temperature'));
        $this->assertSame(0.8, Shape::at($ai, 'prompt', 'top_p'));
    }

    public function testSetPostPromptLlmParams(): void
    {
        $agent = $this->makeAgent();
        $agent->setPostPrompt('Summarize the call.');
        $agent->setPostPromptLlmParams(['temperature' => 0.1]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(0.1, Shape::at($ai, 'post_prompt', 'temperature'));
    }

    // ------------------------------------------------------------------
    // 17. Verb management
    // ------------------------------------------------------------------

    public function testAddPreAnswerVerb(): void
    {
        $agent = $this->makeAgent();
        $agent->addPreAnswerVerb('play', ['url' => 'ring.wav']);

        $swml = $agent->renderSwml();
        $first = Shape::sub($swml, 'sections', 'main', 0);

        // Pre-answer verb should be the first verb
        $this->assertSame('play', array_key_first($first));
        $this->assertSame(['url' => 'ring.wav'], $first['play'] ?? null);
    }

    public function testAddPostAnswerVerb(): void
    {
        $agent = $this->makeAgent();
        $agent->addPostAnswerVerb('play', ['url' => 'welcome.wav']);

        $swml = $agent->renderSwml();
        // Post-answer verb should be after 'answer', before 'ai'
        $verbNames = $this->mainVerbNames($swml);
        $answerIdx = array_search('answer', $verbNames, true);
        $playIdx = array_search('play', $verbNames, true);
        $aiIdx = array_search('ai', $verbNames, true);

        $this->assertGreaterThan($answerIdx, $playIdx);
        $this->assertLessThan($aiIdx, $playIdx);
    }

    public function testAddPostAiVerb(): void
    {
        $agent = $this->makeAgent();
        $agent->addPostAiVerb('hangup', []);

        $swml = $agent->renderSwml();
        $verbNames = $this->mainVerbNames($swml);
        $aiIdx = array_search('ai', $verbNames, true);
        $hangupIdx = array_search('hangup', $verbNames, true);

        $this->assertGreaterThan($aiIdx, $hangupIdx);
    }

    public function testClearPreAnswerVerbs(): void
    {
        $agent = $this->makeAgent();
        $agent->addPreAnswerVerb('play', ['url' => 'ring.wav']);
        $agent->clearPreAnswerVerbs();

        $swml = $agent->renderSwml();
        $verbNames = $this->mainVerbNames($swml);
        $this->assertNotContains('play', $verbNames);
    }

    public function testClearPostAnswerVerbs(): void
    {
        $agent = $this->makeAgent();
        $agent->addPostAnswerVerb('play', ['url' => 'welcome.wav']);
        $agent->clearPostAnswerVerbs();

        $swml = $agent->renderSwml();
        $verbNames = $this->mainVerbNames($swml);
        $this->assertNotContains('play', $verbNames);
    }

    public function testClearPostAiVerbs(): void
    {
        $agent = $this->makeAgent();
        $agent->addPostAiVerb('hangup', []);
        $agent->clearPostAiVerbs();

        $swml = $agent->renderSwml();
        $verbNames = $this->mainVerbNames($swml);
        $this->assertNotContains('hangup', $verbNames);
    }

    // ------------------------------------------------------------------
    // 18. SWML rendering basic structure
    // ------------------------------------------------------------------

    public function testRenderSwmlStructure(): void
    {
        $agent = $this->makeAgent();
        $swml = $agent->renderSwml();

        $this->assertArrayHasKey('version', $swml);
        $this->assertSame('1.0.0', $swml['version']);
        $this->assertArrayHasKey('sections', $swml);
        $this->assertArrayHasKey('main', Shape::sub($swml, 'sections'));

        $verbNames = $this->mainVerbNames($swml);
        $this->assertContains('answer', $verbNames);
        $this->assertContains('ai', $verbNames);
    }

    // ------------------------------------------------------------------
    // 19. SWML with text prompt (usePom=false)
    // ------------------------------------------------------------------

    public function testRenderSwmlWithTextPrompt(): void
    {
        $agent = $this->makeAgent(['use_pom' => false]);
        $agent->setPromptText('You are a receptionist.');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame('You are a receptionist.', Shape::at($ai, 'prompt', 'text'));
        $this->assertArrayNotHasKey('pom', Shape::sub($ai, 'prompt'));
    }

    // ------------------------------------------------------------------
    // 20. SWML with POM
    // ------------------------------------------------------------------

    public function testRenderSwmlWithPomPrompt(): void
    {
        $agent = $this->makeAgent();
        $agent->promptAddSection('Identity', 'You are a doctor.');
        $agent->promptAddSection('Rules', 'Be polite.', ['no cursing', 'be patient']);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertArrayHasKey('pom', Shape::sub($ai, 'prompt'));
        $this->assertCount(2, Shape::sub($ai, 'prompt', 'pom'));
        $this->assertSame('Identity', Shape::at($ai, 'prompt', 'pom', 0, 'title'));
        $this->assertSame('Rules', Shape::at($ai, 'prompt', 'pom', 1, 'title'));
        $this->assertSame(['no cursing', 'be patient'], Shape::at($ai, 'prompt', 'pom', 1, 'bullets'));
    }

    // ------------------------------------------------------------------
    // 21. Post-prompt in SWML
    // ------------------------------------------------------------------

    public function testRenderSwmlWithPostPrompt(): void
    {
        $agent = $this->makeAgent();
        $agent->setPostPrompt('Summarize the conversation.');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertArrayHasKey('post_prompt', $ai);
        $this->assertSame('Summarize the conversation.', Shape::at($ai, 'post_prompt', 'text'));
    }

    // ------------------------------------------------------------------
    // 22. Tools in SWML
    // ------------------------------------------------------------------

    public function testRenderSwmlWithTools(): void
    {
        $agent = $this->makeAgent();
        $agent->defineTool(
            'get_weather',
            'Get the weather',
            ['city' => ['type' => 'string']],
            fn (array $args, array $raw) => new FunctionResult('Sunny'),
        );

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertArrayHasKey('SWAIG', $ai);
        $this->assertArrayHasKey('functions', Shape::sub($ai, 'SWAIG'));
        $this->assertCount(1, Shape::sub($ai, 'SWAIG', 'functions'));

        $func = Shape::sub($ai, 'SWAIG', 'functions', 0);
        $this->assertSame('get_weather', $func['function'] ?? null);
        $this->assertSame('Get the weather', $func['purpose'] ?? null);
        $this->assertArrayNotHasKey('_handler', $func);
        $this->assertArrayNotHasKey('_secure', $func);
        $this->assertArrayHasKey('web_hook_url', $func);
    }

    // ------------------------------------------------------------------
    // 23. Hints in SWML
    // ------------------------------------------------------------------

    public function testRenderSwmlWithHints(): void
    {
        $agent = $this->makeAgent();
        $agent->addHint('SignalWire');
        $agent->addHint('FreeSWITCH');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['SignalWire', 'FreeSWITCH'], Shape::at($ai, 'hints'));
    }

    // ------------------------------------------------------------------
    // 24. Languages in SWML
    // ------------------------------------------------------------------

    public function testRenderSwmlWithLanguages(): void
    {
        $agent = $this->makeAgent();
        $agent->addLanguage('English', 'en-US', 'rachel');
        $agent->addLanguage('Spanish', 'es-ES', 'pedro');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertCount(2, Shape::sub($ai, 'languages'));
        $this->assertSame('English', Shape::at($ai, 'languages', 0, 'name'));
        $this->assertSame('Spanish', Shape::at($ai, 'languages', 1, 'name'));
    }

    // ------------------------------------------------------------------
    // 25. Dynamic config callback
    // ------------------------------------------------------------------

    public function testDynamicConfigCallback(): void
    {
        $agent = $this->makeAgent();
        $agent->setPromptText('Base prompt.');

        $callbackInvoked = false;
        $agent->setDynamicConfigCallback(function (
            array $queryParams,
            ?array $requestData,
            array $headers,
            AgentBase $clone,
        ) use (&$callbackInvoked): void {
            $callbackInvoked = true;
            $clone->setPromptText('Dynamic prompt.');
        });

        // Simulate a SWML request via handleRequest
        [$status, , $body] = $agent->handleRequest('POST', '/', $this->authHeader(), '{}');

        $this->assertTrue($callbackInvoked);
        $this->assertSame(200, $status);

        $decoded = json_decode($body, true);
        $aiVerb = $this->extractAiVerb(Shape::arr($decoded));
        $this->assertSame('Dynamic prompt.', Shape::at($aiVerb, 'prompt', 'text'));
    }

    // ------------------------------------------------------------------
    // 26. Dynamic config isolation
    // ------------------------------------------------------------------

    public function testDynamicConfigIsolation(): void
    {
        $agent = $this->makeAgent();
        $agent->setPromptText('Original prompt.');

        $agent->setDynamicConfigCallback(function (
            array $queryParams,
            ?array $requestData,
            array $headers,
            AgentBase $clone,
        ): void {
            $clone->setPromptText('Modified prompt.');
            $clone->addHint('new hint');
        });

        // Trigger the dynamic config path
        $agent->handleRequest('POST', '/', $this->authHeader(), '{}');

        // The original agent should be unchanged
        $this->assertSame('Original prompt.', $agent->getPrompt());
    }

    // ------------------------------------------------------------------
    // 27. Summary callback
    // ------------------------------------------------------------------

    public function testOnSummaryCallback(): void
    {
        $agent = $this->makeAgent();

        $receivedSummary = null;
        // The overridable on_summary(summary, raw_data) handler is the canonical
        // contract; setSummaryCallback is the PHP-additive registrar convenience.
        $agent->setSummaryCallback(function ($summary, array $data, array $headers) use (&$receivedSummary): void {
            $receivedSummary = $summary;
        });

        $postPromptBody = json_encode([
            'post_prompt_data' => ['raw' => 'The call was about billing.'],
        ]);
        $this->assertIsString($postPromptBody);

        [$status, ,] = $agent->handleRequest(
            'POST',
            '/post_prompt',
            $this->authHeader(),
            $postPromptBody,
        );

        $this->assertSame(200, $status);
        $this->assertSame('The call was about billing.', $receivedSummary);
    }

    // ------------------------------------------------------------------
    // 28. Context builder
    // ------------------------------------------------------------------

    public function testDefineContextsReturnsContextBuilder(): void
    {
        $agent = $this->makeAgent();
        $builder = $agent->defineContexts();

        // A fresh builder has no contexts defined yet.
        $this->assertFalse($builder->hasContexts());
    }

    public function testDefineContextsReturnsSameInstance(): void
    {
        $agent = $this->makeAgent();
        $builder1 = $agent->defineContexts();
        $builder2 = $agent->defineContexts();

        $this->assertSame($builder1, $builder2);
    }

    // ------------------------------------------------------------------
    // 29. Method chaining
    // ------------------------------------------------------------------

    public function testMethodChainingReturnsAgent(): void
    {
        $agent = $this->makeAgent();

        $this->assertSame($agent, $agent->setPromptText('text'));
        $this->assertSame($agent, $agent->setPostPrompt('post'));
        $this->assertSame($agent, $agent->promptAddSection('S', 'B'));
        $this->assertSame($agent, $agent->promptAddSubsection('S', 'Sub', 'B'));
        $this->assertSame($agent, $agent->promptAddToSection('S', 'more'));
        $this->assertSame($agent, $agent->addHint('hint'));
        $this->assertSame($agent, $agent->addHints(['h']));
        $this->assertSame($agent, $agent->addPatternHint('p', 'pat', 'rep'));
        $this->assertSame($agent, $agent->addLanguage('En', 'en', 'v'));
        $this->assertSame($agent, $agent->addPronunciation('a', 'b'));
        $this->assertSame($agent, $agent->setParam('k', 'v'));
        $this->assertSame($agent, $agent->setParams([]));
        $this->assertSame($agent, $agent->setGlobalData([]));
        $this->assertSame($agent, $agent->updateGlobalData([]));
        $this->assertSame($agent, $agent->setNativeFunctions([]));
        $this->assertSame($agent, $agent->setInternalFillers([]));
        $this->assertSame($agent, $agent->addInternalFiller('f'));
        $this->assertSame($agent, $agent->enableDebugEvents());
        $this->assertSame($agent, $agent->addFunctionInclude([]));
        $this->assertSame($agent, $agent->setFunctionIncludes([]));
        $this->assertSame($agent, $agent->setPromptLlmParams([]));
        $this->assertSame($agent, $agent->setPostPromptLlmParams([]));
        $this->assertSame($agent, $agent->addPreAnswerVerb('v', []));
        $this->assertSame($agent, $agent->addPostAnswerVerb('v', []));
        $this->assertSame($agent, $agent->addPostAiVerb('v', []));
        $this->assertSame($agent, $agent->clearPreAnswerVerbs());
        $this->assertSame($agent, $agent->clearPostAnswerVerbs());
        $this->assertSame($agent, $agent->clearPostAiVerbs());
        $this->assertSame($agent, $agent->setDynamicConfigCallback(fn () => null));
        $this->assertSame($agent, $agent->setSummaryCallback(fn () => null));
        $this->assertSame($agent, $agent->defineTool('t', 'd', [], fn () => null));
        $this->assertSame($agent, $agent->registerSwaigFunction(['function' => 'f']));
        $this->assertSame($agent, $agent->defineTools([]));
        $this->assertSame($agent, $agent->setWebHookUrl('http://x'));
        $this->assertSame($agent, $agent->setPostPromptUrl('http://x'));
        $this->assertSame($agent, $agent->manualSetProxyUrl('http://x'));
    }

    // ------------------------------------------------------------------
    // 30. SWAIG dispatch via handleRequest
    // ------------------------------------------------------------------

    public function testHandleSwaigRequest(): void
    {
        $agent = $this->makeAgent();
        $agent->defineTool(
            'echo_tool',
            'Echoes input',
            ['msg' => ['type' => 'string']],
            function (array $args, array $raw): FunctionResult {
                return new FunctionResult('Echo: ' . ($args['msg'] ?? ''));
            },
        );

        $swaigBody = json_encode([
            'function' => 'echo_tool',
            'argument' => [
                'parsed' => [
                    ['msg' => 'hello'],
                ],
            ],
        ]);
        $this->assertIsString($swaigBody);

        [$status, , $body] = $agent->handleRequest(
            'POST',
            '/swaig',
            $this->authHeader(),
            $swaigBody,
        );

        $this->assertSame(200, $status);
        $decoded = json_decode($body, true);
        $this->assertSame('Echo: hello', Shape::at($decoded, 'response'));
    }

    public function testHandleSwaigRequestUnknownFunction(): void
    {
        $agent = $this->makeAgent();

        $swaigBody = json_encode([
            'function' => 'no_such_func',
            'argument' => ['parsed' => [['x' => 1]]],
        ]);
        $this->assertIsString($swaigBody);

        [$status, , $body] = $agent->handleRequest(
            'POST',
            '/swaig',
            $this->authHeader(),
            $swaigBody,
        );

        $this->assertSame(404, $status);
        $decoded = json_decode($body, true);
        $this->assertArrayHasKey('error', Shape::arr($decoded));
    }

    public function testHandleSwaigRequestMissingFunctionName(): void
    {
        $agent = $this->makeAgent();

        $swaigBody = json_encode(['argument' => ['parsed' => [[]]]]);
        $this->assertIsString($swaigBody);

        [$status, ,] = $agent->handleRequest(
            'POST',
            '/swaig',
            $this->authHeader(),
            $swaigBody,
        );

        $this->assertSame(400, $status);
    }

    // ------------------------------------------------------------------
    // 31. Verb phases in render
    // ------------------------------------------------------------------

    public function testVerbPhasesOrder(): void
    {
        $agent = $this->makeAgent();
        $agent->addPreAnswerVerb('play', ['url' => 'ring.wav']);
        $agent->addPostAnswerVerb('record_call', ['format' => 'mp3']);
        $agent->addPostAiVerb('hangup', []);

        $swml = $agent->renderSwml();
        $verbNames = $this->mainVerbNames($swml);

        $playIdx = array_search('play', $verbNames, true);
        $answerIdx = array_search('answer', $verbNames, true);
        $recordIdx = array_search('record_call', $verbNames, true);
        $aiIdx = array_search('ai', $verbNames, true);
        $hangupIdx = array_search('hangup', $verbNames, true);

        // Pre-answer first, then answer, then post-answer, then ai, then post-ai
        $this->assertLessThan($answerIdx, $playIdx);
        $this->assertLessThan($aiIdx, $recordIdx);
        $this->assertGreaterThan($answerIdx, $recordIdx);
        $this->assertGreaterThan($aiIdx, $hangupIdx);
    }

    // ------------------------------------------------------------------
    // 32. Native functions in render
    // ------------------------------------------------------------------

    public function testNativeFunctionsInSwml(): void
    {
        $agent = $this->makeAgent();
        $agent->setNativeFunctions(['check_voicemail', 'send_digits']);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertArrayHasKey('SWAIG', $ai);
        $this->assertSame(['check_voicemail', 'send_digits'], Shape::at($ai, 'SWAIG', 'native_functions'));
    }

    // ------------------------------------------------------------------
    // 33. Global data in render
    // ------------------------------------------------------------------

    public function testGlobalDataInSwml(): void
    {
        $agent = $this->makeAgent();
        $agent->setGlobalData(['customer_id' => '123', 'plan' => 'premium']);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['customer_id' => '123', 'plan' => 'premium'], $ai['global_data']);
    }

    public function testGlobalDataAbsentWhenEmpty(): void
    {
        $agent = $this->makeAgent();
        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertArrayNotHasKey('global_data', $ai);
    }

    // ------------------------------------------------------------------
    // Additional edge-case coverage
    // ------------------------------------------------------------------

    public function testRecordCallTrue(): void
    {
        $agent = $this->makeAgent(['record_call' => true]);
        $swml = $agent->renderSwml();
        $verbNames = $this->mainVerbNames($swml);
        $this->assertContains('record_call', $verbNames);
    }

    public function testAutoAnswerFalse(): void
    {
        $agent = $this->makeAgent(['auto_answer' => false]);
        $swml = $agent->renderSwml();
        $verbNames = $this->mainVerbNames($swml);
        $this->assertNotContains('answer', $verbNames);
    }

    public function testPostPromptUrlInSwml(): void
    {
        $agent = $this->makeAgent();
        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertArrayHasKey('post_prompt_url', $ai);
        $postPromptUrl = Shape::at($ai, 'post_prompt_url');
        $this->assertIsString($postPromptUrl);
        $this->assertStringContainsString('/post_prompt', $postPromptUrl);
    }

    public function testManualProxyUrlUsedInWebhook(): void
    {
        $agent = $this->makeAgent();
        $agent->manualSetProxyUrl('https://my-proxy.example.com');
        $agent->defineTool(
            'tool1',
            'A tool',
            [],
            fn () => new FunctionResult('ok'),
        );

        $ai = $this->extractAiVerb($agent->renderSwml());
        $webhookUrl = Shape::at($ai, 'SWAIG', 'functions', 0, 'web_hook_url');
        $this->assertIsString($webhookUrl);
        $this->assertStringContainsString('my-proxy.example.com', $webhookUrl);
    }

    public function testPromptSectionWithBullets(): void
    {
        $agent = $this->makeAgent();
        $agent->promptAddSection('Rules', 'Follow these rules:', ['rule 1', 'rule 2']);

        $prompt = $agent->getPrompt();
        $this->assertSame(['rule 1', 'rule 2'], Shape::at($prompt, 0, 'bullets'));
    }

    public function testCloneForRequestIsIndependent(): void
    {
        $agent = $this->makeAgent();
        $agent->setPromptText('Original.');
        $agent->addHint('original_hint');
        $agent->setGlobalData(['key' => 'original']);

        $clone = $agent->cloneForRequest();
        $clone->setPromptText('Modified.');
        $clone->addHint('clone_hint');
        $clone->setGlobalData(['key' => 'modified']);

        // Original should be unaffected
        $this->assertSame('Original.', $agent->getPrompt());
        $origAi = $this->extractAiVerb($agent->renderSwml());
        $this->assertNotContains('clone_hint', Shape::sub($origAi, 'hints'));
    }

    // ------------------------------------------------------------------
    // getPom() — Python parity: agent.pom
    //
    // Mirrors signalwire-python tests/unit/core/test_agent_base.py::
    //   TestAgentBasePromptMethods::test_set_prompt_pom_succeeds_when_use_pom_true
    // ------------------------------------------------------------------

    public function testGetPomReturnsAssignedSections(): void
    {
        $agent = $this->makeAgent();
        $sections = [['title' => 'Greeting', 'body' => 'Hello']];
        $agent->setPromptPom($sections);

        $pom = $agent->getPom();
        $this->assertNotNull($pom);
        $this->assertCount(1, $pom->sections);
        $this->assertSame('Greeting', $pom->sections[0]->title);
        $this->assertSame('Hello', $pom->sections[0]->body);
    }

    public function testGetPomReturnsSectionsAfterPromptAddSection(): void
    {
        $agent = $this->makeAgent();
        $agent->promptAddSection('Topic', 'Body text');

        $pom = $agent->getPom();
        $this->assertNotNull($pom);
        $this->assertCount(1, $pom->sections);
        $this->assertSame('Topic', $pom->sections[0]->title);
        $this->assertSame('Body text', $pom->sections[0]->body);
    }

    public function testGetPomNullWhenUsePomFalse(): void
    {
        // Constructor option ``use_pom: false`` disables POM mode;
        // getPom() must return null (Python parity: self.pom is None).
        $agent = $this->makeAgent(['use_pom' => false]);
        $this->assertNull($agent->getPom());
    }

    public function testGetPomReturnsCopyNotReference(): void
    {
        // getPom() builds a fresh PromptObjectModel from the stored section
        // dicts each call — caller mutations must not leak into agent state.
        $agent = $this->makeAgent();
        $agent->setPromptPom([['title' => 'Original', 'body' => 'B']]);

        $pom = $agent->getPom();
        $this->assertNotNull($pom);
        // Mutate the returned tree.
        $pom->sections[0]->title = 'Hijacked';
        $pom->addSection('Injected', ['body' => 'B']);

        $fresh = $agent->getPom();
        $this->assertNotNull($fresh);
        $this->assertCount(1, $fresh->sections, 'caller mutation leaked into agent state');
        $this->assertSame('Original', $fresh->sections[0]->title, 'caller mutation leaked into agent state');
    }

    public function testGetPomFindsSectionsRecursively(): void
    {
        // The returned PromptObjectModel must support the full POM API,
        // including recursive findSection() through subsections.
        $agent = $this->makeAgent();
        $agent->promptAddSection('Outer', 'ob');
        $agent->promptAddSubsection('Outer', 'Inner', 'ib');

        $pom = $agent->getPom();
        $this->assertNotNull($pom);
        $found = $pom->findSection('Inner');
        $this->assertNotNull($found, 'getPom() must return a usable PromptObjectModel');
        $this->assertSame('ib', $found->body);
    }

    // ------------------------------------------------------------------
    // Behavior-parity bundle (#190 / #191 / #185 / #182)
    // ------------------------------------------------------------------

    // #190 — setGlobalData must MERGE (matching the TS reference, which calls
    // safeAssign), not replace. Skills and other callers each contribute keys,
    // so a replacing setGlobalData would silently clobber their contributions.
    public function testSetGlobalDataMerges(): void
    {
        $agent = $this->makeAgent();
        $agent->setGlobalData(['a' => 1]);
        $agent->setGlobalData(['b' => 2]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['a' => 1, 'b' => 2], Shape::at($ai, 'global_data'));
    }

    public function testSetGlobalDataOverwritesOnCollision(): void
    {
        $agent = $this->makeAgent();
        $agent->setGlobalData(['k' => 'first', 'keep' => 'x']);
        $agent->setGlobalData(['k' => 'second']);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['k' => 'second', 'keep' => 'x'], Shape::at($ai, 'global_data'));
    }

    // #191 — setFunctionIncludes must DROP entries that lack a truthy url or an
    // array functions field (matching the TS reference's filter), and warn for
    // each dropped entry so the typo is caught at registration time.
    public function testSetFunctionIncludesDropsInvalidEntries(): void
    {
        $agent = $this->makeAgent();
        $agent->setFunctionIncludes([
            ['url' => 'https://valid.com', 'functions' => ['a']],
            ['url' => 'https://no-functions.com'],          // missing functions -> drop
            ['functions' => ['b']],                          // missing url -> drop
            ['url' => 'https://bad-functions.com', 'functions' => 'notarray'], // functions not array -> drop
        ]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertCount(1, Shape::sub($ai, 'SWAIG', 'includes'));
        $this->assertSame('https://valid.com', Shape::at($ai, 'SWAIG', 'includes', 0, 'url'));
    }

    public function testSetFunctionIncludesKeepsAllValidEntries(): void
    {
        $agent = $this->makeAgent();
        $agent->setFunctionIncludes([
            ['url' => 'https://a.com', 'functions' => ['a']],
            ['url' => 'https://b.com', 'functions' => []],
        ]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertCount(2, Shape::sub($ai, 'SWAIG', 'includes'));
    }

    public function testSetFunctionIncludesWarnsPerDroppedEntry(): void
    {
        // The Logger writes to stderr, which cannot be intercepted from inside
        // the PHPUnit process — spawn a child PHP process to inspect what
        // setFunctionIncludes() logged (same pattern as LoggerTest).
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $script = <<<PHP
            <?php
            require '{$autoload}';
            \$agent = new \\SignalWire\\Agent\\AgentBase(
                name: 'child',
                basicAuthUser: 'u',
                basicAuthPassword: 'p',
            );
            \$agent->setFunctionIncludes([
                ['url' => 'https://valid.com', 'functions' => ['a']],
                ['url' => 'https://no-functions.com'],
                ['functions' => ['b']],
            ]);
            PHP;
        $tmp = \tempnam(\sys_get_temp_dir(), 'sw_fi_test_') . '.php';
        \file_put_contents($tmp, $script);
        try {
            $cmd = \escapeshellcmd(PHP_BINARY) . ' ' . \escapeshellarg($tmp);
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = \proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
            $this->assertIsResource($proc);
            \fclose($pipes[0]);
            $stdout = \stream_get_contents($pipes[1]);
            $stderr = \stream_get_contents($pipes[2]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            \proc_close($proc);

            // Two invalid entries -> two WARN lines, naming the dropped url.
            $this->assertSame(2, \substr_count($stderr, 'invalid_function_include'), 'stderr was: ' . $stderr);
            $this->assertStringContainsString('https://no-functions.com', $stderr);
            $this->assertSame('', $stdout, 'Logger leaked into stdout');
        } finally {
            @\unlink($tmp);
        }
    }

    // #185 — when contexts are active and the prompt text is empty, the AI verb
    // must fall back to the default prompt (matching the TS reference's exact
    // string), never an empty text.
    public function testEmptyPromptWithContextsFallsBack(): void
    {
        $agent = $this->makeAgent(['use_pom' => false, 'name' => 'Aria']);
        $agent->defineContexts()
            ->addContext('default')
            ->addStep('start')
            ->setText('Begin.');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame('You are Aria, a helpful AI assistant.', Shape::at($ai, 'prompt', 'text'));
    }

    public function testEmptyPromptWithoutContextsStaysEmpty(): void
    {
        // Without contexts the TS reference emits the prompt text as-is (no
        // fallback), so an unset prompt remains an empty string.
        $agent = $this->makeAgent(['use_pom' => false]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame('', Shape::at($ai, 'prompt', 'text'));
    }

    public function testNonEmptyPromptWithContextsIsUnchanged(): void
    {
        $agent = $this->makeAgent(['use_pom' => false, 'name' => 'Aria']);
        $agent->setPromptText('Custom prompt.');
        $agent->defineContexts()
            ->addContext('default')
            ->addStep('start')
            ->setText('Begin.');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame('Custom prompt.', Shape::at($ai, 'prompt', 'text'));
    }

    // PHP-8 wire-shape fixture — the contexts DEFINITION is emitted at
    // ``ai.prompt.contexts`` (a sibling of text/pom INSIDE the prompt object),
    // NOT at a top-level ``ai.context_switch``. This matches the SWML schema
    // (swml_handler validates ``prompt.contexts``) and the python reference
    // (swml_handler.build_config sets ``prompt_config['contexts']``).
    // ``context_switch`` is a distinct RUNTIME SWAIG action, not the static
    // definition's home.
    public function testContextsDefinitionEmittedUnderPromptNotContextSwitch(): void
    {
        $agent = $this->makeAgent(['use_pom' => false, 'name' => 'Aria']);
        $agent->defineContexts()
            ->addContext('default')
            ->addStep('start')
            ->setText('Begin.');

        $ai = $this->extractAiVerb($agent->renderSwml());

        // The contexts object is a sibling of the prompt text.
        $this->assertArrayHasKey('contexts', Shape::sub($ai, 'prompt'));
        $contexts = Shape::at($ai, 'prompt', 'contexts');
        $this->assertIsArray($contexts);
        $this->assertArrayHasKey('default', $contexts);

        // The legacy/wrong top-level ``ai.context_switch`` key is NOT emitted for
        // the static contexts definition.
        $this->assertArrayNotHasKey('context_switch', $ai);
    }

    // #182 — promptAddToSection / promptAddSubsection must AUTO-CREATE the
    // (parent) section when it is missing, matching the TS reference, instead
    // of silently no-op'ing.
    public function testPromptAddToSectionAutoCreatesMissingSection(): void
    {
        $agent = $this->makeAgent();
        $agent->promptAddToSection('Rules', 'Body text.', ['bullet one']);

        $prompt = $agent->getPrompt();
        $this->assertIsArray($prompt);
        $this->assertCount(1, $prompt);
        $this->assertSame('Rules', Shape::at($prompt, 0, 'title'));
        $this->assertSame('Body text.', Shape::at($prompt, 0, 'body'));
        $this->assertSame(['bullet one'], Shape::at($prompt, 0, 'bullets'));
    }

    public function testPromptAddSubsectionAutoCreatesMissingParent(): void
    {
        $agent = $this->makeAgent();
        $agent->promptAddSubsection('Main', 'Detail', 'Detail body.');

        $prompt = $agent->getPrompt();
        $this->assertIsArray($prompt);
        $this->assertCount(1, $prompt);
        $this->assertSame('Main', Shape::at($prompt, 0, 'title'));
        $this->assertArrayHasKey('subsections', Shape::sub($prompt, 0));
        $this->assertCount(1, Shape::sub($prompt, 0, 'subsections'));
        $this->assertSame('Detail', Shape::at($prompt, 0, 'subsections', 0, 'title'));
        $this->assertSame('Detail body.', Shape::at($prompt, 0, 'subsections', 0, 'body'));
    }

    // ------------------------------------------------------------------
    // Internal test helpers
    // ------------------------------------------------------------------

    /**
     * Extract the AI verb config from a rendered SWML document array.
     */
    // ------------------------------------------------------------------
    // MCP servers + web/serverless mixin methods (item I cluster)
    // ------------------------------------------------------------------

    public function testAddMcpServerEmitsIntoSwaig(): void
    {
        $agent = $this->makeAgent();
        $result = $agent
            ->addMcpServer('https://mcp.example.com', ['Authorization' => 'Bearer sk-x'], true, ['caller_id' => '${caller_id_number}'])
            ->defineTool('noop', 'noop', [], fn () => new FunctionResult('ok'));
        $this->assertSame($agent, $result);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertArrayHasKey('mcp_servers', Shape::sub($ai, 'SWAIG'));
        $server = Shape::sub($ai, 'SWAIG', 'mcp_servers', 0);
        $this->assertSame('https://mcp.example.com', $server['url'] ?? null);
        $this->assertSame(['Authorization' => 'Bearer sk-x'], $server['headers'] ?? null);
        $this->assertTrue($server['resources'] ?? null);
        $this->assertSame(['caller_id' => '${caller_id_number}'], $server['resource_vars'] ?? null);
    }

    public function testGetMcpServersAndEnableFlag(): void
    {
        $agent = $this->makeAgent();
        $this->assertSame([], $agent->getMcpServers());
        $this->assertFalse($agent->isMcpServerEnabled());

        $agent->addMcpServer('https://a.example.com');
        $this->assertCount(1, $agent->getMcpServers());

        $this->assertSame($agent, $agent->enableMcpServer());
        $this->assertTrue($agent->isMcpServerEnabled());
    }

    public function testEnableDebugRoutesReturnsSelf(): void
    {
        $agent = $this->makeAgent();
        $this->assertSame($agent, $agent->enableDebugRoutes());
    }

    public function testSetupGracefulShutdownReturnsVoidWithoutThrowing(): void
    {
        $agent = $this->makeAgent();
        // No-op when ext-pcntl is absent; must complete without throwing (void).
        $agent->setupGracefulShutdown();
        // Agent remains usable afterwards (SWML still renders).
        $swml = $agent->renderSwml();
        $this->assertArrayHasKey('sections', $swml);
    }

    public function testMcpServersPreservedThroughCloneForRequest(): void
    {
        $agent = $this->makeAgent();
        $agent->addMcpServer('https://mcp.example.com')->enableMcpServer();

        $clone = $agent->cloneForRequest();
        $this->assertCount(1, $clone->getMcpServers());
        $this->assertTrue($clone->isMcpServerEnabled());

        // Mutating the clone must not affect the original (deep copy).
        $clone->addMcpServer('https://other.example.com');
        $this->assertCount(1, $agent->getMcpServers());
        $this->assertCount(2, $clone->getMcpServers());
    }

    /**
     * @param array<string,mixed> $swml
     * @return array<string,mixed>
     */
    private function extractAiVerb(array $swml): array
    {
        foreach (Shape::sub($swml, 'sections', 'main') as $verb) {
            if (is_array($verb) && isset($verb['ai'])) {
                $ai = $verb['ai'];
                $this->assertIsArray($ai);
                /** @var array<string,mixed> $ai */
                return $ai;
            }
        }
        $this->fail('AI verb not found in rendered SWML');
    }
}
