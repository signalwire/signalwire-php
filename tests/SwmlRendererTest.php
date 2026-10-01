<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\SWML\Service;
use SignalWire\SWML\SwmlRenderer;
use SignalWire\Tests\Support\Shape;

/**
 * Behavioral parity tests for SwmlRenderer. Mirrors the Python reference
 * (core/swml_renderer.py): render_swml builds an AI verb (+ SWAIG config,
 * answer, record_call) and render_function_response_swml builds a play + actions.
 */
class SwmlRendererTest extends TestCase
{
    private function service(): Service
    {
        return new Service(name: 'test', basicAuthUser: 'u', basicAuthPassword: 'p');
    }

    /** @return array<array-key,mixed> */
    private function decode(string $swml): array
    {
        return Shape::arr(json_decode($swml, true));
    }

    public function testRenderSwmlBuildsAiVerbWithPromptText(): void
    {
        $swml = SwmlRenderer::renderSwml('You are a helpful agent.', $this->service());
        $doc = $this->decode($swml);

        $main = Shape::sub($doc, 'sections', 'main');
        $ai = null;
        foreach ($main as $verb) {
            if (is_array($verb) && isset($verb['ai'])) {
                $ai = $verb['ai'];
            }
        }
        $this->assertNotNull($ai, 'an ai verb must be present');
        $this->assertSame('You are a helpful agent.', Shape::at($ai, 'prompt', 'text'));
    }

    public function testRenderSwmlIncludesSwaigFunctions(): void
    {
        $funcs = [[
            'function' => 'get_time',
            'description' => 'Get the time',
            'parameters' => ['type' => 'object', 'properties' => []],
        ]];
        $swml = SwmlRenderer::renderSwml('prompt', $this->service(), swaigFunctions: $funcs);
        $doc = $this->decode($swml);

        $ai = $this->findAi($doc);
        $this->assertArrayHasKey('SWAIG', $ai);
        $this->assertSame('get_time', Shape::at($ai, 'SWAIG', 'functions', 0, 'function'));
    }

    public function testRenderSwmlPrependsStartupAndHangupHooks(): void
    {
        $swml = SwmlRenderer::renderSwml(
            'prompt',
            $this->service(),
            startupHookUrl: 'https://a.example.com/startup',
            hangupHookUrl: 'https://a.example.com/hangup',
        );
        $ai = $this->findAi($this->decode($swml));
        $names = array_column(Shape::sub($ai, 'SWAIG', 'functions'), 'function');
        $this->assertContains('startup_hook', $names);
        $this->assertContains('hangup_hook', $names);
    }

    public function testRenderSwmlAddsAnswerAndRecordCall(): void
    {
        $swml = SwmlRenderer::renderSwml(
            'prompt',
            $this->service(),
            addAnswer: true,
            recordCall: true,
            recordFormat: 'wav',
        );
        $doc = $this->decode($swml);
        $verbs = array_map(
            static fn (mixed $v): int|string|null => is_array($v) ? array_key_first($v) : null,
            Shape::sub($doc, 'sections', 'main')
        );
        $this->assertContains('answer', $verbs);
        $this->assertContains('record_call', $verbs);
    }

    public function testRenderSwmlUsesDefaultWebhookUrl(): void
    {
        $swml = SwmlRenderer::renderSwml(
            'prompt',
            $this->service(),
            defaultWebhookUrl: 'https://a.example.com/swaig',
        );
        $ai = $this->findAi($this->decode($swml));
        $this->assertSame('https://a.example.com/swaig', Shape::at($ai, 'SWAIG', 'defaults', 'web_hook_url'));
    }

    /**
     * The SWML `play` verb has NO `text` key — its config is PlayWithURL /
     * PlayWithURLS, and spoken text goes through the `say:` URL scheme (schema
     * `play_url` pattern `^(http://.*|...|say: ?.*|...)$`). Emitting
     * `{"text": ...}` produced a document the schema rejects. Mirrors the
     * reference's `service.add_verb("play", {"url": f"say:{response_text}"})`.
     */
    public function testRenderFunctionResponseSwmlPlaysTextViaSayUrl(): void
    {
        $swml = SwmlRenderer::renderFunctionResponseSwml('Here is your answer.', $this->service());
        $doc = $this->decode($swml);
        $play = null;
        foreach (Shape::sub($doc, 'sections', 'main') as $verb) {
            if (is_array($verb) && isset($verb['play'])) {
                $play = $verb['play'];
            }
        }
        $this->assertSame(['url' => 'say:Here is your answer.'], $play);
    }

    /**
     * The emitted play config must survive the port's OWN schema validator.
     * The renderer used to reach past it via the raw Document entry point, so
     * an invalid verb could never be caught; route through Service::addVerb.
     */
    public function testRenderFunctionResponseSwmlPlayPassesSchemaValidation(): void
    {
        $service = $this->service();
        $swml = SwmlRenderer::renderFunctionResponseSwml('Here is your answer.', $service);
        $doc = $this->decode($swml);
        $play = null;
        foreach (Shape::sub($doc, 'sections', 'main') as $verb) {
            if (is_array($verb) && isset($verb['play'])) {
                $play = $verb['play'];
            }
        }
        $this->assertIsArray($play);
        /** @var array<string,mixed> $play */
        [$isValid, $errors] = $service->getSchemaUtils()->validateVerb('play', $play);
        $this->assertTrue($isValid, 'emitted play config rejected: ' . implode('; ', $errors));
    }

    public function testRenderFunctionResponseSwmlAppendsActions(): void
    {
        $swml = SwmlRenderer::renderFunctionResponseSwml(
            'Bye.',
            $this->service(),
            actions: [['hangup' => ['reason' => 'done']]],
        );
        $doc = $this->decode($swml);
        $verbs = array_map(
            static fn (mixed $v): int|string|null => is_array($v) ? array_key_first($v) : null,
            Shape::sub($doc, 'sections', 'main')
        );
        $this->assertContains('hangup', $verbs);
    }

    /**
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private function findAi(array $doc): array
    {
        foreach (Shape::sub($doc, 'sections', 'main') as $verb) {
            if (is_array($verb) && isset($verb['ai'])) {
                $ai = $verb['ai'];
                $this->assertIsArray($ai);
                /** @var array<string,mixed> $ai */
                return $ai;
            }
        }
        $this->fail('no ai verb found');
    }
}
