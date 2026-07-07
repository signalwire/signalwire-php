<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\SWML;

/**
 * Renders SWML documents for SignalWire AI Agents with AI and SWAIG components.
 *
 * Mirrors the `signalwire.core.swml_renderer.SwmlRenderer` API
 * a small standalone renderer built on top of the
 * {@see Service} document model. Its two static methods delegate to the
 * Service's Document.
 */
class SwmlRenderer
{
    /**
     * Generate a complete SWML document with an AI configuration.
     *
     * @param string|list<array<string,mixed>> $prompt AI prompt text, or a POM structure when $promptIsPom.
     * @param list<array<string,mixed>>|null $swaigFunctions List of SWAIG function definitions.
     * @param array<string,mixed>|null $params Additional AI verb parameters.
     * @return string SWML document as a JSON string.
     */
    public static function renderSwml(
        string|array $prompt,
        Service $service,
        ?string $postPrompt = null,
        ?string $postPromptUrl = null,
        ?array $swaigFunctions = null,
        ?string $startupHookUrl = null,
        ?string $hangupHookUrl = null,
        bool $promptIsPom = false,
        ?array $params = null,
        bool $addAnswer = false,
        bool $recordCall = false,
        string $recordFormat = 'mp4',
        bool $recordStereo = true,
        string $format = 'json',
        ?string $defaultWebhookUrl = null,
    ): string {
        $doc = $service->getDocument();
        $doc->reset();

        if ($addAnswer) {
            $doc->addVerb('answer', new \stdClass());
        }

        if ($recordCall) {
            $doc->addVerb('record_call', ['format' => $recordFormat, 'stereo' => $recordStereo]);
        }

        // Assemble the SWAIG function list, prepending startup/hangup hooks.
        $functions = [];
        if ($startupHookUrl !== null && $startupHookUrl !== '') {
            $functions[] = [
                'function' => 'startup_hook',
                'description' => 'Called when the call starts',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
                'web_hook_url' => $startupHookUrl,
            ];
        }
        if ($hangupHookUrl !== null && $hangupHookUrl !== '') {
            $functions[] = [
                'function' => 'hangup_hook',
                'description' => 'Called when the call ends',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
                'web_hook_url' => $hangupHookUrl,
            ];
        }
        foreach ($swaigFunctions ?? [] as $func) {
            $fn = $func['function'] ?? null;
            if ($fn === 'startup_hook' || $fn === 'hangup_hook') {
                continue;
            }
            $functions[] = $func;
        }

        // Build the SWAIG config object.
        $swaigConfig = [];
        if ($functions !== [] || ($defaultWebhookUrl !== null && $defaultWebhookUrl !== '')) {
            if ($defaultWebhookUrl !== null && $defaultWebhookUrl !== '') {
                $swaigConfig['defaults'] = ['web_hook_url' => $defaultWebhookUrl];
            }
            if ($functions !== []) {
                $swaigConfig['functions'] = $functions;
            }
        }

        // Build the AI verb config.
        $ai = [];
        if ($promptIsPom) {
            $ai['prompt'] = ['pom' => $prompt];
        } else {
            $ai['prompt'] = ['text' => $prompt];
        }
        if ($postPrompt !== null) {
            $ai['post_prompt'] = ['text' => $postPrompt];
        }
        if ($postPromptUrl !== null) {
            $ai['post_prompt_url'] = $postPromptUrl;
        }
        if ($swaigConfig !== []) {
            $ai['SWAIG'] = $swaigConfig;
        }
        foreach ($params ?? [] as $k => $v) {
            $ai[$k] = $v;
        }

        $doc->addVerb('ai', $ai);

        return self::renderIn($service, $format);
    }

    /**
     * Generate a SWML document for a function response — a `play` of the
     * response text followed by any provided actions.
     *
     * @param list<array<string,mixed>>|null $actions
     * @return string SWML document as a JSON string.
     */
    public static function renderFunctionResponseSwml(
        string $responseText,
        Service $service,
        ?array $actions = null,
        string $format = 'json',
    ): string {
        $doc = $service->getDocument();
        $doc->reset();

        if ($responseText !== '') {
            $doc->addVerb('play', ['text' => $responseText]);
        }

        foreach ($actions ?? [] as $action) {
            foreach (['play', 'hangup', 'transfer', 'ai'] as $verb) {
                if (array_key_exists($verb, $action)) {
                    $doc->addVerb($verb, $action[$verb]);
                    break;
                }
            }
        }

        return self::renderIn($service, $format);
    }

    /**
     * Render the built document in the requested format. JSON is the native
     * output; 'yaml' emits YAML when ext-yaml is available (matches the
     * reference's optional yaml branch), otherwise falls back to JSON.
     */
    private static function renderIn(Service $service, string $format): string
    {
        if (strtolower($format) === 'yaml' && function_exists('yaml_emit')) {
            return (string) yaml_emit($service->getDocument()->toArray());
        }
        return $service->render();
    }
}
