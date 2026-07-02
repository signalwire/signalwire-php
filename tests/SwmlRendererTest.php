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

    /** @return array<string,mixed> */
    private function decode(string $swml): array
    {
        $decoded = json_decode($swml, true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    public function testRenderSwmlBuildsAiVerbWithPromptText(): void
    {
        $swml = SwmlRenderer::renderSwml('You are a helpful agent.', $this->service());
        $doc = $this->decode($swml);

        $main = $doc['sections']['main'];
        $ai = null;
        foreach ($main as $verb) {
            if (isset($verb['ai'])) {
                $ai = $verb['ai'];
            }
        }
        $this->assertNotNull($ai, 'an ai verb must be present');
        $this->assertSame('You are a helpful agent.', $ai['prompt']['text']);
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
        $this->assertSame('get_time', $ai['SWAIG']['functions'][0]['function']);
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
        $names = array_column($ai['SWAIG']['functions'], 'function');
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
        $verbs = array_map(static fn ($v) => array_key_first($v), $doc['sections']['main']);
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
        $this->assertSame('https://a.example.com/swaig', $ai['SWAIG']['defaults']['web_hook_url']);
    }

    public function testRenderFunctionResponseSwmlBuildsPlay(): void
    {
        $swml = SwmlRenderer::renderFunctionResponseSwml('Here is your answer.', $this->service());
        $doc = $this->decode($swml);
        $play = null;
        foreach ($doc['sections']['main'] as $verb) {
            if (isset($verb['play'])) {
                $play = $verb['play'];
            }
        }
        $this->assertSame(['text' => 'Here is your answer.'], $play);
    }

    public function testRenderFunctionResponseSwmlAppendsActions(): void
    {
        $swml = SwmlRenderer::renderFunctionResponseSwml(
            'Bye.',
            $this->service(),
            actions: [['hangup' => ['reason' => 'done']]],
        );
        $doc = $this->decode($swml);
        $verbs = array_map(static fn ($v) => array_key_first($v), $doc['sections']['main']);
        $this->assertContains('hangup', $verbs);
    }

    /**
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private function findAi(array $doc): array
    {
        foreach ($doc['sections']['main'] as $verb) {
            if (isset($verb['ai'])) {
                return $verb['ai'];
            }
        }
        $this->fail('no ai verb found');
    }
}
