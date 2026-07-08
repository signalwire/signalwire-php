<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Logging\Logger;
use SignalWire\SWML\AIVerbHandler;
use SignalWire\SWML\Schema;
use SignalWire\SWML\Service;
use SignalWire\SWML\SWMLBuilder;
use SignalWire\SWML\SWMLVerbHandler;
use SignalWire\SWML\VerbHandlerRegistry;
use SignalWire\Tests\Support\Shape;

/**
 * Tests for the SWML core subsystem: SWMLBuilder, the verb-handler registry
 * (SWMLVerbHandler / AIVerbHandler / VerbHandlerRegistry) and the SWMLService
 * document-mutation methods. Mirrors the TypeScript SWML tests — real
 * assertions on the produced SWML structure, not construction-only.
 */
class SWMLCoreTest extends TestCase
{
    protected function setUp(): void
    {
        Schema::reset();
        Logger::reset();
        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
        putenv('SWML_PROXY_URL_BASE');
    }

    protected function tearDown(): void
    {
        Schema::reset();
        Logger::reset();
        putenv('SWML_BASIC_AUTH_USER');
        putenv('SWML_BASIC_AUTH_PASSWORD');
        putenv('SWML_PROXY_URL_BASE');
    }

    private function makeService(): Service
    {
        return new Service(
            name: 'swml-core-test',
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
        );
    }

    // ------------------------------------------------------------------
    // SWMLBuilder
    // ------------------------------------------------------------------

    public function testBuilderBuildsBasicDocument(): void
    {
        $builder = new SWMLBuilder($this->makeService());
        $doc = $builder
            ->answer()
            ->play(url: 'https://cdn.example.com/greeting.mp3')
            ->hangup()
            ->build();

        $this->assertSame('1.0.0', $doc['version']);
        $this->assertArrayHasKey('main', Shape::sub($doc, 'sections'));

        $main = Shape::sub($doc, 'sections', 'main');
        $this->assertCount(3, $main);
        $this->assertSame(['answer' => []], $main[0]);
        $this->assertSame(['play' => ['url' => 'https://cdn.example.com/greeting.mp3']], $main[1]);
        $this->assertSame(['hangup' => []], $main[2]);
    }

    public function testBuilderFluentChainingReturnsSelf(): void
    {
        $builder = new SWMLBuilder($this->makeService());
        $this->assertSame($builder, $builder->answer());
        $this->assertSame($builder, $builder->hangup());
    }

    public function testBuilderAnswerWithParams(): void
    {
        $builder = new SWMLBuilder($this->makeService());
        $doc = $builder->answer(maxDuration: 3600, codecs: 'PCMU,PCMA')->build();
        $this->assertSame(
            ['answer' => ['max_duration' => 3600, 'codecs' => 'PCMU,PCMA']],
            Shape::at($doc, 'sections', 'main', 0)
        );
    }

    public function testBuilderSayEmitsPlayWithSayPrefix(): void
    {
        $builder = new SWMLBuilder($this->makeService());
        $doc = $builder->say('Hello there', voice: 'en-US-Neural', volume: 5.0)->build();
        $this->assertSame(
            ['play' => [
                'url' => 'say:Hello there',
                'volume' => 5.0,
                'say_voice' => 'en-US-Neural',
            ]],
            Shape::at($doc, 'sections', 'main', 0)
        );
    }

    public function testBuilderAiWrapsPromptText(): void
    {
        $builder = new SWMLBuilder($this->makeService());
        $doc = $builder->ai(promptText: 'You are helpful')->build();
        $this->assertSame(
            ['ai' => ['prompt' => ['text' => 'You are helpful']]],
            Shape::at($doc, 'sections', 'main', 0)
        );
    }

    public function testBuilderAiWithPomPostPromptAndSwaig(): void
    {
        $builder = new SWMLBuilder($this->makeService());
        $doc = $builder->ai(
            promptPom: [['title' => 'Role', 'body' => 'assistant']],
            postPrompt: 'Summarize',
            postPromptUrl: 'https://x.example.com/pp',
            swaig: ['functions' => []],
        )->build();

        $this->assertSame(
            ['ai' => [
                'prompt' => ['pom' => [['title' => 'Role', 'body' => 'assistant']]],
                'post_prompt' => ['text' => 'Summarize'],
                'post_prompt_url' => 'https://x.example.com/pp',
                'SWAIG' => ['functions' => []],
            ]],
            Shape::at($doc, 'sections', 'main', 0)
        );
    }

    public function testBuilderPlayRequiresUrlOrUrls(): void
    {
        $builder = new SWMLBuilder($this->makeService());
        $this->expectException(\InvalidArgumentException::class);
        $builder->play();
    }

    public function testBuilderAddSectionCreatesSection(): void
    {
        $builder = new SWMLBuilder($this->makeService());
        $doc = $builder->addSection('intro')->build();
        $this->assertArrayHasKey('intro', Shape::sub($doc, 'sections'));
        $this->assertSame([], Shape::at($doc, 'sections', 'intro'));
    }

    public function testBuilderResetClearsDocument(): void
    {
        $builder = new SWMLBuilder($this->makeService());
        $builder->answer()->hangup();
        $doc = $builder->reset()->build();
        $this->assertSame(['main' => []], $doc['sections']);
    }

    public function testBuilderRenderProducesJson(): void
    {
        $builder = new SWMLBuilder($this->makeService());
        $json = $builder->answer()->render();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);
        $this->assertSame('1.0.0', $decoded['version']);
        $this->assertSame([['answer' => []]], Shape::at($decoded, 'sections', 'main'));
    }

    public function testBuilderAutoVivifiesSchemaVerb(): void
    {
        $builder = new SWMLBuilder($this->makeService());
        // `denoise` has no explicit method — dispatched via __call.
        // @phpstan-ignore method.notFound (auto-vivified SWML verb via __call)
        $doc = $builder->denoise()->build();
        $this->assertSame(['denoise' => []], Shape::at($doc, 'sections', 'main', 0));
    }

    public function testBuilderAutoVivifiedSleepTakesInteger(): void
    {
        $builder = new SWMLBuilder($this->makeService());
        // @phpstan-ignore method.notFound (auto-vivified SWML verb via __call)
        $doc = $builder->sleep(2000)->build();
        $this->assertSame(['sleep' => 2000], Shape::at($doc, 'sections', 'main', 0));
    }

    public function testBuilderUnknownVerbThrows(): void
    {
        $builder = new SWMLBuilder($this->makeService());
        $this->expectException(\BadMethodCallException::class);
        // @phpstan-ignore method.notFound (auto-vivified SWML verb via __call; intentionally unknown)
        $builder->definitelyNotAVerb();
    }

    // ------------------------------------------------------------------
    // VerbHandlerRegistry
    // ------------------------------------------------------------------

    public function testRegistryRegistersAiHandlerByDefault(): void
    {
        $registry = new VerbHandlerRegistry();
        $this->assertTrue($registry->hasHandler('ai'));
        $handler = $registry->getHandler('ai');
        $this->assertInstanceOf(AIVerbHandler::class, $handler);
        $this->assertSame('ai', $handler->getVerbName());
    }

    public function testRegistryMissingHandlerReturnsNull(): void
    {
        $registry = new VerbHandlerRegistry();
        $this->assertFalse($registry->hasHandler('play'));
        $this->assertNull($registry->getHandler('play'));
    }

    public function testRegistryRegisterAndGetRoundTrip(): void
    {
        $registry = new VerbHandlerRegistry();
        $handler = new class () extends SWMLVerbHandler {
            public function getVerbName(): string
            {
                return 'my_verb';
            }

            /** @param array<string, mixed> $config @return array{0: bool, 1: list<string>} */
            public function validateConfig(array $config): array
            {
                $errors = [];
                if (!array_key_exists('url', $config)) {
                    $errors[] = "Missing required field 'url'";
                }
                return [count($errors) === 0, $errors];
            }

            /** @param array<string, mixed> $kwargs @return array<string, mixed> */
            public function buildConfig(array $kwargs = []): array
            {
                return ['url' => $kwargs['url'] ?? null];
            }
        };

        $registry->registerHandler($handler);
        $this->assertTrue($registry->hasHandler('my_verb'));
        $this->assertSame($handler, $registry->getHandler('my_verb'));
    }

    public function testRegistryRegisterReplacesExisting(): void
    {
        $registry = new VerbHandlerRegistry();
        $replacement = new AIVerbHandler();
        $registry->registerHandler($replacement);
        $this->assertSame($replacement, $registry->getHandler('ai'));
    }

    // ------------------------------------------------------------------
    // AIVerbHandler
    // ------------------------------------------------------------------

    public function testAiHandlerBuildConfigWithText(): void
    {
        $handler = new AIVerbHandler();
        $config = $handler->buildConfig(['prompt_text' => 'Be nice']);
        $this->assertSame(['text' => 'Be nice'], $config['prompt']);
        $this->assertSame([], $config['params']);
    }

    public function testAiHandlerBuildConfigRoutesExtras(): void
    {
        $handler = new AIVerbHandler();
        $config = $handler->buildConfig([
            'prompt_text' => 'Hi',
            'languages' => [['name' => 'English']],
            'temperature' => 0.7,
        ]);

        // Top-level routed key.
        $this->assertSame([['name' => 'English']], $config['languages']);
        // Unknown key goes into params.
        $this->assertSame(['temperature' => 0.7], $config['params']);
    }

    public function testAiHandlerBuildConfigWithPomContextsPostPrompt(): void
    {
        $handler = new AIVerbHandler();
        $config = $handler->buildConfig([
            'prompt_pom' => [['title' => 'A']],
            'contexts' => ['default' => []],
            'post_prompt' => 'wrap up',
            'swaig' => ['functions' => []],
        ]);

        $this->assertSame(
            ['pom' => [['title' => 'A']], 'contexts' => ['default' => []]],
            $config['prompt']
        );
        $this->assertSame(['text' => 'wrap up'], $config['post_prompt']);
        $this->assertSame(['functions' => []], $config['SWAIG']);
    }

    public function testAiHandlerBuildConfigRequiresBasePrompt(): void
    {
        $handler = new AIVerbHandler();
        $this->expectException(\InvalidArgumentException::class);
        $handler->buildConfig([]);
    }

    public function testAiHandlerBuildConfigRejectsBothTextAndPom(): void
    {
        $handler = new AIVerbHandler();
        $this->expectException(\InvalidArgumentException::class);
        $handler->buildConfig(['prompt_text' => 'x', 'prompt_pom' => [['title' => 'y']]]);
    }

    public function testAiHandlerValidateConfigValid(): void
    {
        $handler = new AIVerbHandler();
        [$valid, $errors] = $handler->validateConfig(['prompt' => ['text' => 'hi']]);
        $this->assertTrue($valid);
        $this->assertSame([], $errors);
    }

    public function testAiHandlerValidateConfigMissingPrompt(): void
    {
        $handler = new AIVerbHandler();
        [$valid, $errors] = $handler->validateConfig([]);
        $this->assertFalse($valid);
        $this->assertContains("Missing required field 'prompt'", $errors);
    }

    public function testAiHandlerValidateConfigMutuallyExclusive(): void
    {
        $handler = new AIVerbHandler();
        [$valid, $errors] = $handler->validateConfig([
            'prompt' => ['text' => 'a', 'pom' => [['title' => 'b']]],
        ]);
        $this->assertFalse($valid);
        $this->assertContains(
            "'prompt' can only contain one of: 'text' or 'pom' (mutually exclusive)",
            $errors
        );
    }

    public function testAiHandlerValidateConfigRejectsNonObjectSwaig(): void
    {
        $handler = new AIVerbHandler();
        [$valid, $errors] = $handler->validateConfig([
            'prompt' => ['text' => 'a'],
            'SWAIG' => 'not-an-object',
        ]);
        $this->assertFalse($valid);
        $this->assertContains("'SWAIG' must be an object", $errors);
    }

    // ------------------------------------------------------------------
    // SWMLService document mutation
    // ------------------------------------------------------------------

    public function testServiceAddVerbMutatesMainSection(): void
    {
        $svc = $this->makeService();
        $this->assertTrue($svc->addVerb('answer', []));
        $doc = $svc->getDocument()->toArray();
        $this->assertSame([['answer' => []]], $doc['sections']['main']);
    }

    public function testServiceAddSection(): void
    {
        $svc = $this->makeService();
        $this->assertTrue($svc->addSection('menu'));
        // Second add is a no-op returning false.
        $this->assertFalse($svc->addSection('menu'));
        $this->assertTrue($svc->getDocument()->hasSection('menu'));
    }

    public function testServiceAddVerbToSectionAutoCreatesSection(): void
    {
        $svc = $this->makeService();
        $this->assertTrue($svc->addVerbToSection('intro', 'answer', []));
        $doc = $svc->getDocument()->toArray();
        $this->assertSame([['answer' => []]], $doc['sections']['intro']);
    }

    public function testServiceAddVerbSleepTakesInteger(): void
    {
        $svc = $this->makeService();
        $this->assertTrue($svc->addVerb('sleep', 500));
        $doc = $svc->getDocument()->toArray();
        $this->assertSame([['sleep' => 500]], $doc['sections']['main']);
    }

    public function testServiceAddVerbConsultsHandlerAndThrowsOnInvalidAi(): void
    {
        $svc = $this->makeService();
        // The ai handler rejects a config without a prompt.
        $this->expectException(\SignalWire\Utils\SchemaValidationError::class);
        $svc->addVerb('ai', ['SWAIG' => []]);
    }

    public function testServiceAddVerbAcceptsValidAiViaHandler(): void
    {
        $svc = $this->makeService();
        $this->assertTrue($svc->addVerb('ai', ['prompt' => ['text' => 'hi']]));
        $doc = $svc->getDocument()->toArray();
        $this->assertSame(
            [['ai' => ['prompt' => ['text' => 'hi']]]],
            $doc['sections']['main']
        );
    }

    public function testServiceResetDocument(): void
    {
        $svc = $this->makeService();
        $svc->addVerb('answer', []);
        $svc->resetDocument();
        $doc = $svc->getDocument()->toArray();
        $this->assertSame(['main' => []], $doc['sections']);
    }

    public function testServiceRenderDocumentProducesJson(): void
    {
        $svc = $this->makeService();
        $svc->addVerb('answer', []);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($svc->renderDocument(), true);
        $this->assertSame([['answer' => []]], Shape::at($decoded, 'sections', 'main'));
    }

    public function testServiceRegisterVerbHandlerRoutesValidation(): void
    {
        $svc = $this->makeService();
        $handler = new class () extends SWMLVerbHandler {
            public function getVerbName(): string
            {
                return 'answer';
            }

            /** @param array<string, mixed> $config @return array{0: bool, 1: list<string>} */
            public function validateConfig(array $config): array
            {
                // Always reject to prove the handler is consulted.
                return [false, ['handler rejected']];
            }

            /** @param array<string, mixed> $kwargs @return array<string, mixed> */
            public function buildConfig(array $kwargs = []): array
            {
                return $kwargs;
            }
        };
        $svc->registerVerbHandler($handler);

        $this->expectException(\SignalWire\Utils\SchemaValidationError::class);
        $svc->addVerb('answer', []);
    }

    public function testServiceAsRouterReturnsHandler(): void
    {
        $svc = $this->makeService();
        // asRouter() returns the service itself (which implements RequestHandlerLike).
        $router = $svc->asRouter();
        $this->assertSame($svc, $router);
    }

    public function testServiceManualSetProxyUrlOverridesProxyBase(): void
    {
        $svc = $this->makeService();
        $ret = $svc->manualSetProxyUrl('https://tunnel.example.com/');
        $this->assertSame($svc, $ret);
        // Trailing slash stripped and used as the proxy base.
        $this->assertSame('https://tunnel.example.com', $svc->getProxyUrlBase());
    }

    public function testServiceStopFlipsRunningFlag(): void
    {
        $svc = $this->makeService();

        // Reach the protected `running` flag via reflection to prove stop()
        // actually mutates state (mirrors Python's self._running = False).
        $prop = new \ReflectionProperty(Service::class, 'running');
        $prop->setValue($svc, true);
        $this->assertTrue($prop->getValue($svc));

        $svc->stop();
        $this->assertFalse($prop->getValue($svc));
    }

    public function testServiceFullValidationEnabledReturnsBool(): void
    {
        $svc = $this->makeService();
        // The flag is deterministic per service instance; calling it must not
        // throw and must return a stable boolean across calls.
        $this->assertSame($svc->fullValidationEnabled(), $svc->fullValidationEnabled());
    }
}
