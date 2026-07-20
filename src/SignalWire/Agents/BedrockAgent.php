<?php

declare(strict_types=1);

namespace SignalWire\Agents;

use SignalWire\Agent\AgentBase;

/**
 * BedrockAgent — Amazon Bedrock voice-to-voice integration.
 *
 * Extends {@see AgentBase} to support Amazon Bedrock's voice-to-voice model
 * while keeping full compatibility with the SignalWire agent ecosystem
 * (skills, POM, SWAIG functions, post-prompt). The one behavioral difference
 * from a standard agent is that the rendered SWML uses the `amazon_bedrock`
 * verb instead of `ai`.
 *
 * Ported from the Python SDK `signalwire.agents.bedrock.BedrockAgent` (shape
 * mirrors the TypeScript `BedrockAgent`). Voice and inference parameters live
 * inside the prompt object for Bedrock (not as separate fields).
 */
class BedrockAgent extends AgentBase
{
    private string $voiceId;
    private float $temperature;
    private float $topP;
    private int $maxTokens;

    /**
     * Create a BedrockAgent.
     *
     * @param string      $name         Agent name.
     * @param string      $route        HTTP route for the agent.
     * @param string|null $systemPrompt Initial system prompt (may be overridden
     *                                  later with setPromptText).
     * @param string      $voiceId      Bedrock voice ID (default: matthew).
     * @param float       $temperature  Generation temperature (0-1).
     * @param float       $topP         Nucleus sampling parameter (0-1).
     * @param int         $maxTokens    Maximum tokens to generate.
     * @param string|null $host
     * @param int|null    $port
     * @param string|null $basicAuthUser
     * @param string|null $basicAuthPassword
     * @param bool        $autoAnswer
     * @param bool        $recordCall
     * @param bool        $usePom
     */
    public function __construct(
        string $name = 'bedrock_agent',
        string $route = '/bedrock',
        ?string $systemPrompt = null,
        string $voiceId = 'matthew',
        float $temperature = 0.7,
        float $topP = 0.9,
        int $maxTokens = 1024,
        ?string $host = null,
        ?int $port = null,
        ?string $basicAuthUser = null,
        ?string $basicAuthPassword = null,
        bool $autoAnswer = true,
        bool $recordCall = false,
        bool $usePom = true,
    ) {
        // Store Bedrock-specific parameters first (before base init).
        $this->voiceId     = $voiceId;
        $this->temperature = $temperature;
        $this->topP        = $topP;
        $this->maxTokens   = $maxTokens;

        parent::__construct(
            name: $name,
            route: $route,
            host: $host,
            port: $port,
            basicAuthUser: $basicAuthUser,
            basicAuthPassword: $basicAuthPassword,
            autoAnswer: $autoAnswer,
            recordCall: $recordCall,
            usePom: $usePom,
        );

        // Set initial prompt if provided (after super init).
        if ($systemPrompt !== null && $systemPrompt !== '') {
            $this->setPromptText($systemPrompt);
        }

        $this->logger->info("BedrockAgent initialized: {$this->name} on route {$this->route}");
    }

    /**
     * Render the SWML document, transforming the base `ai` verb into an
     * `amazon_bedrock` verb with the same structure. Mirrors Python
     * `BedrockAgent._render_swml` / TS `BedrockAgent.renderSwml`.
     *
     * @param array<string, mixed>|null $requestBody
     * @param array<string, string>     $headers
     * @param string|null               $callId Threaded to the base render so
     *   secure SWAIG functions get their per-tool ``__token``.
     * @return array<string, mixed>
     */
    public function renderSwml(?array $requestBody = null, array $headers = [], ?string $callId = null): array
    {
        // Build the base SWML with the ai verb, then transform it.
        $swml = parent::renderSwml($requestBody, $headers, $callId);

        $sections = is_array($swml['sections'] ?? null) ? $swml['sections'] : [];
        $main = is_array($sections['main'] ?? null) ? $sections['main'] : [];
        if ($main === []) {
            return $swml;
        }

        foreach ($main as $i => $verb) {
            if (is_array($verb) && array_key_exists('ai', $verb)) {
                $aiConfig = is_array($verb['ai']) ? $verb['ai'] : [];

                // Build the amazon_bedrock verb with the same structure. Voice
                // and inference params live inside the prompt object.
                $bedrockConfig = [
                    'prompt'         => $this->addVoiceToPrompt(
                        is_array($aiConfig['prompt'] ?? null) ? $aiConfig['prompt'] : []
                    ),
                    'SWAIG'          => $aiConfig['SWAIG'] ?? new \stdClass(),
                    'params'         => $aiConfig['params'] ?? new \stdClass(),
                    'global_data'    => $aiConfig['global_data'] ?? new \stdClass(),
                    'post_prompt'    => $aiConfig['post_prompt'] ?? null,
                    'post_prompt_url' => $aiConfig['post_prompt_url'] ?? null,
                ];

                // Remove null values.
                $cleaned = [];
                foreach ($bedrockConfig as $k => $v) {
                    if ($v !== null) {
                        $cleaned[$k] = $v;
                    }
                }

                $main[$i] = ['amazon_bedrock' => $cleaned];
                break;
            }
        }

        $sections['main'] = $main;
        $swml['sections'] = $sections;
        return $swml;
    }

    /**
     * Add voice configuration to the prompt object. In Bedrock, voice and
     * inference params are part of the prompt object (not separate fields).
     * Mirrors Python `_add_voice_to_prompt`.
     *
     * @param array<string, mixed> $promptConfig
     * @return array<string, mixed>
     */
    private function addVoiceToPrompt(array $promptConfig): array
    {
        $filtered = [];
        // Skip text-model-specific parameters that don't apply to Bedrock's
        // voice-to-voice model.
        $skip = ['barge_confidence', 'presence_penalty', 'frequency_penalty'];
        foreach ($promptConfig as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            $filtered[$key] = $value;
        }
        $filtered['voice_id']    = $this->voiceId;
        $filtered['temperature'] = $this->temperature;
        $filtered['top_p']       = $this->topP;
        return $filtered;
    }

    /**
     * Set the Bedrock voice ID (e.g. "matthew", "joanna").
     * Mirrors Python `set_voice` (TS returns `this`).
     */
    public function setVoice(string $voiceId): self
    {
        $this->voiceId = $voiceId;
        $this->logger->debug("Voice set to: {$voiceId}");
        return $this;
    }

    /**
     * Update Bedrock inference parameters. Any null argument is left unchanged.
     * Mirrors Python `set_inference_params` (TS returns `this`).
     */
    public function setInferenceParams(
        ?float $temperature = null,
        ?float $topP = null,
        ?int $maxTokens = null
    ): self {
        if ($temperature !== null) {
            $this->temperature = $temperature;
        }
        if ($topP !== null) {
            $this->topP = $topP;
        }
        if ($maxTokens !== null) {
            $this->maxTokens = $maxTokens;
        }
        $this->logger->debug(
            "Inference params updated: temp={$this->temperature}, "
            . "top_p={$this->topP}, max_tokens={$this->maxTokens}"
        );
        return $this;
    }

    /**
     * Set the LLM model — not applicable for Bedrock, which uses a fixed
     * voice-to-voice model. Logs a warning and does nothing.
     * Mirrors Python `set_llm_model` (TS returns `this`).
     */
    public function setLlmModel(string $model): self
    {
        $this->logger->warn(
            "setLlmModel('{$model}') called but Bedrock uses a fixed voice-to-voice model"
        );
        return $this;
    }

    /**
     * Set the LLM temperature — redirects to {@see setInferenceParams}.
     * Mirrors Python `set_llm_temperature` (TS returns `this`).
     */
    public function setLlmTemperature(float $temperature): self
    {
        return $this->setInferenceParams(temperature: $temperature);
    }

    /**
     * Set post-prompt LLM parameters — not applicable for Bedrock (its
     * post-prompt uses OpenAI configured in the engine). Logs a warning.
     * Mirrors Python `set_post_prompt_llm_params`. Returns `self` to stay
     * signature-compatible with the fluent base
     * {@see AgentBase::setPostPromptLlmParams}.
     *
     * @param array<string, mixed> $params Ignored parameters.
     */
    public function setPostPromptLlmParams(array $params): self
    {
        $this->logger->warn(
            'setPostPromptLlmParams() called but Bedrock post-prompt uses OpenAI configured in the engine'
        );
        return $this;
    }

    /**
     * Set prompt LLM parameters — use {@see setInferenceParams} instead for
     * Bedrock. Logs a warning. Returns `self` to stay signature-compatible
     * with the fluent base {@see AgentBase::setPromptLlmParams}.
     *
     * @param array<string, mixed> $params Ignored parameters.
     */
    public function setPromptLlmParams(array $params): self
    {
        $this->logger->warn(
            'setPromptLlmParams() called - use setInferenceParams() for Bedrock'
        );
        return $this;
    }
}
