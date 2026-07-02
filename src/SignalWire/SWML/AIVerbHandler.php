<?php

declare(strict_types=1);

namespace SignalWire\SWML;

/**
 * Handler for the SWML 'ai' verb.
 *
 * The 'ai' verb is complex and requires specialized handling, particularly
 * for managing prompts, SWAIG functions, and AI configurations.
 *
 * Mirrors Python's `signalwire/core/swml_handler.py::AIVerbHandler` and the
 * TypeScript `AIVerbHandler`.
 */
class AIVerbHandler extends SWMLVerbHandler
{
    /**
     * Get the name of the verb this handler handles.
     */
    public function getVerbName(): string
    {
        return 'ai';
    }

    /**
     * Validate the configuration for the AI verb.
     *
     * Checks that:
     *  - `prompt` is present and is an object/array,
     *  - `prompt` contains exactly one of `text` or `pom` (mutually exclusive),
     *  - `prompt.contexts`, if present, is an object,
     *  - `SWAIG`, if present, is an object.
     *
     * @param array<string, mixed> $config
     * @return array{0: bool, 1: list<string>}
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        // Check that prompt is present.
        if (!array_key_exists('prompt', $config)) {
            $errors[] = "Missing required field 'prompt'";
            return [false, $errors];
        }

        $prompt = $config['prompt'];
        if (!is_array($prompt)) {
            $errors[] = "'prompt' must be an object";
            return [false, $errors];
        }

        // Check that prompt contains either text or pom (required, mutually exclusive).
        $hasText = array_key_exists('text', $prompt);
        $hasPom = array_key_exists('pom', $prompt);
        $hasContexts = array_key_exists('contexts', $prompt);

        $basePromptCount = ($hasText ? 1 : 0) + ($hasPom ? 1 : 0);
        if ($basePromptCount === 0) {
            $errors[] = "'prompt' must contain either 'text' or 'pom' as base prompt";
        } elseif ($basePromptCount > 1) {
            $errors[] = "'prompt' can only contain one of: 'text' or 'pom' (mutually exclusive)";
        }

        // Contexts are optional and can be combined with text or pom.
        if ($hasContexts) {
            $contexts = $prompt['contexts'];
            if (!is_array($contexts)) {
                $errors[] = "'prompt.contexts' must be an object";
            }
        }

        // Validate SWAIG structure if present.
        if (array_key_exists('SWAIG', $config)) {
            $swaig = $config['SWAIG'];
            if (!is_array($swaig)) {
                $errors[] = "'SWAIG' must be an object";
            }
        }

        return [count($errors) === 0, $errors];
    }

    /**
     * Build a configuration for the AI verb.
     *
     * Requires exactly one of `prompt_text` or `prompt_pom` (mutually
     * exclusive). Throws an {@see \InvalidArgumentException} if both or neither
     * are provided.
     *
     * Recognised keys in `$kwargs`:
     *  - `prompt_text`     (string) base text prompt,
     *  - `prompt_pom`      (list)   POM structure prompt,
     *  - `contexts`        (array)  optional contexts/steps configuration,
     *  - `post_prompt`     (string) optional post-prompt text,
     *  - `post_prompt_url` (string) optional post-prompt URL,
     *  - `swaig`           (array)  optional SWAIG configuration.
     *
     * Extra keys are routed as follows: `languages`, `hints`, `pronounce` and
     * `global_data` are placed at the top level of the config; every other
     * extra key is placed into `config.params`.
     *
     * @param array<string, mixed> $kwargs
     * @return array<string, mixed>
     */
    public function buildConfig(array $kwargs = []): array
    {
        $promptText = $kwargs['prompt_text'] ?? null;
        $promptPom = $kwargs['prompt_pom'] ?? null;
        $contexts = $kwargs['contexts'] ?? null;
        $postPrompt = $kwargs['post_prompt'] ?? null;
        $postPromptUrl = $kwargs['post_prompt_url'] ?? null;
        $swaig = $kwargs['swaig'] ?? null;

        // Strip the recognised keys; everything left is "rest".
        $rest = $kwargs;
        unset(
            $rest['prompt_text'],
            $rest['prompt_pom'],
            $rest['contexts'],
            $rest['post_prompt'],
            $rest['post_prompt_url'],
            $rest['swaig'],
        );

        $config = [];

        // Require either text or pom as base prompt (mutually exclusive).
        $basePromptCount = ($promptText !== null ? 1 : 0) + ($promptPom !== null ? 1 : 0);
        if ($basePromptCount === 0) {
            throw new \InvalidArgumentException(
                'Either prompt_text or prompt_pom must be provided as base prompt'
            );
        }
        if ($basePromptCount > 1) {
            throw new \InvalidArgumentException('prompt_text and prompt_pom are mutually exclusive');
        }

        // Build prompt object with base prompt.
        $promptConfig = [];
        if ($promptText !== null) {
            $promptConfig['text'] = $promptText;
        } elseif ($promptPom !== null) {
            $promptConfig['pom'] = $promptPom;
        }

        // Add contexts if provided (optional, activates steps feature).
        if ($contexts !== null) {
            $promptConfig['contexts'] = $contexts;
        }

        $config['prompt'] = $promptConfig;

        // Add post-prompt if provided.
        if ($postPrompt !== null) {
            $config['post_prompt'] = ['text' => $postPrompt];
        }

        // Add post-prompt URL if provided.
        if ($postPromptUrl !== null) {
            $config['post_prompt_url'] = $postPromptUrl;
        }

        // Add SWAIG if provided.
        if ($swaig !== null) {
            $config['SWAIG'] = $swaig;
        }

        // Match Python behaviour: always initialise the params dict.
        $config['params'] = [];

        $topLevelKeys = ['languages', 'hints', 'pronounce', 'global_data'];
        foreach ($rest as $key => $value) {
            if (in_array($key, $topLevelKeys, true)) {
                $config[$key] = $value;
            } else {
                $config['params'][$key] = $value;
            }
        }

        return $config;
    }
}
