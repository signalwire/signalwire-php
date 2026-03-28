<?php

declare(strict_types=1);

namespace SignalWire\Agent;

use SignalWire\SWML\Service;
use SignalWire\SWML\Schema;
use SignalWire\Logging\Logger;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\Security\SessionManager;
use SignalWire\Contexts\ContextBuilder;

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
    /** @var array<string, array> */
    protected array $tools;
    /** @var list<string> */
    protected array $toolOrder;

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

        // Tools
        $this->tools     = [];
        $this->toolOrder = [];

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

    // ══════════════════════════════════════════════════════════════════════
    //  Tool Methods
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Define a tool with a callable handler.
     */
    public function defineTool(
        string $name,
        string $description,
        array $parameters,
        callable $handler,
        bool $secure = false,
    ): self {
        $this->tools[$name] = [
            'function'  => $name,
            'purpose'   => $description,
            'argument'  => [
                'type'       => 'object',
                'properties' => $parameters,
            ],
            '_handler' => $handler,
            '_secure'  => $secure,
        ];

        if (!in_array($name, $this->toolOrder, true)) {
            $this->toolOrder[] = $name;
        }

        return $this;
    }

    /**
     * Register a raw SWAIG function definition (e.g. DataMap tools).
     */
    public function registerSwaigFunction(array $funcDef): self
    {
        $name = $funcDef['function'] ?? '';
        if ($name === '') {
            return $this;
        }

        $this->tools[$name] = $funcDef;

        if (!in_array($name, $this->toolOrder, true)) {
            $this->toolOrder[] = $name;
        }

        return $this;
    }

    /**
     * Register multiple tool definitions at once.
     */
    public function defineTools(array $toolDefs): self
    {
        foreach ($toolDefs as $def) {
            $this->registerSwaigFunction($def);
        }
        return $this;
    }

    /**
     * Dispatch a function call to the registered handler.
     */
    public function onFunctionCall(string $name, array $args, array $rawData): ?FunctionResult
    {
        if (!isset($this->tools[$name])) {
            return null;
        }

        $tool = $this->tools[$name];
        $handler = $tool['_handler'] ?? null;

        if ($handler === null || !is_callable($handler)) {
            return null;
        }

        $result = $handler($args, $rawData);

        if ($result instanceof FunctionResult) {
            return $result;
        }

        return null;
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

    public function setInternalFillers(array $fillers): self
    {
        $this->internalFillers = $fillers;
        return $this;
    }

    public function addInternalFiller(string $filler): self
    {
        $this->internalFillers[] = $filler;
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
     */
    public function defineContexts(): ContextBuilder
    {
        if ($this->contextBuilder === null) {
            $this->contextBuilder = new ContextBuilder();
        }
        return $this->contextBuilder;
    }

    /**
     * Alias for defineContexts().
     */
    public function contexts(): ContextBuilder
    {
        return $this->defineContexts();
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Skill Methods
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Add a skill by name (stub -- delegates to skill manager when available).
     */
    public function addSkill(string $name, array $params = []): self
    {
        if ($this->skillManager !== null && is_object($this->skillManager) && method_exists($this->skillManager, 'add')) {
            $this->skillManager->add($name, $params);
        }
        return $this;
    }

    public function removeSkill(string $name): self
    {
        if ($this->skillManager !== null && is_object($this->skillManager) && method_exists($this->skillManager, 'remove')) {
            $this->skillManager->remove($name);
        }
        return $this;
    }

    public function listSkills(): array
    {
        if ($this->skillManager !== null && is_object($this->skillManager) && method_exists($this->skillManager, 'list')) {
            return $this->skillManager->list();
        }
        return [];
    }

    public function hasSkill(string $name): bool
    {
        if ($this->skillManager !== null && is_object($this->skillManager) && method_exists($this->skillManager, 'has')) {
            return $this->skillManager->has($name);
        }
        return false;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Web / Callback Methods
    // ══════════════════════════════════════════════════════════════════════

    public function setDynamicConfigCallback(callable $callback): self
    {
        $this->dynamicConfigCallback = $callback;
        return $this;
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

    /**
     * Handle a SWAIG function dispatch request.
     */
    protected function handleSwaigRequest(?array $requestData, array $headers): array
    {
        if ($requestData === null) {
            return $this->jsonResponse(400, ['error' => 'Missing request body']);
        }

        $functionName = $requestData['function'] ?? '';
        if ($functionName === '') {
            return $this->jsonResponse(400, ['error' => 'Missing function name']);
        }

        $args = $requestData['argument']['parsed'][0] ?? [];

        $result = $this->onFunctionCall($functionName, $args, $requestData);

        if ($result === null) {
            return $this->jsonResponse(404, ['error' => "Unknown function: {$functionName}"]);
        }

        return $this->jsonResponse(200, $result->toArray());
    }

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
