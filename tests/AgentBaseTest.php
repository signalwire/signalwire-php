<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\Logging\Logger;
use SignalWire\SWML\Schema;
use SignalWire\Contexts\ContextBuilder;

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

    private function authHeader(string $user = 'testuser', string $pass = 'testpass'): array
    {
        return ['Authorization' => 'Basic ' . base64_encode("{$user}:{$pass}")];
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

        $main = $swml['sections']['main'];
        $verbs = array_map(fn(array $v) => array_key_first($v), $main);
        $this->assertContains('answer', $verbs);
    }

    public function testConstructionRecordCallDefaultFalse(): void
    {
        $agent = $this->makeAgent();
        $swml = $agent->renderSwml();

        $main = $swml['sections']['main'];
        $verbs = array_map(fn(array $v) => array_key_first($v), $main);
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
        $this->assertArrayHasKey('subsections', $prompt[0]);
        $this->assertCount(1, $prompt[0]['subsections']);
        $this->assertSame('Detail', $prompt[0]['subsections'][0]['title']);
        $this->assertSame('Detail body text.', $prompt[0]['subsections'][0]['body']);
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
        $this->assertSame('Base rules. Extra rules.', $prompt[0]['body']);
        $this->assertSame(['bullet one', 'bullet two'], $prompt[0]['bullets']);
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
            fn(array $args, array $raw) => new FunctionResult('found'),
        );

        $swml = $agent->renderSwml();
        $ai = $this->extractAiVerb($swml);
        $functions = $ai['SWAIG']['functions'];

        $this->assertCount(1, $functions);
        $this->assertSame('lookup', $functions[0]['function']);
        $this->assertSame('Look up a customer', $functions[0]['purpose']);
        // Handler must be stripped from the output
        $this->assertArrayNotHasKey('_handler', $functions[0]);
        $this->assertArrayNotHasKey('_secure', $functions[0]);
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
        $functions = $ai['SWAIG']['functions'];

        $this->assertCount(1, $functions);
        $this->assertSame('data_map_tool', $functions[0]['function']);
        $this->assertArrayHasKey('data_map', $functions[0]);
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
        $functions = $ai['SWAIG']['functions'];

        $this->assertCount(2, $functions);
        $this->assertSame('tool_a', $functions[0]['function']);
        $this->assertSame('tool_b', $functions[1]['function']);
    }

    // ------------------------------------------------------------------
    // 12. AI config methods
    // ------------------------------------------------------------------

    public function testAddHint(): void
    {
        $agent = $this->makeAgent();
        $agent->addHint('SignalWire');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertContains('SignalWire', $ai['hints']);
    }

    public function testAddHints(): void
    {
        $agent = $this->makeAgent();
        $agent->addHints(['hello', 'world']);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['hello', 'world'], $ai['hints']);
    }

    public function testAddPatternHint(): void
    {
        $agent = $this->makeAgent();
        $agent->addPatternHint('\\d{3}-\\d{4}');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertContains('\\d{3}-\\d{4}', $ai['hints']);
    }

    public function testAddLanguage(): void
    {
        $agent = $this->makeAgent();
        $agent->addLanguage('English', 'en-US', 'rachel');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertCount(1, $ai['languages']);
        $this->assertSame('English', $ai['languages'][0]['name']);
        $this->assertSame('en-US', $ai['languages'][0]['code']);
        $this->assertSame('rachel', $ai['languages'][0]['voice']);
    }

    public function testAddPronunciation(): void
    {
        $agent = $this->makeAgent();
        $agent->addPronunciation('SW', 'SignalWire');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertCount(1, $ai['pronounce']);
        $this->assertSame('SW', $ai['pronounce'][0]['replace']);
        $this->assertSame('SignalWire', $ai['pronounce'][0]['with']);
    }

    public function testSetParam(): void
    {
        $agent = $this->makeAgent();
        $agent->setParam('temperature', 0.7);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(0.7, $ai['params']['temperature']);
    }

    public function testSetParams(): void
    {
        $agent = $this->makeAgent();
        $agent->setParams(['temperature' => 0.5, 'top_p' => 0.9]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(0.5, $ai['params']['temperature']);
        $this->assertSame(0.9, $ai['params']['top_p']);
    }

    public function testSetGlobalData(): void
    {
        $agent = $this->makeAgent();
        $agent->setGlobalData(['key' => 'value']);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['key' => 'value'], $ai['global_data']);
    }

    public function testUpdateGlobalData(): void
    {
        $agent = $this->makeAgent();
        $agent->setGlobalData(['a' => 1]);
        $agent->updateGlobalData(['b' => 2]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['a' => 1, 'b' => 2], $ai['global_data']);
    }

    public function testSetNativeFunctions(): void
    {
        $agent = $this->makeAgent();
        $agent->setNativeFunctions(['check_voicemail', 'send_digits']);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['check_voicemail', 'send_digits'], $ai['SWAIG']['native_functions']);
    }

    // ------------------------------------------------------------------
    // 13. Internal fillers
    // ------------------------------------------------------------------

    public function testSetInternalFillers(): void
    {
        $agent = $this->makeAgent();
        $agent->setInternalFillers(['hmm', 'let me think']);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['hmm', 'let me think'], $ai['params']['internal_fillers']);
    }

    public function testAddInternalFiller(): void
    {
        $agent = $this->makeAgent();
        $agent->addInternalFiller('hmm');
        $agent->addInternalFiller('uh');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(['hmm', 'uh'], $ai['params']['internal_fillers']);
    }

    // ------------------------------------------------------------------
    // 14. Debug events
    // ------------------------------------------------------------------

    public function testEnableDebugEvents(): void
    {
        $agent = $this->makeAgent();
        $agent->enableDebugEvents();

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame('all', $ai['params']['debug_events']);
    }

    public function testEnableDebugEventsCustomLevel(): void
    {
        $agent = $this->makeAgent();
        $agent->enableDebugEvents('verbose');

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame('verbose', $ai['params']['debug_events']);
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
        $this->assertCount(1, $ai['SWAIG']['includes']);
        $this->assertSame('https://example.com/funcs', $ai['SWAIG']['includes'][0]['url']);
    }

    public function testSetFunctionIncludes(): void
    {
        $agent = $this->makeAgent();
        $agent->addFunctionInclude(['url' => 'https://first.com']);
        $agent->setFunctionIncludes([
            ['url' => 'https://replaced.com'],
        ]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertCount(1, $ai['SWAIG']['includes']);
        $this->assertSame('https://replaced.com', $ai['SWAIG']['includes'][0]['url']);
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
        $this->assertSame(0.3, $ai['prompt']['temperature']);
        $this->assertSame(0.8, $ai['prompt']['top_p']);
    }

    public function testSetPostPromptLlmParams(): void
    {
        $agent = $this->makeAgent();
        $agent->setPostPrompt('Summarize the call.');
        $agent->setPostPromptLlmParams(['temperature' => 0.1]);

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertSame(0.1, $ai['post_prompt']['temperature']);
    }

    // ------------------------------------------------------------------
    // 17. Verb management
    // ------------------------------------------------------------------

    public function testAddPreAnswerVerb(): void
    {
        $agent = $this->makeAgent();
        $agent->addPreAnswerVerb('play', ['url' => 'ring.wav']);

        $swml = $agent->renderSwml();
        $main = $swml['sections']['main'];

        // Pre-answer verb should be the first verb
        $this->assertSame('play', array_key_first($main[0]));
        $this->assertSame(['url' => 'ring.wav'], $main[0]['play']);
    }

    public function testAddPostAnswerVerb(): void
    {
        $agent = $this->makeAgent();
        $agent->addPostAnswerVerb('play', ['url' => 'welcome.wav']);

        $swml = $agent->renderSwml();
        $main = $swml['sections']['main'];

        // Post-answer verb should be after 'answer', before 'ai'
        $verbNames = array_map(fn(array $v) => array_key_first($v), $main);
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
        $main = $swml['sections']['main'];

        $verbNames = array_map(fn(array $v) => array_key_first($v), $main);
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
        $main = $swml['sections']['main'];
        $verbNames = array_map(fn(array $v) => array_key_first($v), $main);
        $this->assertNotContains('play', $verbNames);
    }

    public function testClearPostAnswerVerbs(): void
    {
        $agent = $this->makeAgent();
        $agent->addPostAnswerVerb('play', ['url' => 'welcome.wav']);
        $agent->clearPostAnswerVerbs();

        $swml = $agent->renderSwml();
        $main = $swml['sections']['main'];
        $verbNames = array_map(fn(array $v) => array_key_first($v), $main);
        $this->assertNotContains('play', $verbNames);
    }

    public function testClearPostAiVerbs(): void
    {
        $agent = $this->makeAgent();
        $agent->addPostAiVerb('hangup', []);
        $agent->clearPostAiVerbs();

        $swml = $agent->renderSwml();
        $main = $swml['sections']['main'];
        $verbNames = array_map(fn(array $v) => array_key_first($v), $main);
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
        $this->assertArrayHasKey('main', $swml['sections']);

        $main = $swml['sections']['main'];
        $verbNames = array_map(fn(array $v) => array_key_first($v), $main);
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
        $this->assertSame('You are a receptionist.', $ai['prompt']['text']);
        $this->assertArrayNotHasKey('pom', $ai['prompt']);
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
        $this->assertArrayHasKey('pom', $ai['prompt']);
        $this->assertCount(2, $ai['prompt']['pom']);
        $this->assertSame('Identity', $ai['prompt']['pom'][0]['title']);
        $this->assertSame('Rules', $ai['prompt']['pom'][1]['title']);
        $this->assertSame(['no cursing', 'be patient'], $ai['prompt']['pom'][1]['bullets']);
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
        $this->assertSame('Summarize the conversation.', $ai['post_prompt']['text']);
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
            fn(array $args, array $raw) => new FunctionResult('Sunny'),
        );

        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertArrayHasKey('SWAIG', $ai);
        $this->assertArrayHasKey('functions', $ai['SWAIG']);
        $this->assertCount(1, $ai['SWAIG']['functions']);

        $func = $ai['SWAIG']['functions'][0];
        $this->assertSame('get_weather', $func['function']);
        $this->assertSame('Get the weather', $func['purpose']);
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
        $this->assertSame(['SignalWire', 'FreeSWITCH'], $ai['hints']);
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
        $this->assertCount(2, $ai['languages']);
        $this->assertSame('English', $ai['languages'][0]['name']);
        $this->assertSame('Spanish', $ai['languages'][1]['name']);
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
        $aiVerb = $this->extractAiVerb($decoded);
        $this->assertSame('Dynamic prompt.', $aiVerb['prompt']['text']);
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
        $agent->onSummary(function (string $summary, array $data, array $headers) use (&$receivedSummary): void {
            $receivedSummary = $summary;
        });

        $postPromptBody = json_encode([
            'post_prompt_data' => ['raw' => 'The call was about billing.'],
        ]);

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

        $this->assertInstanceOf(ContextBuilder::class, $builder);
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
        $this->assertSame($agent, $agent->addPatternHint('p'));
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
        $this->assertSame($agent, $agent->setDynamicConfigCallback(fn() => null));
        $this->assertSame($agent, $agent->onSummary(fn() => null));
        $this->assertSame($agent, $agent->defineTool('t', 'd', [], fn() => null));
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

        [$status, , $body] = $agent->handleRequest(
            'POST',
            '/swaig',
            $this->authHeader(),
            $swaigBody,
        );

        $this->assertSame(200, $status);
        $decoded = json_decode($body, true);
        $this->assertSame('Echo: hello', $decoded['response']);
    }

    public function testHandleSwaigRequestUnknownFunction(): void
    {
        $agent = $this->makeAgent();

        $swaigBody = json_encode([
            'function' => 'no_such_func',
            'argument' => ['parsed' => [['x' => 1]]],
        ]);

        [$status, , $body] = $agent->handleRequest(
            'POST',
            '/swaig',
            $this->authHeader(),
            $swaigBody,
        );

        $this->assertSame(404, $status);
        $decoded = json_decode($body, true);
        $this->assertArrayHasKey('error', $decoded);
    }

    public function testHandleSwaigRequestMissingFunctionName(): void
    {
        $agent = $this->makeAgent();

        $swaigBody = json_encode(['argument' => ['parsed' => [[]]]]);

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
        $main = $swml['sections']['main'];
        $verbNames = array_map(fn(array $v) => array_key_first($v), $main);

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
        $this->assertSame(['check_voicemail', 'send_digits'], $ai['SWAIG']['native_functions']);
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
        $main = $swml['sections']['main'];
        $verbNames = array_map(fn(array $v) => array_key_first($v), $main);
        $this->assertContains('record_call', $verbNames);
    }

    public function testAutoAnswerFalse(): void
    {
        $agent = $this->makeAgent(['auto_answer' => false]);
        $swml = $agent->renderSwml();
        $main = $swml['sections']['main'];
        $verbNames = array_map(fn(array $v) => array_key_first($v), $main);
        $this->assertNotContains('answer', $verbNames);
    }

    public function testPostPromptUrlInSwml(): void
    {
        $agent = $this->makeAgent();
        $ai = $this->extractAiVerb($agent->renderSwml());
        $this->assertArrayHasKey('post_prompt_url', $ai);
        $this->assertStringContainsString('/post_prompt', $ai['post_prompt_url']);
    }

    public function testManualProxyUrlUsedInWebhook(): void
    {
        $agent = $this->makeAgent();
        $agent->manualSetProxyUrl('https://my-proxy.example.com');
        $agent->defineTool(
            'tool1',
            'A tool',
            [],
            fn() => new FunctionResult('ok'),
        );

        $ai = $this->extractAiVerb($agent->renderSwml());
        $webhookUrl = $ai['SWAIG']['functions'][0]['web_hook_url'];
        $this->assertStringContainsString('my-proxy.example.com', $webhookUrl);
    }

    public function testPromptSectionWithBullets(): void
    {
        $agent = $this->makeAgent();
        $agent->promptAddSection('Rules', 'Follow these rules:', ['rule 1', 'rule 2']);

        $prompt = $agent->getPrompt();
        $this->assertSame(['rule 1', 'rule 2'], $prompt[0]['bullets']);
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
        $this->assertNotContains('clone_hint', $origAi['hints'] ?? []);
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
        $this->assertInstanceOf(\SignalWire\POM\PromptObjectModel::class, $pom);
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
        $this->assertInstanceOf(\SignalWire\POM\PromptObjectModel::class, $pom);
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
    // Internal test helpers
    // ------------------------------------------------------------------

    /**
     * Extract the AI verb config from a rendered SWML document array.
     */
    private function extractAiVerb(array $swml): array
    {
        $main = $swml['sections']['main'];
        foreach ($main as $verb) {
            if (isset($verb['ai'])) {
                return $verb['ai'];
            }
        }
        $this->fail('AI verb not found in rendered SWML');
    }
}
