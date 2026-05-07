<?php

declare(strict_types=1);

namespace SignalWire\Agent;

use SignalWire\SWML\Service;
use SignalWire\SWML\Schema;
use SignalWire\Logging\Logger;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\Security\SessionManager;
use SignalWire\Contexts\ContextBuilder;
use SignalWire\POM\PromptObjectModel;
use SignalWire\Skills\SkillManager;

class AgentBase extends Service
{
    // ── Call handling ────────────────────────────────────────────────────
    protected bool $autoAnswer;
    protected bool $recordCall;
    protected string $recordFormat;
    protected bool $recordStereo;

    // ── Prompt / POM ────────────────────────────────────────────────────
    protected bool $usePom;
    protected array $pomSections;
    protected string $promptText;
    protected string $postPrompt;

    // ── Tools / SWAIG ───────────────────────────────────────────────────
    // $tools and $toolOrder are now declared on Service (lifted so non-agent
    // SWMLService instances can host SWAIG functions). AgentBase inherits.

    // ── Hints ───────────────────────────────────────────────────────────
    /** @var list<string> */
    protected array $hints;
    /** @var list<string> */
    protected array $patternHints;

    // ── Languages / pronunciations ──────────────────────────────────────
    /** @var list<array{name: string, code: string, voice: string}> */
    protected array $languages;
    /** @var list<array{replace: string, with: string, ignore?: string}> */
    protected array $pronunciations;

    // ── Params / data ───────────────────────────────────────────────────
    protected array $params;
    protected array $globalData;

    // ── Native functions / fillers / debug ───────────────────────────────
    /** @var list<string> */
    protected array $nativeFunctions;
    /** @var list<string> */
    protected array $internalFillers;
    protected ?string $debugEventsLevel;

    // ── LLM params ──────────────────────────────────────────────────────
    protected array $promptLlmParams;
    protected array $postPromptLlmParams;

    // ── Verbs ───────────────────────────────────────────────────────────
    /** @var list<array{string, mixed}> */
    protected array $preAnswerVerbs;
    /** @var list<array{string, mixed}> */
    protected array $postAnswerVerbs;
    /** @var list<array{string, mixed}> */
    protected array $postAiVerbs;
    protected array $answerConfig;

    // ── Callbacks ───────────────────────────────────────────────────────
    /** @var callable|null */
    protected $dynamicConfigCallback;
    /** @var callable|null */
    protected $summaryCallback;
    /** @var callable|null */
    protected $debugEventHandler;

    // ── Web / URLs ──────────────────────────────────────────────────────
    protected ?string $webhookUrl;
    protected ?string $postPromptUrl;
    protected array $swaigQueryParams;

    // ── Function includes ───────────────────────────────────────────────
    /** @var list<array> */
    protected array $functionIncludes;

    // ── Session / context / skills ──────────────────────────────────────
    protected SessionManager $sessionManager;
    protected ?ContextBuilder $contextBuilder;
    protected mixed $skillManager;

    // ── Proxy override ──────────────────────────────────────────────────
    protected ?string $manualProxyUrl = null;

    // ── Webhook signature validation ────────────────────────────────────
    /**
     * SignalWire Signing Key for webhook signature validation. When set
     * (explicitly or via SIGNALWIRE_SIGNING_KEY env), POSTs to /, /swaig,
     * and /post_prompt require a valid X-SignalWire-Signature header or
     * are rejected with 403. Read by Service::handleRequest() via
     * property_exists.
     */
    protected ?string $signingKey = null;

    // ══════════════════════════════════════════════════════════════════════
    //  Constructor
    // ══════════════════════════════════════════════════════════════════════

    /**
     * @param array{
     *   name: string,
     *   route?: string,
     *   host?: string,
     *   port?: int,
     *   basic_auth_user?: string,
     *   basic_auth_password?: string,
     *   signing_key?: string,
     * } $options
     */
    public function __construct(array $options)
    {
        parent::__construct($options);

        // Call handling
        $this->autoAnswer   = (bool) ($options['auto_answer'] ?? true);
        $this->recordCall   = (bool) ($options['record_call'] ?? false);
        $this->recordFormat = 'wav';
        $this->recordStereo = false;

        // Prompt / POM
        $this->usePom      = (bool) ($options['use_pom'] ?? true);
        $this->pomSections = [];
        $this->promptText  = '';
        $this->postPrompt  = '';

        // Tools — registry now initialised by Service (parent) property defaults

        // Hints
        $this->hints        = [];
        $this->patternHints = [];

        // Languages / pronunciations
        $this->languages      = [];
        $this->pronunciations = [];

        // Params / data
        $this->params     = [];
        $this->globalData = [];

        // Native functions / fillers / debug
        $this->nativeFunctions  = [];
        $this->internalFillers  = [];
        $this->debugEventsLevel = null;

        // LLM params
        $this->promptLlmParams     = [];
        $this->postPromptLlmParams = [];

        // Verbs
        $this->preAnswerVerbs  = [];
        $this->postAnswerVerbs = [];
        $this->postAiVerbs     = [];
        $this->answerConfig    = [];

        // Callbacks
        $this->dynamicConfigCallback = null;
        $this->summaryCallback       = null;
        $this->debugEventHandler     = null;

        // Web / URLs
        $this->webhookUrl       = null;
        $this->postPromptUrl    = null;
        $this->swaigQueryParams = [];

        // Function includes
        $this->functionIncludes = [];

        // Session / context / skills
        $this->sessionManager = new SessionManager();
        $this->contextBuilder = null;
        $this->skillManager   = null;

        // Webhook signing key: explicit > env. Empty string treated as unset.
        $explicitKey = $options['signing_key'] ?? null;
        if (is_string($explicitKey) && $explicitKey !== '') {
            $this->signingKey = $explicitKey;
        } else {
            $envKey = getenv('SIGNALWIRE_SIGNING_KEY');
            if (is_string($envKey) && $envKey !== '') {
                $this->signingKey = $envKey;
            }
        }

        if ($this->signingKey === null) {
            $this->logger->warn(
                '[signalwire] webhook signature validation is disabled — '
                . 'set signing_key or SIGNALWIRE_SIGNING_KEY to enable'
            );
        }
    }

    /**
     * Get the configured signing key, or null when validation is disabled.
     * Exposed for tooling / tests; do NOT log or echo this value.
     */
    public function getSigningKey(): ?string
    {
        return $this->signingKey;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Prompt Methods
    // ══════════════════════════════════════════════════════════════════════

    public function setPromptText(string $text): self
    {
        $this->promptText = $text;
        return $this;
    }

    public function setPostPrompt(string $text): self
    {
        $this->postPrompt = $text;
        return $this;
    }

    /**
     * Add a top-level POM section with an optional body and bullets.
     */
    public function promptAddSection(string $title, string $body, array $bullets = []): self
    {
        $this->usePom = true;

        $section = [
            'title'  => $title,
            'body'   => $body,
        ];

        if (!empty($bullets)) {
            $section['bullets'] = $bullets;
        }

        $this->pomSections[] = $section;
        return $this;
    }

    /**
     * Add a subsection nested under an existing parent section.
     */
    public function promptAddSubsection(string $parentTitle, string $title, string $body): self
    {
        foreach ($this->pomSections as &$section) {
            if ($section['title'] === $parentTitle) {
                if (!isset($section['subsections'])) {
                    $section['subsections'] = [];
                }
                $section['subsections'][] = [
                    'title' => $title,
                    'body'  => $body,
                ];
                break;
            }
        }
        unset($section);
        return $this;
    }

    /**
     * Append body text and/or bullets to an existing section.
     */
    public function promptAddToSection(string $title, ?string $body = null, array $bullets = []): self
    {
        foreach ($this->pomSections as &$section) {
            if ($section['title'] === $title) {
                if ($body !== null) {
                    $section['body'] = ($section['body'] ?? '') . $body;
                }
                if (!empty($bullets)) {
                    if (!isset($section['bullets'])) {
                        $section['bullets'] = [];
                    }
                    $section['bullets'] = array_merge($section['bullets'], $bullets);
                }
                break;
            }
        }
        unset($section);
        return $this;
    }

    /**
     * Check whether a POM section with the given title exists.
     */
    public function promptHasSection(string $title): bool
    {
        foreach ($this->pomSections as $section) {
            if ($section['title'] === $title) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the prompt payload: POM array if enabled and populated, otherwise raw text.
     *
     * @return array|string
     */
    public function getPrompt(): array|string
    {
        if ($this->usePom && !empty($this->pomSections)) {
            return $this->pomSections;
        }
        return $this->promptText;
    }

    /**
     * Returns the post-prompt text that was set via setPostPrompt, or the
     * empty string when none has been set.
     *
     * Mirrors Python's PromptManager::get_post_prompt /
     * PromptMixin::get_post_prompt — used by SWML rendering when a
     * post-prompt is configured.
     */
    public function getPostPrompt(): string
    {
        return $this->postPrompt;
    }

    /**
     * Returns the raw prompt text whatever setPromptText stored, or the
     * empty string when no raw prompt has been set. Distinct from
     * getPrompt() which returns the POM array when usePom is true.
     *
     * Mirrors Python's PromptManager::get_raw_prompt.
     */
    public function getRawPrompt(): string
    {
        return $this->promptText;
    }

    /**
     * Sets the prompt as a list of POM section arrays. Each section array
     * supports keys "title", "body", "bullets", "numbered",
     * "numbered_bullets", and "subsections". Switches the agent to POM
     * mode.
     *
     * Mirrors Python's PromptManager::set_prompt_pom — accepts a list of
     * section dicts and stores them in pomSections.
     */
    public function setPromptPom(array $pom): self
    {
        $this->usePom      = true;
        $this->pomSections = [];
        foreach ($pom as $section) {
            if (is_array($section)) {
                $this->pomSections[] = $section;
            }
        }
        return $this;
    }

    /**
     * Return the agent's POM as a typed PromptObjectModel instance.
     *
     * Python parity: ``agent.pom`` instance attribute (agent_base.py
     * line 209). Returns ``null`` when ``use_pom`` is false (mirroring
     * Python's ``self.pom = None``).  Otherwise returns a PromptObjectModel
     * built from the agent's stored POM section dicts — sections added via
     * ``promptAddSection`` / ``setPromptPom`` show up as native ``Section``
     * objects so callers can use ``renderMarkdown`` / ``renderXml`` /
     * ``findSection`` etc directly.
     *
     * The returned instance is freshly constructed each call; mutating it
     * does not affect the agent's stored state (Python parity is achieved
     * via construction-from-dicts rather than shared references).
     */
    public function getPom(): ?PromptObjectModel
    {
        if (!$this->usePom) {
            return null;
        }
        if (empty($this->pomSections)) {
            return new PromptObjectModel();
        }
        return PromptObjectModel::fromJson($this->pomSections);
    }

    /**
     * Returns the contexts dictionary as a serialised array, or null when
     * no contexts have been defined yet.
     *
     * Mirrors Python's PromptManager::get_contexts which returns the
     * contexts dict or None.
     */
    public function getContexts(): ?array
    {
        if ($this->contextBuilder === null) {
            return null;
        }
        return $this->contextBuilder->toArray();
    }

    // Tool methods (defineTool, registerSwaigFunction, defineTools, onFunctionCall)
    // are now provided by SignalWire\SWML\Service - inherited via parent.

    /**
     * Mint a per-call SWAIG-function token via the agent's SessionManager.
     *
     * Python parity: state_mixin.StateMixin._create_tool_token —
     * delegates to SessionManager::createToolToken and returns "" on
     * any thrown error (Python catches all exceptions and returns "").
     */
    public function createToolToken(string $toolName, string $callId): string
    {
        try {
            return $this->sessionManager->createToolToken($toolName, $callId);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Validate a per-call SWAIG-function token. Returns false when the
     * function is not registered, when the SessionManager rejects the
     * token, or on any underlying exception.
     *
     * Python parity: state_mixin.StateMixin.validate_tool_token —
     * rejects unknown function names up-front and swallows exceptions.
     */
    public function validateToolToken(string $functionName, string $token, string $callId): bool
    {
        if (!$this->hasFunction($functionName)) {
            return false;
        }
        try {
            return $this->sessionManager->validateToolToken($functionName, $token, $callId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  AI Config Methods
    // ══════════════════════════════════════════════════════════════════════

    public function addHint(string $hint): self
    {
        $this->hints[] = $hint;
        return $this;
    }

    public function addHints(array $hints): self
    {
        foreach ($hints as $hint) {
            $this->hints[] = (string) $hint;
        }
        return $this;
    }

    public function addPatternHint(string $pattern): self
    {
        $this->patternHints[] = $pattern;
        return $this;
    }

    public function addLanguage(string $name, string $code, string $voice): self
    {
        $this->languages[] = [
            'name'  => $name,
            'code'  => $code,
            'voice' => $voice,
        ];
        return $this;
    }

    public function setLanguages(array $languages): self
    {
        $this->languages = $languages;
        return $this;
    }

    public function addPronunciation(string $replace, string $with, string $ignore = ''): self
    {
        $entry = [
            'replace' => $replace,
            'with'    => $with,
        ];
        if ($ignore !== '') {
            $entry['ignore'] = $ignore;
        }
        $this->pronunciations[] = $entry;
        return $this;
    }

    public function setPronunciations(array $pronunciations): self
    {
        $this->pronunciations = $pronunciations;
        return $this;
    }

    public function setParam(string $key, mixed $value): self
    {
        $this->params[$key] = $value;
        return $this;
    }

    public function setParams(array $params): self
    {
        $this->params = $params;
        return $this;
    }

    public function setGlobalData(array $data): self
    {
        $this->globalData = $data;
        return $this;
    }

    public function updateGlobalData(array $data): self
    {
        $this->globalData = array_merge($this->globalData, $data);
        return $this;
    }

    public function setNativeFunctions(array $functions): self
    {
        $this->nativeFunctions = $functions;
        return $this;
    }

    /**
     * The complete set of internal SWAIG function names that accept
     * fillers, matching the SWAIGInternalFiller schema definition.
     *
     * Any name outside this set is silently ignored by the runtime —
     * setInternalFillers() and addInternalFiller() warn if you pass
     * an unknown name.
     *
     * Notable absences: change_step, gather_submit, and arbitrary
     * user-defined SWAIG function names are NOT supported.
     */
    public const SUPPORTED_INTERNAL_FILLER_NAMES = [
        'hangup',                   // AI is hanging up the call
        'check_time',               // AI is checking the time
        'wait_for_user',            // AI is waiting for user input
        'wait_seconds',             // deliberate pause / wait period
        'adjust_response_latency',  // AI is adjusting response timing
        'next_step',                // transitioning between steps in prompt.contexts
        'change_context',           // switching between contexts in prompt.contexts
        'get_visual_input',         // processing visual input (enable_vision)
        'get_ideal_strategy',       // thinking (enable_thinking)
    ];

    /**
     * Set internal fillers for native SWAIG functions.
     *
     * Internal fillers are short phrases the AI agent speaks (via
     * TTS) while an internal/native function is running, so the
     * caller doesn't hear dead air during transitions or background
     * work.
     *
     * Supported function names (match the SWAIGInternalFiller
     * schema): hangup, check_time, wait_for_user, wait_seconds,
     * adjust_response_latency, next_step, change_context,
     * get_visual_input, get_ideal_strategy. See
     * self::SUPPORTED_INTERNAL_FILLER_NAMES.
     *
     * Notably NOT supported: change_step, gather_submit, or arbitrary
     * user-defined SWAIG function names. The runtime only honors
     * fillers for the names listed above; everything else is
     * silently ignored at the SWML level. This method warns at
     * registration time if you pass an unknown name so you catch the
     * typo early.
     *
     * Expected format: ['function_name' => ['language_code' => ['phrase', ...]]]
     */
    public function setInternalFillers(array $fillers): self
    {
        $unknown = array_values(array_diff(
            array_keys($fillers),
            self::SUPPORTED_INTERNAL_FILLER_NAMES
        ));
        if (!empty($unknown)) {
            sort($unknown);
            $unknownStr = "['" . implode("', '", $unknown) . "']";
            $supported = self::SUPPORTED_INTERNAL_FILLER_NAMES;
            sort($supported);
            $supportedStr = "['" . implode("', '", $supported) . "']";
            $this->logger->warn(
                "unknown_internal_filler_names: {$unknownStr}. "
                . "setInternalFillers received names that the SWML schema "
                . "does not recognize. Those entries will be ignored by "
                . "the runtime. Supported names: {$supportedStr}."
            );
        }
        $this->internalFillers = $fillers;
        return $this;
    }

    /**
     * Add a single internal filler entry.
     *
     * Two calling conventions are supported for backward
     * compatibility:
     *
     *   $agent->addInternalFiller('plain text')  // legacy
     *   $agent->addInternalFiller($functionName, $languageCode, $fillers)
     *
     * See setInternalFillers() for the complete list of supported
     * function names and what fillers do. Names outside the supported
     * set log a warning.
     */
    public function addInternalFiller(string $filler_or_function, ?string $languageCode = null, ?array $fillers = null): self
    {
        if ($languageCode === null || $fillers === null) {
            // Legacy: single string argument.
            $this->internalFillers[] = $filler_or_function;
            return $this;
        }

        $functionName = $filler_or_function;
        if (!in_array($functionName, self::SUPPORTED_INTERNAL_FILLER_NAMES, true)) {
            $supported = self::SUPPORTED_INTERNAL_FILLER_NAMES;
            sort($supported);
            $supportedStr = "['" . implode("', '", $supported) . "']";
            $this->logger->warn(
                "unknown_internal_filler_name: '{$functionName}'. "
                . "addInternalFiller received a function name the SWML "
                . "schema does not recognize. The entry will be stored "
                . "but the runtime will not play these fillers. "
                . "Supported names: {$supportedStr}."
            );
        }
        if (!isset($this->internalFillers[$functionName])
            || !is_array($this->internalFillers[$functionName])) {
            $this->internalFillers[$functionName] = [];
        }
        $this->internalFillers[$functionName][$languageCode] = $fillers;
        return $this;
    }

    public function enableDebugEvents(string $level = 'all'): self
    {
        $this->debugEventsLevel = $level;
        return $this;
    }

    public function addFunctionInclude(array $include): self
    {
        $this->functionIncludes[] = $include;
        return $this;
    }

    public function setFunctionIncludes(array $includes): self
    {
        $this->functionIncludes = $includes;
        return $this;
    }

    public function setPromptLlmParams(array $params): self
    {
        $this->promptLlmParams = $params;
        return $this;
    }

    public function setPostPromptLlmParams(array $params): self
    {
        $this->postPromptLlmParams = $params;
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Verb Methods
    // ══════════════════════════════════════════════════════════════════════

    public function addPreAnswerVerb(string $verb, mixed $config): self
    {
        $this->preAnswerVerbs[] = [$verb, $config];
        return $this;
    }

    public function addPostAnswerVerb(string $verb, mixed $config): self
    {
        $this->postAnswerVerbs[] = [$verb, $config];
        return $this;
    }

    /**
     * Alias for addPostAnswerVerb().
     */
    public function addAnswerVerb(string $verb, mixed $config): self
    {
        return $this->addPostAnswerVerb($verb, $config);
    }

    public function addPostAiVerb(string $verb, mixed $config): self
    {
        $this->postAiVerbs[] = [$verb, $config];
        return $this;
    }

    public function clearPreAnswerVerbs(): self
    {
        $this->preAnswerVerbs = [];
        return $this;
    }

    public function clearPostAnswerVerbs(): self
    {
        $this->postAnswerVerbs = [];
        return $this;
    }

    public function clearPostAiVerbs(): self
    {
        $this->postAiVerbs = [];
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Context Methods
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Return the ContextBuilder, creating it lazily on first access.
     *
     * The builder is attached to this agent so validate() can check
     * user-defined tool names against reserved native tool names
     * (next_step, change_context, gather_submit).
     */
    public function defineContexts(): ContextBuilder
    {
        if ($this->contextBuilder === null) {
            $this->contextBuilder = new ContextBuilder();
            $this->contextBuilder->attachToolNameSupplier(function (): array {
                return $this->listToolNames();
            });
        }
        return $this->contextBuilder;
    }

    /**
     * Return the names of every registered SWAIG tool in insertion
     * order. Used by ContextBuilder::validate() to detect collisions
     * with reserved native tool names.
     *
     * @return string[]
     */
    public function listToolNames(): array
    {
        return $this->toolOrder;
    }

    /**
     * Alias for defineContexts().
     */
    public function contexts(): ContextBuilder
    {
        return $this->defineContexts();
    }

    /**
     * Remove all contexts, returning the agent to a no-contexts state.
     * This is a convenience wrapper around defineContexts()->reset().
     * Use it in a dynamic config callback when you need to rebuild
     * contexts from scratch for a specific request.
     */
    public function resetContexts(): self
    {
        if ($this->contextBuilder !== null) {
            $this->contextBuilder->reset();
        }
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Skill Methods
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Get or create the SkillManager (lazy init).
     */
    protected function getSkillManager(): SkillManager
    {
        if ($this->skillManager === null) {
            $this->skillManager = new SkillManager($this);
        }
        return $this->skillManager;
    }

    /**
     * Add a skill by name.
     */
    public function addSkill(string $name, array $params = []): self
    {
        $this->getSkillManager()->loadSkill($name, $params);
        return $this;
    }

    public function removeSkill(string $name): self
    {
        $this->getSkillManager()->unloadSkill($name);
        return $this;
    }

    public function listSkills(): array
    {
        if ($this->skillManager === null) {
            return [];
        }
        return $this->skillManager->listSkills();
    }

    public function hasSkill(string $name): bool
    {
        if ($this->skillManager === null) {
            return false;
        }
        return $this->skillManager->hasSkill($name);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Web / Callback Methods
    // ══════════════════════════════════════════════════════════════════════

    public function setDynamicConfigCallback(callable $callback): self
    {
        $this->dynamicConfigCallback = $callback;
        return $this;
    }

    /**
     * Returns the dynamic-config callback (or null if none has been set).
     * Used by request handlers to invoke the callback before SWML
     * rendering so the agent can be reshaped per-request.
     */
    public function getDynamicConfigCallback(): ?callable
    {
        return $this->dynamicConfigCallback;
    }

    public function setWebHookUrl(string $url): self
    {
        $this->webhookUrl = $url;
        return $this;
    }

    public function setPostPromptUrl(string $url): self
    {
        $this->postPromptUrl = $url;
        return $this;
    }

    /**
     * Manually override the proxy URL used for SWAIG webhook construction.
     */
    public function manualSetProxyUrl(string $url): self
    {
        $this->manualProxyUrl = rtrim($url, '/');
        return $this;
    }

    public function addSwaigQueryParams(array $params): self
    {
        $this->swaigQueryParams = array_merge($this->swaigQueryParams, $params);
        return $this;
    }

    public function clearSwaigQueryParams(): self
    {
        $this->swaigQueryParams = [];
        return $this;
    }

    public function onSummary(callable $callback): self
    {
        $this->summaryCallback = $callback;
        return $this;
    }

    public function onDebugEvent(callable $callback): self
    {
        $this->debugEventHandler = $callback;
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SIP Methods
    // ══════════════════════════════════════════════════════════════════════

    public function enableSipRouting(): self
    {
        $this->setParam('sip_routing', true);
        return $this;
    }

    public function registerSipUsername(string $username, string $route = ''): self
    {
        $this->setParam('sip_username', $username);
        if ($route !== '') {
            $this->setParam('sip_route', $route);
        }
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SWML Rendering
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Build the complete SWML document for a request.
     *
     * Phases:
     *   1. Pre-answer verbs
     *   2. Answer verb (if autoAnswer)
     *   3. Record call verb (if recordCall)
     *   4. Post-answer verbs
     *   5. AI verb
     *   6. Post-AI verbs
     *
     * @return array The SWML document array.
     */
    public function renderSwml(?array $requestBody = null, array $headers = []): array
    {
        $main = [];

        // 1. Pre-answer verbs
        foreach ($this->preAnswerVerbs as [$verb, $config]) {
            $main[] = [$verb => $config];
        }

        // 2. Answer verb
        if ($this->autoAnswer) {
            $answerParams = array_merge(['max_duration' => 14400], $this->answerConfig);
            $main[] = ['answer' => $answerParams];
        }

        // 3. Record call verb
        if ($this->recordCall) {
            $main[] = ['record_call' => [
                'format' => $this->recordFormat,
                'stereo' => $this->recordStereo,
            ]];
        }

        // 4. Post-answer verbs
        foreach ($this->postAnswerVerbs as [$verb, $config]) {
            $main[] = [$verb => $config];
        }

        // 5. AI verb
        $main[] = ['ai' => $this->buildAiVerb($headers)];

        // 6. Post-AI verbs
        foreach ($this->postAiVerbs as [$verb, $config]) {
            $main[] = [$verb => $config];
        }

        return [
            'version'  => '1.0.0',
            'sections' => [
                'main' => $main,
            ],
        ];
    }

    /**
     * Build the AI verb configuration block.
     */
    public function buildAiVerb(array $headers = []): array
    {
        $ai = [];

        // ── Prompt ──────────────────────────────────────────────────────
        $prompt = [];
        if ($this->usePom && !empty($this->pomSections)) {
            $prompt['pom'] = $this->pomSections;
        } else {
            $prompt['text'] = $this->promptText;
        }
        if (!empty($this->promptLlmParams)) {
            $prompt = array_merge($prompt, $this->promptLlmParams);
        }
        $ai['prompt'] = $prompt;

        // ── Post prompt ─────────────────────────────────────────────────
        if ($this->postPrompt !== '') {
            $postPromptBlock = ['text' => $this->postPrompt];
            if (!empty($this->postPromptLlmParams)) {
                $postPromptBlock = array_merge($postPromptBlock, $this->postPromptLlmParams);
            }
            $ai['post_prompt'] = $postPromptBlock;
        }

        // ── Post prompt URL ─────────────────────────────────────────────
        if ($this->postPromptUrl !== null) {
            $ai['post_prompt_url'] = $this->postPromptUrl;
        } else {
            $proxyBase = $this->resolveProxyBase($headers);
            $routeSegment = $this->route === '/' ? '' : $this->route;
            $ai['post_prompt_url'] = $proxyBase . $routeSegment . '/post_prompt';
        }

        // ── Params ──────────────────────────────────────────────────────
        $mergedParams = $this->params;
        if (!empty($this->internalFillers)) {
            $mergedParams['internal_fillers'] = $this->internalFillers;
        }
        if ($this->debugEventsLevel !== null) {
            $mergedParams['debug_events'] = $this->debugEventsLevel;
        }
        if (!empty($mergedParams)) {
            $ai['params'] = $mergedParams;
        }

        // ── Hints ───────────────────────────────────────────────────────
        $allHints = array_merge($this->hints, $this->patternHints);
        if (!empty($allHints)) {
            $ai['hints'] = $allHints;
        }

        // ── Languages ───────────────────────────────────────────────────
        if (!empty($this->languages)) {
            $ai['languages'] = $this->languages;
        }

        // ── Pronunciations ──────────────────────────────────────────────
        if (!empty($this->pronunciations)) {
            $ai['pronounce'] = $this->pronunciations;
        }

        // ── SWAIG ──────────────────────────────────────────────────────
        $swaig = $this->buildSwaigBlock($headers);
        if (!empty($swaig)) {
            $ai['SWAIG'] = $swaig;
        }

        // ── Global data ─────────────────────────────────────────────────
        if (!empty($this->globalData)) {
            $ai['global_data'] = $this->globalData;
        }

        // ── Context switch ──────────────────────────────────────────────
        if ($this->contextBuilder !== null) {
            $contextArray = $this->contextBuilder->toArray();
            if (!empty($contextArray)) {
                $ai['context_switch'] = $contextArray;
            }
        }

        return $ai;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  HTTP Overrides
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Handle the SWML document request.
     *
     * If a dynamic-config callback is registered, clone the agent, pass the
     * clone to the callback for customisation, and render from the clone.
     */
    protected function handleSwmlRequest(string $method, ?array $requestData, array $headers): array
    {
        if ($this->dynamicConfigCallback !== null) {
            $clone = $this->cloneForRequest();

            $queryParams = [];
            if (isset($requestData['query_params'])) {
                $queryParams = (array) $requestData['query_params'];
            }

            ($this->dynamicConfigCallback)($queryParams, $requestData, $headers, $clone);

            $swml = $clone->renderSwml($requestData, $headers);
            return $this->jsonResponse(200, $swml);
        }

        $swml = $this->renderSwml($requestData, $headers);
        return $this->jsonResponse(200, $swml);
    }

    // handleSwaigRequest is now provided by Service (parent). The lifted
    // version handles GET (renders SWML) and POST (dispatches via onFunctionCall).

    /**
     * Handle the post-prompt callback.
     */
    protected function handlePostPrompt(?array $requestData, array $headers): array
    {
        if ($this->summaryCallback !== null && $requestData !== null) {
            $summary = $requestData['post_prompt_data']['raw'] ?? $requestData['summary'] ?? '';

            ($this->summaryCallback)($summary, $requestData, $headers);
        }

        return $this->jsonResponse(200, ['status' => 'ok']);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Clone
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Create a deep copy of this agent for per-request customisation.
     *
     * Arrays and objects are deeply copied; callbacks are preserved by reference.
     */
    public function cloneForRequest(): static
    {
        $clone = clone $this;

        // Deep copy arrays
        $clone->pomSections        = $this->deepCopyArray($this->pomSections);
        $clone->tools              = $this->deepCopyArray($this->tools);
        $clone->toolOrder          = $this->toolOrder;
        $clone->hints              = $this->hints;
        $clone->patternHints       = $this->patternHints;
        $clone->languages          = $this->deepCopyArray($this->languages);
        $clone->pronunciations     = $this->deepCopyArray($this->pronunciations);
        $clone->params             = $this->deepCopyArray($this->params);
        $clone->globalData         = $this->deepCopyArray($this->globalData);
        $clone->nativeFunctions    = $this->nativeFunctions;
        $clone->internalFillers    = $this->internalFillers;
        $clone->promptLlmParams    = $this->deepCopyArray($this->promptLlmParams);
        $clone->postPromptLlmParams = $this->deepCopyArray($this->postPromptLlmParams);
        $clone->preAnswerVerbs     = $this->deepCopyArray($this->preAnswerVerbs);
        $clone->postAnswerVerbs    = $this->deepCopyArray($this->postAnswerVerbs);
        $clone->postAiVerbs        = $this->deepCopyArray($this->postAiVerbs);
        $clone->answerConfig       = $this->deepCopyArray($this->answerConfig);
        $clone->swaigQueryParams   = $this->deepCopyArray($this->swaigQueryParams);
        $clone->functionIncludes   = $this->deepCopyArray($this->functionIncludes);

        // Deep-copy objects
        $clone->sessionManager = clone $this->sessionManager;
        if ($this->contextBuilder !== null) {
            $clone->contextBuilder = clone $this->contextBuilder;
        }

        // Callbacks preserved by reference (not cloned)
        $clone->dynamicConfigCallback = $this->dynamicConfigCallback;
        $clone->summaryCallback       = $this->summaryCallback;
        $clone->debugEventHandler     = $this->debugEventHandler;

        return $clone;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Private / Internal Helpers
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Build the SWAIG block for the AI verb.
     */
    private function buildSwaigBlock(array $headers): array
    {
        $swaig = [];

        // Functions
        $functions = [];
        foreach ($this->toolOrder as $name) {
            if (!isset($this->tools[$name])) {
                continue;
            }

            $tool = $this->tools[$name];

            // Strip internal keys
            $funcDef = array_filter($tool, fn(string $key): bool => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);

            // Add web_hook_url for callable tools (those with a handler)
            if (isset($tool['_handler'])) {
                $funcDef['web_hook_url'] = $this->buildSwaigWebhookUrl($headers);
            }

            $functions[] = $funcDef;
        }
        if (!empty($functions)) {
            $swaig['functions'] = $functions;
        }

        // Native functions
        if (!empty($this->nativeFunctions)) {
            $swaig['native_functions'] = $this->nativeFunctions;
        }

        // Includes
        if (!empty($this->functionIncludes)) {
            $swaig['includes'] = $this->functionIncludes;
        }

        return $swaig;
    }

    /**
     * Build the authenticated SWAIG webhook URL with query params.
     */
    private function buildSwaigWebhookUrl(array $headers): string
    {
        $proxyBase = $this->resolveProxyBase($headers);
        $routeSegment = $this->route === '/' ? '' : $this->route;

        // Build the URL with embedded basic auth
        $parsed = parse_url($proxyBase);
        $scheme = $parsed['scheme'] ?? 'http';
        $host   = $parsed['host'] ?? $this->host;
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path   = $parsed['path'] ?? '';

        $authUrl = "{$scheme}://{$this->basicAuthUser}:{$this->basicAuthPassword}@{$host}{$port}{$path}{$routeSegment}/swaig";

        // Append query params
        if (!empty($this->swaigQueryParams)) {
            $authUrl .= '?' . http_build_query($this->swaigQueryParams);
        }

        return $authUrl;
    }

    /**
     * Resolve the proxy URL base, preferring manual override.
     */
    private function resolveProxyBase(array $headers): string
    {
        if ($this->manualProxyUrl !== null) {
            return $this->manualProxyUrl;
        }
        return $this->getProxyUrlBase($headers);
    }

    /**
     * Recursively deep-copy an array (handles nested arrays).
     */
    private function deepCopyArray(array $array): array
    {
        $copy = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $copy[$key] = $this->deepCopyArray($value);
            } elseif (is_object($value)) {
                $copy[$key] = clone $value;
            } else {
                $copy[$key] = $value;
            }
        }
        return $copy;
    }
}
