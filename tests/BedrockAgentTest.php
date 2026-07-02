<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\Agents\BedrockAgent;

/**
 * Tests for the Amazon Bedrock voice-to-voice prefab agent (item I).
 */
class BedrockAgentTest extends TestCase
{
    private function makeAgent(): BedrockAgent
    {
        return new BedrockAgent(
            name: 'bedrock',
            systemPrompt: 'You are a helpful voice assistant.',
            basicAuthUser: 'testuser',
            basicAuthPassword: 'testpass',
        );
    }

    #[Test]
    public function rendersAmazonBedrockVerbInsteadOfAi(): void
    {
        $agent = $this->makeAgent();
        $swml = $agent->renderSwml();

        $main = $swml['sections']['main'];
        // No `ai` verb should remain; it must be transformed to amazon_bedrock.
        $hasAi = false;
        $bedrock = null;
        foreach ($main as $verb) {
            if (isset($verb['ai'])) {
                $hasAi = true;
            }
            if (isset($verb['amazon_bedrock'])) {
                $bedrock = $verb['amazon_bedrock'];
            }
        }
        $this->assertFalse($hasAi, 'ai verb should be replaced by amazon_bedrock');
        $this->assertNotNull($bedrock, 'amazon_bedrock verb should be present');
    }

    #[Test]
    public function voiceAndInferenceParamsLiveInsideThePrompt(): void
    {
        $agent = new BedrockAgent(
            name: 'bedrock',
            voiceId: 'joanna',
            temperature: 0.5,
            topP: 0.95,
            basicAuthUser: 'u',
            basicAuthPassword: 'p',
        );
        $swml = $agent->renderSwml();

        $bedrock = null;
        foreach ($swml['sections']['main'] as $verb) {
            if (isset($verb['amazon_bedrock'])) {
                $bedrock = $verb['amazon_bedrock'];
            }
        }
        $this->assertNotNull($bedrock);
        $this->assertSame('joanna', $bedrock['prompt']['voice_id']);
        $this->assertSame(0.5, $bedrock['prompt']['temperature']);
        $this->assertSame(0.95, $bedrock['prompt']['top_p']);
    }

    #[Test]
    public function setVoiceUpdatesTheRenderedVoiceId(): void
    {
        $agent = $this->makeAgent();
        $ret = $agent->setVoice('kevin');
        $this->assertSame($agent, $ret);

        $bedrock = $this->bedrockVerb($agent);
        $this->assertSame('kevin', $bedrock['prompt']['voice_id']);
    }

    #[Test]
    public function setInferenceParamsUpdatesOnlyProvidedValues(): void
    {
        $agent = $this->makeAgent();
        $agent->setInferenceParams(temperature: 0.2);

        $bedrock = $this->bedrockVerb($agent);
        $this->assertSame(0.2, $bedrock['prompt']['temperature']);
        // top_p unchanged from the 0.9 default.
        $this->assertSame(0.9, $bedrock['prompt']['top_p']);
    }

    #[Test]
    public function setLlmTemperatureRedirectsToInferenceParams(): void
    {
        $agent = $this->makeAgent();
        $agent->setLlmTemperature(0.33);

        $bedrock = $this->bedrockVerb($agent);
        $this->assertSame(0.33, $bedrock['prompt']['temperature']);
    }

    #[Test]
    public function inapplicableSettersReturnSelfAndAreNoOps(): void
    {
        $agent = $this->makeAgent();
        // These log a warning and do nothing but must stay fluent (return self).
        $this->assertSame($agent, $agent->setLlmModel('gpt-4'));
        $this->assertSame($agent, $agent->setPromptLlmParams(['temperature' => 1]));
        $this->assertSame($agent, $agent->setPostPromptLlmParams(['temperature' => 1]));
    }

    #[Test]
    public function textModelSpecificPromptFieldsAreFilteredOut(): void
    {
        $agent = $this->makeAgent();
        // Seed prompt-level LLM params that don't apply to Bedrock's V2V model.
        $agent->setPromptLlmParams([
            'barge_confidence'   => 0.5,
            'presence_penalty'   => 0.1,
            'frequency_penalty'  => 0.2,
        ]);

        $bedrock = $this->bedrockVerb($agent);
        $this->assertArrayNotHasKey('barge_confidence', $bedrock['prompt']);
        $this->assertArrayNotHasKey('presence_penalty', $bedrock['prompt']);
        $this->assertArrayNotHasKey('frequency_penalty', $bedrock['prompt']);
    }

    /**
     * @return array<string, mixed>
     */
    private function bedrockVerb(BedrockAgent $agent): array
    {
        $swml = $agent->renderSwml();
        foreach ($swml['sections']['main'] as $verb) {
            if (isset($verb['amazon_bedrock'])) {
                return $verb['amazon_bedrock'];
            }
        }
        $this->fail('amazon_bedrock verb not found');
    }
}
