<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\Logging\Logger;
use SignalWire\SWML\Schema;
use SignalWire\SWML\Service;
use SignalWire\Utils\SchemaValidationError;

/**
 * SWML strict-render contract (Wave-2 P#5) — PHP port of the python reference
 * suite tests/unit/core/test_swml_strict_render.py.
 *
 * Building / rendering an SWML document with a MISSHAPEN config, an UNKNOWN
 * verb, or a MISSPELLED key must RAISE a clear error — not silently drop or
 * accept it. The r5 dogfood named a "silent-drop family": unknown verbs
 * accepted, misspelled/unknown verb-config keys swallowed, and dangling SWAIG
 * step-function references emitted with no warning.
 *
 * Baseline cases (unknown verb, misspelled/unknown/ wrong-typed keys on the
 * closed verbs) are regression guards; the GAP cases (ai top-level keys and
 * dangling set_functions references) are the two silent-accepts this pass
 * closes.
 */
class SwmlStrictRenderTest extends TestCase
{
    protected function setUp(): void
    {
        Schema::reset();
        Logger::reset();
    }

    /** A SWMLService with schema validation ON (production default). */
    private function strictService(): Service
    {
        return new Service(name: 'strict', route: '/strict');
    }

    private function strictAgent(): AgentBase
    {
        return new AgentBase(name: 'ctxagent', route: '/ctx');
    }

    // ── Baseline: unknown verb / good verb ──────────────────────────────

    public function testUnknownVerbRaises(): void
    {
        $this->expectException(SchemaValidationError::class);
        $this->strictService()->addVerb('foobar', []);
    }

    public function testGoodVerbRenders(): void
    {
        $this->assertTrue($this->strictService()->addVerb('answer', ['max_duration' => 5]));
    }

    // ── Baseline: misspelled / unknown / wrong-typed keys on closed verbs ─

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function misspelledKeyProvider(): array
    {
        return [
            'answer misspelled max_duration' => ['answer', ['maxduration' => 5]],
            'answer unknown key' => ['answer', ['wibble' => 1]],
            'play misspelled urls' => ['play', ['urlz' => ['say:hi']]],
            'play valid plus unknown extra' => ['play', ['url' => 'say:hi', 'foo' => 1]],
            'record misspelled format' => ['record', ['formatt' => 'wav']],
            'prompt misspelled text' => ['prompt', ['txt' => 'hi']],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @dataProvider misspelledKeyProvider
     */
    public function testMisspelledOrUnknownKeyRaises(string $verb, array $config): void
    {
        $this->expectException(SchemaValidationError::class);
        $this->strictService()->addVerb($verb, $config);
    }

    public function testWrongTypedConfigRaises(): void
    {
        $this->expectException(SchemaValidationError::class);
        $this->strictService()->addVerb('answer', ['max_duration' => 'notanumber']);
    }

    public function testSwmlVarTemplateStringForNumericKeyRenders(): void
    {
        // max_duration is `integer OR ${var}`; a valid template string passes
        // even though it is not an integer — the SWMLVar escape hatch.
        $this->assertTrue(
            $this->strictService()->addVerb('answer', ['max_duration' => '${duration}'])
        );
    }

    // ── GAP 1: the ai verb rejects unknown/misspelled top-level keys ─────

    public function testAiGoodConfigRenders(): void
    {
        $this->assertTrue(
            $this->strictService()->addVerb('ai', ['prompt' => ['text' => 'hi']])
        );
    }

    public function testAiGoodConfigWithSwaigRenders(): void
    {
        $this->assertTrue(
            $this->strictService()->addVerb(
                'ai',
                ['prompt' => ['text' => 'hi'], 'SWAIG' => ['functions' => []]]
            )
        );
    }

    public function testAiMisspelledTopLevelKeyRaises(): void
    {
        $this->expectException(SchemaValidationError::class);
        $this->strictService()->addVerb('ai', ['prompt' => ['text' => 'hi'], 'temperatur' => 0.5]);
    }

    public function testAiUnknownTopLevelKeyRaises(): void
    {
        $this->expectException(SchemaValidationError::class);
        $this->strictService()->addVerb('ai', ['prompt' => ['text' => 'hi'], 'zzz' => 1]);
    }

    public function testAiMissingPromptStillRaises(): void
    {
        // The handler's own prompt check must survive alongside the schema pass.
        $this->expectException(SchemaValidationError::class);
        $this->strictService()->addVerb('ai', ['post_prompt' => ['text' => 'bye']]);
    }

    public function testAiParamsSubobjectStaysOpen(): void
    {
        // params is the DELIBERATE open door for LLM tuning params; a key
        // inside it is NOT a misspelling and must render.
        $this->assertTrue(
            $this->strictService()->addVerb(
                'ai',
                ['prompt' => ['text' => 'hi'], 'params' => ['some_future_param' => 1]]
            )
        );
    }

    // ── GAP 2: dangling step set_functions references ───────────────────

    public function testDanglingFunctionRefRaises(): void
    {
        $agent = $this->strictAgent();
        $agent->defineTool('order_status', 'look up an order', [], fn (array $a, array $raw): mixed => null);
        $contexts = $agent->defineContexts();
        $step = $contexts->addContext('default')->addStep('help');
        $step->setText('help the caller');
        $step->setFunctions(['order_status', 'get_datetime']); // get_datetime dangles

        try {
            $contexts->toArray();
            $this->fail('expected a dangling-function-reference error');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('get_datetime', $e->getMessage());
        }
    }

    public function testRegisteredFunctionRefRenders(): void
    {
        $agent = $this->strictAgent();
        $agent->defineTool('order_status', 'look up an order', [], fn (array $a, array $raw): mixed => null);
        $contexts = $agent->defineContexts();
        $step = $contexts->addContext('default')->addStep('help');
        $step->setText('help the caller');
        $step->setFunctions(['order_status']);

        $doc = $contexts->toArray();
        $this->assertArrayHasKey('default', $doc);
    }

    public function testReservedNativeToolRefAllowed(): void
    {
        // next_step / change_context are auto-injected natives; referencing
        // them explicitly must not be treated as dangling.
        $agent = $this->strictAgent();
        $contexts = $agent->defineContexts();
        $step = $contexts->addContext('default')->addStep('help');
        $step->setText('help the caller');
        $step->setFunctions(['next_step', 'change_context']);

        $doc = $contexts->toArray();
        $this->assertArrayHasKey('default', $doc);
    }

    /**
     * @return array<string, array{0: list<string>|string}>
     */
    public static function disableAllProvider(): array
    {
        return [
            'string none' => ['none'],
            'empty list' => [[]],
        ];
    }

    /**
     * @param list<string>|string $value
     * @dataProvider disableAllProvider
     */
    public function testFunctionsNoneAndEmptyRender(array|string $value): void
    {
        // "none" and [] are explicit disable-all — never dangling.
        $agent = $this->strictAgent();
        $contexts = $agent->defineContexts();
        $step = $contexts->addContext('default')->addStep('help');
        $step->setText('help the caller');
        $step->setFunctions($value);

        $doc = $contexts->toArray();
        $this->assertArrayHasKey('default', $doc);
    }

    // ── Baseline: dangling valid_contexts (already enforced) ─────────────

    public function testDanglingValidContextRaises(): void
    {
        $agent = $this->strictAgent();
        $contexts = $agent->defineContexts();
        $step = $contexts->addContext('default')->addStep('help');
        $step->setText('help the caller');
        $step->setValidContexts(['nowhere']);

        $this->expectException(\Throwable::class);
        $contexts->toArray();
    }
}
