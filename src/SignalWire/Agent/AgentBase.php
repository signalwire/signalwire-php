<?php

declare(strict_types=1);

namespace SignalWire\Agent;

use SignalWire\Contexts\ContextBuilder;
use SignalWire\Core\ConfigLoader;
use SignalWire\POM\PromptObjectModel;
use SignalWire\Security\SessionManager;
use SignalWire\Skills\SkillManager;
use SignalWire\Skills\SkillName;
use SignalWire\SWML\Schema;
use SignalWire\SWML\Service;

/**
 * @phpstan-type PomSection array{
 *   title: string,
 *   body?: string,
 *   bullets?: list<string>,
 *   numbered?: bool,
 *   numberedBullets?: bool,
 *   subsections?: list<array<string, mixed>>
 * }
 */
class AgentBase extends Service implements AgentInterface
{
    // ── Call handling ────────────────────────────────────────────────────
    protected bool $autoAnswer;
    protected bool $recordCall;
    protected string $recordFormat;
    protected bool $recordStereo;

    // ── Prompt / POM ────────────────────────────────────────────────────
    protected bool $usePom;
    /** @var list<PomSection> */
    protected array $pomSections;
    protected string $promptText;
    protected string $postPrompt;

    // ── Tools / SWAIG ───────────────────────────────────────────────────
    // $tools and $toolOrder are now declared on Service (lifted so non-agent
    // SWMLService instances can host SWAIG functions). AgentBase inherits.

    // ── Hints ───────────────────────────────────────────────────────────
    /** @var list<string> */
    protected array $hints;
    /**
     * Structured pattern hints. Each entry mirrors Python's
     * ``AIConfigMixin.add_pattern_hint`` payload
     * ``{hint, pattern, replace, ignore_case}`` and is merged into the
     * rendered ``ai.hints`` array alongside the bare-string hints.
     *
     * @var list<array<string,mixed>>
     */
    protected array $patternHints;

    // ── Languages / pronunciations ──────────────────────────────────────
    /**
     * Configured languages. Entries added via addLanguage() carry
     * name/code/voice (+ optional params), but setLanguages() accepts
     * arbitrary caller-supplied dicts, so the element shape is open.
     *
     * @var list<array<string,mixed>>
     */
    protected array $languages;
    /**
     * ASR-driven multilingual (Mode B) config, emitted as the top-level
     * `multilingual` object on the AI verb. See setMultilingual().
     *
     * @var array<string,mixed>
     */
    protected array $multilingual;
    /** @var list<array{replace: string, with: string, ignore_case?: bool}> */
    protected array $pronunciations;

    // ── Params / data ───────────────────────────────────────────────────
    /** @var array<string,mixed> */
    protected array $params;
    /** @var array<string,mixed> */
    protected array $globalData;

    // ── Native functions / fillers / debug ───────────────────────────────
    /** @var list<string> */
    protected array $nativeFunctions;
    /**
     * Internal fillers. The legacy single-arg path appends bare filler
     * strings (int-keyed); the structured path keys a per-function map of
     * languageCode => fillers. The shape is therefore mixed.
     *
     * @var array<int|string, mixed>
     */
    protected array $internalFillers;
    protected ?int $debugEventsLevel;

    // ── LLM params ──────────────────────────────────────────────────────
    /** @var array<string,mixed> */
    protected array $promptLlmParams;
    /** @var array<string,mixed> */
    protected array $postPromptLlmParams;

    // ── SIP routing ─────────────────────────────────────────────────────
    /**
     * SIP usernames registered to this agent (lowercased), the consultable
     * set the SIP routing callback checks. Mirrors Python
     * `AgentBase._sip_usernames` (a set). Keyed by lowercase username → true.
     *
     * @var array<string, bool>
     */
    protected array $sipUsernames = [];

    // ── Verbs ───────────────────────────────────────────────────────────
    /** @var list<array{string, array<string,mixed>}> */
    protected array $preAnswerVerbs;
    /** @var list<array{string, array<string,mixed>}> */
    protected array $postAnswerVerbs;
    /** @var list<array{string, array<string,mixed>}> */
    protected array $postAiVerbs;
    /** @var array<string,mixed> */
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
    /** @var array<string,string> */
    protected array $swaigQueryParams;

    // ── Function includes ───────────────────────────────────────────────
    /** @var list<array<string, mixed>> */
    protected array $functionIncludes;

    // ── MCP servers ─────────────────────────────────────────────────────
    /** @var list<array<string, mixed>> External MCP servers for tool discovery. */
    protected array $mcpServers = [];
    /** Whether this agent exposes its tools as an MCP server endpoint. */
    protected bool $mcpServerEnabled = false;

    // ── Session / context / skills ──────────────────────────────────────
    protected SessionManager $sessionManager;
    protected ?ContextBuilder $contextBuilder;
    protected ?SkillManager $skillManager;

    // ── Proxy override ──────────────────────────────────────────────────
    // $manualProxyUrl + manualSetProxyUrl() now live on the base Service
    // (SWMLService parity); AgentBase inherits both.

    // ── Webhook signature validation ────────────────────────────────────
    /**
     * SignalWire Signing Key for webhook signature validation. When set
     * (explicitly or via SIGNALWIRE_SIGNING_KEY env), POSTs to /, /swaig,
     * and /post_prompt require a valid X-SignalWire-Signature header or
     * are rejected with 403. Read by Service::handleRequest() via
     * property_exists.
     */
    protected ?string $signingKey = null;

    // $trustProxyForSignature lives on the base Service (where
    // reconstructPublicUrl() reads it); AgentBase supplies it via the
    // constructor, mirroring Python AgentBase's `_trust_proxy_for_signature`.

    // ── Identity / construction-time state ──────────────────────────────
    /**
     * Unique ID for this agent — the constructor's `agentId`, or a generated
     * UUIDv4. Mirrors Python AgentBase's public `self.agent_id`.
     */
    protected string $agentId = '';

    /**
     * Default webhook URL for all SWAIG functions, as supplied at
     * construction. Mirrors Python AgentBase's `_default_webhook_url`.
     */
    protected ?string $defaultWebhookUrl = null;

    /**
     * Whether structured request logs are suppressed. Mirrors Python
     * AgentBase's `_suppress_logs`.
     */
    protected bool $suppressLogs = false;

    /**
     * Post-prompt override flag, as supplied at construction. Mirrors Python
     * AgentBase's `enable_post_prompt_override` constructor parameter.
     */
    protected bool $enablePostPromptOverride = false;

    /**
     * Check-for-input override flag, as supplied at construction. Mirrors
     * Python AgentBase's `check_for_input_override` constructor parameter.
     */
    protected bool $checkForInputOverride = false;

    // ══════════════════════════════════════════════════════════════════════
    //  Constructor
    // ══════════════════════════════════════════════════════════════════════

    /**
     * @param string $name
     * @param string $route
     * @param string|null $host
     * @param int|null $port
     * @param string|null $basicAuthUser
     * @param string|null $basicAuthPassword
     * @param bool $autoAnswer
     * @param bool $recordCall
     * @param bool $usePom
     * @param string|null $signingKey  Webhook signing key (falls back to
     *   SIGNALWIRE_SIGNING_KEY env). When null, signature validation is
     *   disabled and a startup warning is logged.
     * @param int $tokenExpirySecs Seconds until per-call SWAIG tokens expire.
     *   Forwarded to the agent's SessionManager.
     * @param string $recordFormat Recording format for the `record_call` verb.
     * @param bool $recordStereo Whether to record in stereo.
     * @param string|null $defaultWebhookUrl Optional default webhook URL for
     *   all SWAIG functions.
     * @param string|null $agentId Unique ID for this agent; a UUIDv4 is
     *   generated when omitted.
     * @param list<string>|null $nativeFunctions Native functions to advertise
     *   in the rendered SWAIG object.
     * @param string|null $schemaPath Optional path to a SWML schema.json.
     *   Forwarded to the SWMLService base -> SchemaUtils.
     * @param bool $suppressLogs Whether to suppress structured request logs.
     * @param bool $enablePostPromptOverride Whether to enable post-prompt
     *   override.
     * @param bool $checkForInputOverride Whether to enable check-for-input
     *   override.
     * @param string|null $configFile Optional path to a JSON configuration
     *   file. Forwarded to the SWMLService base -> SecurityConfig, and its
     *   `service` section supplies route/host/port/name defaults that explicit
     *   constructor arguments override.
     * @param bool $schemaValidation Enable SWML schema validation (default
     *   true). Forwarded to the SWMLService base -> SchemaUtils; can also be
     *   disabled via SWML_SKIP_SCHEMA_VALIDATION=1.
     * @param bool $trustProxyForSignature Honour X-Forwarded-Proto /
     *   X-Forwarded-Host when reconstructing the URL during webhook signature
     *   validation. Default false — proxy headers are spoofable, so opt in
     *   only when you control the proxy chain.
     */
    public function __construct(
        string $name,
        string $route = '/',
        ?string $host = null,
        ?int $port = null,
        ?string $basicAuthUser = null,
        ?string $basicAuthPassword = null,
        bool $autoAnswer = true,
        bool $recordCall = false,
        bool $usePom = true,
        ?string $signingKey = null,
        int $tokenExpirySecs = 3600,
        string $recordFormat = 'mp4',
        bool $recordStereo = true,
        ?string $defaultWebhookUrl = null,
        ?string $agentId = null,
        ?array $nativeFunctions = null,
        ?string $schemaPath = null,
        bool $suppressLogs = false,
        bool $enablePostPromptOverride = false,
        bool $checkForInputOverride = false,
        ?string $configFile = null,
        bool $schemaValidation = true,
        bool $trustProxyForSignature = false,
    ) {
        // Load the config file's `service` section BEFORE constructing the
        // base, so its route/host/port/name become defaults that an explicit
        // constructor argument still overrides. Mirrors Python
        // AgentBase.__init__'s `_load_service_config` + "constructor
        // parameters taking precedence" block (agent_base.py:186-208).
        $serviceConfig = self::loadServiceConfig($configFile, $name);

        $finalRoute = $route !== '/'
            ? $route
            : (is_string($serviceConfig['route'] ?? null) ? $serviceConfig['route'] : $route);
        $finalHost = $host ?? (is_string($serviceConfig['host'] ?? null)
            ? $serviceConfig['host']
            : null);
        $finalPort = $port ?? (is_int($serviceConfig['port'] ?? null)
            ? $serviceConfig['port']
            : (is_numeric($serviceConfig['port'] ?? null) ? (int) $serviceConfig['port'] : null));
        $finalName = is_string($serviceConfig['name'] ?? null) && $serviceConfig['name'] !== ''
            ? $serviceConfig['name']
            : $name;

        parent::__construct(
            name: $finalName,
            route: $finalRoute,
            host: $finalHost,
            port: $finalPort,
            basicAuthUser: $basicAuthUser,
            basicAuthPassword: $basicAuthPassword,
            schemaPath: $schemaPath,
            configFile: $configFile,
            schemaValidation: $schemaValidation,
        );

        // Call handling
        $this->autoAnswer   = $autoAnswer;
        $this->recordCall   = $recordCall;
        $this->recordFormat = $recordFormat;
        $this->recordStereo = $recordStereo;

        // Prompt / POM
        $this->usePom      = $usePom;
        $this->pomSections = [];
        $this->promptText  = '';
        $this->postPrompt  = '';

        // Tools — registry now initialised by Service (parent) property defaults

        // Hints
        $this->hints        = [];
        $this->patternHints = [];

        // Languages / pronunciations
        $this->languages      = [];
        $this->multilingual   = [];
        $this->pronunciations = [];

        // Params / data
        $this->params     = [];
        $this->globalData = [];

        // Native functions / fillers / debug
        $this->nativeFunctions  = $nativeFunctions ?? [];
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

        // MCP servers
        $this->mcpServers = [];
        $this->mcpServerEnabled = false;

        // Session / context / skills. tokenExpirySecs is forwarded here —
        // mirrors Python `SessionManager(token_expiry_secs=token_expiry_secs)`
        // (agent_base.py:247).
        $this->sessionManager = new SessionManager(tokenExpirySecs: $tokenExpirySecs);
        $this->contextBuilder = null;
        $this->skillManager   = null;

        // Agent identity: explicit id wins, else a generated UUIDv4.
        $this->agentId = ($agentId !== null && $agentId !== '')
            ? $agentId
            : self::generateUuidV4();

        // Agent-specific construction state (mirrors agent_base.py:225-254).
        $this->defaultWebhookUrl        = $defaultWebhookUrl;
        $this->suppressLogs             = $suppressLogs;
        $this->trustProxyForSignature   = $trustProxyForSignature;
        $this->enablePostPromptOverride = $enablePostPromptOverride;
        $this->checkForInputOverride    = $checkForInputOverride;

        // Webhook signing key: explicit > env. Empty string treated as unset.
        if ($signingKey !== null && $signingKey !== '') {
            $this->signingKey = $signingKey;
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

    /**
     * This agent's unique ID — the constructor's `agentId` or a generated
     * UUIDv4. Mirrors Python AgentBase's public `self.agent_id`.
     *
     * PUBLIC, deliberately: the caller supplies `agentId` at construction, so
     * the caller must be able to read it back. It was `protected`, which is the
     * exact failure CONSTRUCTION-READBACK exists to catch — cpp made the same
     * two members (`agent_id`, `token_expiry_secs`) protected purely to pass
     * SURFACE-DIFF, and the consequence was that its callers could not read what
     * Python callers can.
     */
    public function getAgentId(): string
    {
        return $this->agentId;
    }

    /**
     * This agent, for the reference's `PromptManager.agent` / `ToolRegistry.agent`
     * back-reference. Python factors prompt handling and tool registration into
     * collaborator objects that hold a reference BACK to the agent; php flattens
     * both onto the agent itself, so the back-reference resolves to `$this` (the
     * same resolution cpp used when it merged the manager into the agent).
     */
    public function getAgent(): self
    {
        return $this;
    }

    /**
     * The construction-time default webhook URL for SWAIG functions, or null.
     * Mirrors Python AgentBase's `_default_webhook_url`.
     */
    protected function getDefaultWebhookUrl(): ?string
    {
        return $this->defaultWebhookUrl;
    }

    /**
     * Whether structured request logs are suppressed.
     * Mirrors Python AgentBase's `_suppress_logs`.
     */
    protected function getSuppressLogs(): bool
    {
        return $this->suppressLogs;
    }

    /**
     * Whether proxy headers are honoured when reconstructing the URL during
     * webhook signature validation.
     * Mirrors Python AgentBase's `_trust_proxy_for_signature`.
     */
    protected function getTrustProxyForSignature(): bool
    {
        return $this->trustProxyForSignature;
    }

    /**
     * Load the `service` section of the config file, mirroring Python's
     * `AgentBase._load_service_config(config_file, service_name)`
     * (agent_base.py:358-382): when no path is given the standard search
     * paths are consulted for a service-named config first.
     *
     * @return array<string, mixed>
     */
    private static function loadServiceConfig(?string $configFile, string $serviceName): array
    {
        if ($configFile === null || $configFile === '') {
            $configFile = ConfigLoader::findConfigFile($serviceName);
        }
        if ($configFile === null) {
            return [];
        }

        $loader = new ConfigLoader([$configFile]);
        if (!$loader->hasConfig()) {
            return [];
        }

        return $loader->getSection('service');
    }

    /**
     * Generate a RFC-4122 version-4 UUID, matching the reference's
     * `str(uuid.uuid4())` agent-id default.
     */
    private static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Prompt Methods
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Set the agent's main prompt as one plain-text string, an alternative to
     * building it out of POM sections.
     */
    public function setPromptText(string $text): self
    {
        $this->promptText = $text;
        return $this;
    }

    /**
     * Set the post-prompt — the instruction the AI runs after the conversation
     * ends (typically a summary request). Its result is delivered to the
     * post-prompt URL.
     */
    public function setPostPrompt(string $text): self
    {
        $this->postPrompt = $text;
        return $this;
    }

    /**
     * Add a top-level POM section with an optional body and bullets.
     *
     * @param list<string> $bullets
     */
    public function promptAddSection(string $title, string $body, array $bullets = []): static
    {
        $this->usePom = true;

        // Match the POM Section serializer (POM\Section::toArray, and Python's
        // pom Section.to_dict): an empty body is OMITTED, not emitted as "".
        $section = [
            'title'  => $title,
        ];
        if ($body !== '') {
            $section['body'] = $body;
        }

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
        $this->usePom = true;

        $found = false;
        foreach ($this->pomSections as &$section) {
            if ($section['title'] === $parentTitle) {
                if (!isset($section['subsections'])) {
                    $section['subsections'] = [];
                }
                $sub = ['title' => $title];
                if ($body !== '') {
                    $sub['body'] = $body;
                }
                $section['subsections'][] = $sub;
                $found = true;
                break;
            }
        }
        unset($section);

        // Auto-create the parent section when missing (matching the
        // TypeScript reference, which calls addSection(parentTitle)).
        if (!$found) {
            $sub = ['title' => $title];
            if ($body !== '') {
                $sub['body'] = $body;
            }
            $this->pomSections[] = [
                'title'       => $parentTitle,
                'subsections' => [$sub],
            ];
        }

        return $this;
    }

    /**
     * Append body text and/or bullets to an existing section.
     *
     * @param list<string> $bullets
     */
    public function promptAddToSection(string $title, ?string $body = null, array $bullets = []): self
    {
        $this->usePom = true;

        $found = false;
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
                $found = true;
                break;
            }
        }
        unset($section);

        // Auto-create the section when missing (matching the TypeScript
        // reference, which calls addSection(title) before appending).
        if (!$found) {
            $section = ['title' => $title];
            if ($body !== null) {
                $section['body'] = $body;
            }
            if (!empty($bullets)) {
                $section['bullets'] = $bullets;
            }
            $this->pomSections[] = $section;
        }

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
     * @return list<array<string,mixed>>|string
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
     *
     * @param list<mixed> $pom List of POM section arrays; non-array entries are
     *                         defensively skipped by the loop below.
     */
    public function setPromptPom(array $pom): self
    {
        $this->usePom      = true;
        $this->pomSections = [];
        foreach ($pom as $section) {
            if (!is_array($section)) {
                continue;
            }
            $built = $this->normalizePomSection($section);
            if ($built !== null) {
                $this->pomSections[] = $built;
            }
        }
        return $this;
    }

    /**
     * Coerce an externally-supplied section array into the internal
     * PomSection shape, validating each recognised key at runtime. Sections
     * without a string title are skipped (Python's renderer requires one).
     *
     * @param array<mixed, mixed> $section
     * @return PomSection|null
     */
    private function normalizePomSection(array $section): ?array
    {
        $title = $section['title'] ?? null;
        if (!is_string($title)) {
            return null;
        }
        $out = ['title' => $title];

        $body = $section['body'] ?? null;
        if (is_string($body)) {
            $out['body'] = $body;
        }

        $bullets = $section['bullets'] ?? null;
        if (is_array($bullets)) {
            $out['bullets'] = array_values(array_filter($bullets, 'is_string'));
        }

        $numbered = $section['numbered'] ?? null;
        if (is_bool($numbered)) {
            $out['numbered'] = $numbered;
        }

        $numberedBullets = $section['numberedBullets'] ?? ($section['numbered_bullets'] ?? null);
        if (is_bool($numberedBullets)) {
            $out['numberedBullets'] = $numberedBullets;
        }

        $subsections = $section['subsections'] ?? null;
        if (is_array($subsections)) {
            $subs = [];
            foreach ($subsections as $sub) {
                if (!is_array($sub)) {
                    continue;
                }
                $subData = [];
                foreach ($sub as $k => $v) {
                    if (is_string($k)) {
                        $subData[$k] = $v;
                    }
                }
                $subs[] = $subData;
            }
            $out['subsections'] = $subs;
        }

        return $out;
    }

    /**
     * Return the agent's POM as a typed PromptObjectModel instance.
     *
     * Mirrors ``agent.pom`` instance attribute (agent_base.py
     * line 209). Returns ``null`` when ``use_pom`` is false (mirroring
     * Python's ``self.pom = None``).  Otherwise returns a PromptObjectModel
     * built from the agent's stored POM section dicts — sections added via
     * ``promptAddSection`` / ``setPromptPom`` show up as native ``Section``
     * objects so callers can use ``renderMarkdown`` / ``renderXml`` /
     * ``findSection`` etc directly.
     *
     * The returned instance is freshly constructed each call; mutating it
     * does not affect the agent's stored state (the same behavior is achieved
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
     *
     * @return array<string, array<string,mixed>>|null
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
     * Mirrors state_mixin.StateMixin._create_tool_token —
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
     * Mirrors state_mixin.StateMixin.validate_tool_token —
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

    /**
     * Append one speech-recognition hint — a word or phrase the recognizer
     * should bias toward. Appends; it does not replace the existing list (use
     * {@see AgentBase::addHints()} for a batch).
     */
    public function addHint(string $hint): self
    {
        $this->hints[] = $hint;
        return $this;
    }

    /**
     * @param list<string> $hints
     */
    public function addHints(array $hints): static
    {
        foreach ($hints as $hint) {
            $this->hints[] = (string) $hint;
        }
        return $this;
    }

    /**
     * Add a complex hint with pattern matching.
     *
     * Mirrors Python's ``AIConfigMixin.add_pattern_hint``: attaches a
     * STRUCTURED hint (not a bare string) that is merged into the rendered
     * SWML ``ai.hints`` array. All three of hint/pattern/replace must be
     * non-empty for the hint to be recorded (mirrors the reference).
     *
     * @param string $hint       The hint token to match.
     * @param string $pattern    Regular-expression pattern.
     * @param string $replace    Text to replace the matched hint with.
     * @param bool   $ignoreCase Whether matching should ignore case.
     */
    public function addPatternHint(
        string $hint,
        string $pattern,
        string $replace,
        bool $ignoreCase = false,
    ): self {
        if ($hint !== '' && $pattern !== '' && $replace !== '') {
            $this->patternHints[] = [
                'hint'        => $hint,
                'pattern'     => $pattern,
                'replace'     => $replace,
                'ignore_case' => $ignoreCase,
            ];
        }
        return $this;
    }

    /**
     * Add a language configuration to support multilingual conversations.
     *
     * Mirrors Python's ``AIConfigMixin.add_language``: carries engine, model
     * and fillers into the rendered SWML ``ai.languages`` entry, and parses
     * the combined ``engine.voice:model`` voice string when engine/model are
     * not given explicitly.
     *
     * @param string                   $voice           TTS voice. Either a
     *     simple name, or the combined ``engine.voice:model`` format when
     *     engine/model are not passed explicitly.
     * @param list<string>|null        $speechFillers   Filler phrases for
     *     natural speech.
     * @param list<string>|null        $functionFillers Filler phrases spoken
     *     during function calls.
     * @param string|null              $engine          Explicit engine name.
     * @param string|null              $model           Explicit model name.
     * @param array<string,mixed>|null $params Optional per-language
     *     params dict (engine-specific tuning, voice settings, etc.).
     *     Emitted as the language object's `params` key in SWML when
     *     non-empty. Empty / null omits the key.
     */
    public function addLanguage(
        string $name,
        string $code,
        string $voice,
        ?array $speechFillers = null,
        ?array $functionFillers = null,
        ?string $engine = null,
        ?string $model = null,
        ?array $params = null,
    ): self {
        $language = [
            'name' => $name,
            'code' => $code,
        ];

        // Handle voice formatting (either explicit params or combined string).
        if ($engine !== null || $model !== null) {
            $language['voice'] = $voice;
            if ($engine !== null) {
                $language['engine'] = $engine;
            }
            if ($model !== null) {
                $language['model'] = $model;
            }
        } elseif (str_contains($voice, '.') && str_contains($voice, ':')) {
            // Parse combined string format: "engine.voice:model".
            [$engineVoice, $modelPart] = explode(':', $voice, 2);
            if (str_contains($engineVoice, '.')) {
                [$enginePart, $voicePart] = explode('.', $engineVoice, 2);
                $language['voice']  = $voicePart;
                $language['engine'] = $enginePart;
                $language['model']  = $modelPart;
            } else {
                $language['voice'] = $voice;
            }
        } else {
            $language['voice'] = $voice;
        }

        // Add fillers if provided.
        if ($speechFillers !== null && $speechFillers !== []
            && $functionFillers !== null && $functionFillers !== []) {
            $language['speech_fillers']   = $speechFillers;
            $language['function_fillers'] = $functionFillers;
        } elseif (($speechFillers !== null && $speechFillers !== [])
            || ($functionFillers !== null && $functionFillers !== [])) {
            // Only one type provided -> deprecated combined "fillers" field.
            $language['fillers'] = ($speechFillers !== null && $speechFillers !== [])
                ? $speechFillers
                : $functionFillers;
        }

        // Per-language params (engine-specific tuning, voice settings,
        // etc.). Only emit the key when non-empty so we don't pollute
        // SWML with empty objects.
        if ($params !== null && $params !== []) {
            $language['params'] = $params;
        }
        $this->languages[] = $language;
        return $this;
    }

    /**
     * Set (or replace) the per-language `params` dict on an
     * already-added language. Useful when language entries are built
     * up via addLanguage() first and engine-specific tuning is added
     * later (e.g., from a config loader).
     *
     * @param array<string,mixed> $params Engine-specific params dict
     *     to attach. Empty array removes the key. No-op when `code`
     *     isn't found.
     */
    public function setLanguageParams(string $code, array $params): self
    {
        foreach ($this->languages as $i => $language) {
            if (($language['code'] ?? null) === $code) {
                if ($params !== []) {
                    $this->languages[$i]['params'] = $params;
                } else {
                    unset($this->languages[$i]['params']);
                }
                break;
            }
        }
        return $this;
    }

    /**
     * Read the per-language `params` dict for a previously-added
     * language. Returns null when unset or when the code is unknown
     * (no exception path).
     *
     * @return array<string,mixed>|null
     */
    public function getLanguageParams(string $code): ?array
    {
        foreach ($this->languages as $language) {
            if (($language['code'] ?? null) === $code) {
                $params = $language['params'] ?? null;
                if (!is_array($params)) {
                    return null;
                }
                $out = [];
                foreach ($params as $k => $v) {
                    if (is_string($k)) {
                        $out[$k] = $v;
                    }
                }
                return $out;
            }
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $languages
     */
    public function setLanguages(array $languages): self
    {
        $this->languages = $languages;
        return $this;
    }

    /**
     * Configure ASR-driven multilingual mode (Mode B).
     *
     * Emits a top-level `multilingual` object on the AI verb. The recognizer
     * runs in code-switching mode and the agent answers in whatever language
     * the caller actually spoke — the model does not pick the language. This is
     * mutually exclusive with setLanguages(); if both are set the server uses
     * `multilingual` and ignores `languages`.
     *
     * Mirrors AIConfigMixin.set_multilingual(config).
     *
     * @param array<string,mixed> $config The multilingual config object
     *   (languages, allowed, start_language, min_switch_words, fillers, etc.).
     */
    public function setMultilingual(array $config): self
    {
        if ($config !== []) {
            $this->multilingual = $config;
        }
        return $this;
    }

    /**
     * Add a pronunciation rule (mirrors Python add_pronunciation(replace,
     * with_text, ignore_case: bool = False)). Emits the SWML wire key
     * `ignore_case` (bool, only when true), NOT `ignore`.
     */
    public function addPronunciation(string $replace, string $with, bool $ignoreCase = false): self
    {
        $entry = [
            'replace' => $replace,
            'with'    => $with,
        ];
        if ($ignoreCase) {
            $entry['ignore_case'] = true;
        }
        $this->pronunciations[] = $entry;
        return $this;
    }

    /**
     * @param list<array{replace: string, with: string, ignore_case?: bool}> $pronunciations
     */
    public function setPronunciations(array $pronunciations): self
    {
        $this->pronunciations = $pronunciations;
        return $this;
    }

    /**
     * Set ONE AI-verb parameter, leaving the others intact — unlike
     * {@see AgentBase::setParams()}, which replaces the whole `params` map.
     *
     * @param string $key   the wire parameter name, passed through verbatim
     *   (this is not a validated closed set).
     * @param mixed  $value the value, emitted as-is under `ai.params`.
     */
    public function setParam(string $key, mixed $value): self
    {
        $this->params[$key] = $value;
        return $this;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function setParams(array $params): self
    {
        $this->params = $params;
        return $this;
    }

    /**
     * Merge $data into global_data. Despite the name this does NOT replace
     * the existing object: existing keys are preserved and incoming keys
     * overwrite only on collision. This mirrors updateGlobalData() and the
     * TypeScript reference (safeAssign) — skills and other callers each
     * contribute keys, so a replacing setGlobalData would silently clobber
     * their contributions.
     *
     * @param array<string,mixed> $data
     */
    public function setGlobalData(array $data): self
    {
        $this->globalData = array_merge($this->globalData, $data);
        return $this;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function updateGlobalData(array $data): static
    {
        $this->globalData = array_merge($this->globalData, $data);
        return $this;
    }

    /**
     * @param list<string> $functions
     */
    public function setNativeFunctions(array $functions): self
    {
        $this->nativeFunctions = $functions;
        return $this;
    }

    /**
     * The native functions advertised to the platform. The reference records
     * ``AgentBase.native_functions`` (the attribute) and
     * ``AIConfigMixin.set_native_functions`` (the setter) as TWO DISTINCT
     * members — so the setter does not stand in for the reader. php had only the
     * setter, with the field ``protected``, so a caller could pass
     * ``nativeFunctions`` at construction and never read it back.
     *
     * @return list<string>
     */
    public function getNativeFunctions(): array
    {
        return $this->nativeFunctions;
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
     *
     * @param array<string, array<string, list<string>>> $fillers
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
                . 'setInternalFillers received names that the SWML schema '
                . 'does not recognize. Those entries will be ignored by '
                . "the runtime. Supported names: {$supportedStr}."
            );
        }
        $this->internalFillers = $fillers;
        return $this;
    }

    /**
     * Add internal fillers for a single internal function and language.
     *
     *   $agent->addInternalFiller($functionName, $languageCode, $fillers)
     *
     * See setInternalFillers() for the complete list of supported
     * function names and what fillers do. Names outside the supported
     * set log a warning.
     *
     * @param list<string> $fillers
     */
    public function addInternalFiller(string $functionName, string $languageCode, array $fillers): self
    {
        // Python: the whole body is guarded on all three being truthy
        // (ai_config_mixin.py:489) — an empty name/code/list is a no-op.
        if ($functionName === '' || $languageCode === '' || $fillers === []) {
            return $this;
        }

        if (!in_array($functionName, self::SUPPORTED_INTERNAL_FILLER_NAMES, true)) {
            $supported = self::SUPPORTED_INTERNAL_FILLER_NAMES;
            sort($supported);
            $supportedStr = "['" . implode("', '", $supported) . "']";
            $this->logger->warn(
                "unknown_internal_filler_name: '{$functionName}'. "
                . 'addInternalFiller received a function name the SWML '
                . 'schema does not recognize. The entry will be stored '
                . 'but the runtime will not play these fillers. '
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

    /**
     * Enable the debug-event webhook for this agent.
     *
     * Mirrors Python's enable_debug_events(level: int = 1): the AI module POSTs
     * real-time debug events to the agent's /debug_events endpoint. The level is
     * the wire verbosity (1 = high-level events; 2+ = high-volume: every LLM
     * request/response, conversation_add) and is emitted as the SWML AI param
     * `debug_webhook_level` (int), NOT `debug_events`.
     */
    public function enableDebugEvents(int $level = 1): self
    {
        $this->debugEventsLevel = $level;
        return $this;
    }

    /**
     * @param array<string, mixed> $include
     */
    public function addFunctionInclude(array $include): self
    {
        $this->functionIncludes[] = $include;
        return $this;
    }

    /**
     * Replace the entire list of function includes.
     *
     * Each include must have a truthy ``url`` and an array ``functions``
     * field; entries missing either are dropped (matching the TypeScript
     * reference's filter), and each dropped entry is warned so a malformed
     * include is caught at registration time rather than silently vanishing.
     *
     * @param list<mixed> $includes List of include arrays ({url, functions, ...});
     *                              malformed / non-array entries are defensively dropped.
     */
    public function setFunctionIncludes(array $includes): self
    {
        $valid = [];
        foreach ($includes as $include) {
            if (
                is_array($include)
                && !empty($include['url'])
                && isset($include['functions'])
                && is_array($include['functions'])
            ) {
                $valid[] = $include;
                continue;
            }
            $urlLabel = (is_array($include) && isset($include['url']) && is_string($include['url']))
                ? $include['url']
                : '(no url)';
            $this->logger->warn(
                "invalid_function_include: '{$urlLabel}'. setFunctionIncludes "
                . 'requires each entry to have a non-empty url and an array '
                . 'functions field; this entry was dropped.'
            );
        }
        $this->functionIncludes = $valid;
        return $this;
    }

    /**
     * Add an external MCP server for tool discovery and invocation.
     *
     * Tools are discovered via the MCP protocol at session start and registered
     * as SWAIG functions; resources are optionally fetched into global_data.
     * Mirrors Python's ``AIConfigMixin.add_mcp_server`` (projected onto the
     * ai_config_mixin path by the surface enumerator).
     *
     * @param array<string, string>|null $headers      Optional HTTP headers.
     * @param array<string, string>|null $resourceVars Variables for URI templates.
     *
     * @return $this
     */
    public function addMcpServer(
        string $url,
        ?array $headers = null,
        bool $resources = false,
        ?array $resourceVars = null
    ): self {
        $server = ['url' => $url];
        if ($headers !== null && $headers !== []) {
            $server['headers'] = $headers;
        }
        if ($resources) {
            $server['resources'] = true;
        }
        if ($resourceVars !== null && $resourceVars !== []) {
            $server['resource_vars'] = $resourceVars;
        }
        $this->mcpServers[] = $server;
        return $this;
    }

    /**
     * Expose this agent's tools as an MCP server endpoint at ``/mcp``.
     *
     * Mirrors Python's ``AIConfigMixin.enable_mcp_server`` (projected onto the
     * ai_config_mixin path by the surface enumerator).
     *
     * @return $this
     */
    public function enableMcpServer(): self
    {
        $this->mcpServerEnabled = true;
        return $this;
    }

    /**
     * Whether the MCP server endpoint is enabled.
     */
    public function isMcpServerEnabled(): bool
    {
        return $this->mcpServerEnabled;
    }

    /**
     * The configured external MCP servers (read-only copy).
     *
     * @return list<array<string, mixed>>
     */
    public function getMcpServers(): array
    {
        return $this->mcpServers;
    }

    /**
     * Enable debug routes for testing and development.
     *
     * Debug routes are registered by the request router; this method exists for
     * API compatibility and returns ``$this`` for chaining. Mirrors Python's
     * ``WebMixin.enable_debug_routes`` (projected onto the web_mixin path).
     *
     * @return $this
     */
    public function enableDebugRoutes(): self
    {
        return $this;
    }

    /**
     * Register signal handlers for graceful shutdown (e.g. Kubernetes SIGTERM).
     *
     * Uses PHP's pcntl signal handling when available; a no-op otherwise (the
     * ext-pcntl extension is optional and absent under most SAPIs). Mirrors
     * Python's ``WebMixin.setup_graceful_shutdown``.
     */
    public function setupGracefulShutdown(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $signo): void {
            $this->logger->info("shutdown_signal_received: {$signo}");
            exit(0);
        };

        // SIGTERM = 15 (Kubernetes), SIGINT = 2 (Ctrl+C). Reference the named
        // constants when defined, falling back to the POSIX numbers.
        $sigterm = defined('SIGTERM') ? SIGTERM : 15;
        $sigint = defined('SIGINT') ? SIGINT : 2;
        pcntl_signal($sigterm, $handler);
        pcntl_signal($sigint, $handler);
    }

    /**
     * Merge LLM parameters into the main prompt config.
     *
     * Mirrors Python `AIConfigMixin.set_prompt_llm_params`
     * (ai_config_mixin.py:669): `self._prompt_llm_params.update(params)` —
     * successive calls MERGE, so distinct keys accumulate rather than the
     * latest call replacing the earlier ones.
     *
     * @param array<string,mixed> $params
     */
    public function setPromptLlmParams(array $params): self
    {
        $this->promptLlmParams = array_merge($this->promptLlmParams, $params);
        return $this;
    }

    /**
     * Merge LLM parameters into the post-prompt config.
     *
     * Mirrors Python `AIConfigMixin.set_post_prompt_llm_params`
     * (ai_config_mixin.py:703): `self._post_prompt_llm_params.update(params)`
     * — successive calls MERGE (see {@see setPromptLlmParams}).
     *
     * @param array<string,mixed> $params
     */
    public function setPostPromptLlmParams(array $params): self
    {
        $this->postPromptLlmParams = array_merge($this->postPromptLlmParams, $params);
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Verb Methods
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Append a SWML verb that runs BEFORE the `answer` verb — i.e. while the
     * call is still ringing (for example `play` on early media).
     *
     * Verbs are emitted into `sections.main` in call order:
     * pre-answer → answer → record_call → post-answer → ai → post-ai.
     * Repeated calls append, preserving insertion order.
     *
     * @param string $verb   the SWML verb name, used verbatim as the object key.
     * @param array<string,mixed> $config the verb's configuration. Typed to
     *        match what the validating Service::addVerb accepts — renderSwml
     *        emits these through it, so a `mixed` here only deferred the type
     *        error to render time. Mirrors the reference's `config: dict[str, Any]`
     *        (core/agent_base.py:558).
     */
    public function addPreAnswerVerb(string $verb, array $config): self
    {
        $this->preAnswerVerbs[] = [$verb, $config];
        return $this;
    }

    /**
     * Append a SWML verb that runs after the call is answered but BEFORE the
     * `ai` verb takes over. See {@see AgentBase::addPreAnswerVerb()} for the
     * full ordering.
     *
     * @param string $verb   the SWML verb name, used verbatim as the object key.
     * @param array<string,mixed> $config the verb's configuration. Typed to
     *        match what the validating Service::addVerb accepts — renderSwml
     *        emits these through it, so a `mixed` here only deferred the type
     *        error to render time. Mirrors the reference's `config: dict[str, Any]`
     *        (core/agent_base.py:558).
     */
    public function addPostAnswerVerb(string $verb, array $config): self
    {
        $this->postAnswerVerbs[] = [$verb, $config];
        return $this;
    }

    /**
     * Alias for addPostAnswerVerb().
     *
     * @param string $verb   the SWML verb name, used verbatim as the object key.
     * @param array<string,mixed> $config the verb's configuration.
     */
    public function addAnswerVerb(string $verb, array $config): self
    {
        return $this->addPostAnswerVerb($verb, $config);
    }

    /**
     * Append a SWML verb that runs AFTER the `ai` verb finishes — the tail of
     * `sections.main` (for example a closing `play` or `hangup`).
     *
     * @param string $verb   the SWML verb name, used verbatim as the object key.
     * @param array<string,mixed> $config the verb's configuration. Typed to
     *        match what the validating Service::addVerb accepts — renderSwml
     *        emits these through it, so a `mixed` here only deferred the type
     *        error to render time. Mirrors the reference's `config: dict[str, Any]`
     *        (core/agent_base.py:558).
     */
    public function addPostAiVerb(string $verb, array $config): self
    {
        $this->postAiVerbs[] = [$verb, $config];
        return $this;
    }

    /** Drop every verb registered by {@see AgentBase::addPreAnswerVerb()}. */
    public function clearPreAnswerVerbs(): self
    {
        $this->preAnswerVerbs = [];
        return $this;
    }

    /**
     * Drop every verb registered by {@see AgentBase::addPostAnswerVerb()} (and
     * therefore by its `addAnswerVerb` alias).
     */
    public function clearPostAnswerVerbs(): self
    {
        $this->postAnswerVerbs = [];
        return $this;
    }

    /** Drop every verb registered by {@see AgentBase::addPostAiVerb()}. */
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
     *
     * Surfaces a load failure by THROWING — a skill that is not in the
     * registry, is missing required env vars, whose ``setup()`` returns false,
     * or is a duplicate that forbids multiple instances is a configuration
     * error the caller must see, NOT a silent no-op. Mirrors the python
     * reference ``SkillMixin.add_skill`` which raises
     * ``ValueError(f"Failed to load skill '{name}': {error}")``; PHP's idiom for
     * that value-domain error is ``\InvalidArgumentException``.
     *
     * @param array<string, mixed>|null $params
     * @throws \InvalidArgumentException when the skill fails to load.
     */
    public function addSkill(SkillName|string $name, ?array $params = null): static
    {
        $skillName = $name instanceof SkillName ? $name->value : $name;
        [$success, $error] = $this->getSkillManager()->loadSkill($skillName, params: $params);
        if (!$success) {
            throw new \InvalidArgumentException(
                "Failed to load skill '{$skillName}': {$error}"
            );
        }
        return $this;
    }

    /**
     * Unload a previously-added skill, running its `cleanup()` hook and
     * dropping it from the skill manager.
     *
     * Unlike {@see AgentBase::addSkill()}, this does not throw when the skill is
     * absent — the unload is silently a no-op, and the boolean the manager
     * returns is discarded in favour of the fluent `$this`.
     *
     * @param SkillName|string $name the typed skill enum or its bare string name.
     */
    public function removeSkill(SkillName|string $name): self
    {
        $this->getSkillManager()->unloadSkill($name instanceof SkillName ? $name->value : $name);
        return $this;
    }

    /**
     * @return list<string>
     */
    public function listSkills(): array
    {
        if ($this->skillManager === null) {
            return [];
        }
        return $this->skillManager->listLoadedSkills();
    }

    /** Whether there is a skill. */
    public function hasSkill(SkillName|string $name): bool
    {
        if ($this->skillManager === null) {
            return false;
        }
        return $this->skillManager->hasSkill($name instanceof SkillName ? $name->value : $name);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Web / Callback Methods
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Install the per-request reconfiguration callback used for multi-tenancy.
     *
     * The request handler retrieves it via
     * {@see AgentBase::getDynamicConfigCallback()} and invokes it as
     * `$cb($queryParams, $body, $headers, $agent)` against a
     * {@see AgentBase::cloneForRequest()} copy just before SWML rendering, so
     * mutations apply to that one request and never to the long-lived agent.
     * Only one callback is held — a second call replaces the first.
     *
     * @param callable $callback `(array $queryParams, array $body, array $headers, AgentBase $agent): void`.
     */
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

    /**
     * Override the SHARED SWAIG callback endpoint emitted as
     * `SWAIG.defaults.web_hook_url`.
     *
     * When unset, that default is derived from the request headers by the
     * agent's own URL builder (which embeds basic-auth credentials and any
     * SWAIG query params). Setting it here WINS over the derived URL, so the
     * override must itself be reachable and authenticated — every tool without
     * a per-tool `web_hook_url`, including every insecure tool, calls it.
     */
    public function setWebHookUrl(string $url): self
    {
        $this->webhookUrl = $url;
        return $this;
    }

    /**
     * Override the URL the post-prompt summary is delivered to, emitted as
     * `ai.post_prompt_url`. Omitted from the AI verb entirely when unset.
     */
    public function setPostPromptUrl(string $url): self
    {
        $this->postPromptUrl = $url;
        return $this;
    }

    // manualSetProxyUrl() is inherited from the base Service (SWMLService
    // parity); AgentBase does not override it.

    /**
     * @param array<string,string> $params
     */
    public function addSwaigQueryParams(array $params): self
    {
        $this->swaigQueryParams = array_merge($this->swaigQueryParams, $params);
        return $this;
    }

    /**
     * Drop every query param registered by
     * {@see AgentBase::addSwaigQueryParams()}.
     *
     * Note this also changes WHICH tools get a per-tool `web_hook_url`: a
     * handler-backed tool is given its own URL when it has a minted token OR
     * when swaig query params exist, so clearing the params can push tokenless
     * tools back onto the shared `SWAIG.defaults.web_hook_url`.
     */
    public function clearSwaigQueryParams(): self
    {
        $this->swaigQueryParams = [];
        return $this;
    }

    /**
     * Lifecycle handler invoked when a post-prompt summary is received.
     *
     * Mirrors Python `AgentBase.on_summary(summary, raw_data)` and the TS
     * `AgentBase.onSummary(summary, rawData)` overridable hook: the default
     * implementation is a no-op, and subclasses (e.g. the prefab agents)
     * override it to log or persist the interaction summary. The base
     * dispatcher ({@see handlePostPrompt}) calls this method with the parsed
     * summary and the full raw POST payload.
     *
     * @param array<string,mixed>|string|null $summary Parsed summary object
     *        (array when the post-prompt requested structured output, string
     *        for free-form), or null if no summary was extracted.
     * @param array<string,mixed>|null        $rawData Full raw post-prompt
     *        payload received from the platform, or null.
     */
    public function onSummary(array|string|null $summary, ?array $rawData = null): void
    {
        // Default implementation does nothing; subclasses override.
    }

    /**
     * Register a callback invoked when a post-prompt summary is received.
     *
     * PHP-additive convenience (recorded in PORT_ADDITIONS.md): where the
     * canonical contract is to override {@see onSummary}, this lets a caller
     * install a summary handler without subclassing. Both the registered
     * callback and the overridable {@see onSummary} method run.
     *
     * @param callable $callback fn(mixed $summary, array $rawData, array $headers): void
     */
    public function setSummaryCallback(callable $callback): self
    {
        $this->summaryCallback = $callback;
        return $this;
    }

    /**
     * Register the handler for debug events, invoked as `$callback($event)`.
     *
     * Only one handler is held — a second call replaces the first — and it is
     * carried across a {@see AgentBase::cloneForRequest()} by reference (the
     * clone shares the same callable, it is not deep-copied).
     */
    public function onDebugEvent(callable $callback): self
    {
        $this->debugEventHandler = $callback;
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SIP Methods
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Enable SIP-based routing for this agent.
     *
     * Mirrors Python `AgentBase.enable_sip_routing(auto_map=True, path="/sip")`
     * (agent_base.py:708). PHP's public method takes no args by idiom (the
     * auto_map / path overrides go through AgentServer::setupSipRouting); it
     * uses the reference defaults auto_map=true, path="/sip".
     *
     * Registers a routing callback at the SIP path that extracts the SIP
     * username from the request body and consults this agent's registered
     * usernames. When the username is registered to THIS agent the callback
     * returns null (the agent already serves the route, no redirect); this is
     * the reachable, consulted behavior — not a stored-but-ignored flag.
     */
    public function enableSipRouting(): self
    {
        $this->setParam('sip_routing', true);

        $path = '/sip';

        // (body, headers) -> route|null. Inspects only the body (via
        // extractSipUsername), matching Python's sip_routing_callback.
        $callback = function (array $body, array $headers): ?string {
            $sipUsername = self::extractSipUsername($body);
            if ($sipUsername === null || $sipUsername === '') {
                return null;
            }
            $this->logger->info("sip_username_extracted username={$sipUsername}");
            if (isset($this->sipUsernames[strtolower($sipUsername)])) {
                $this->logger->info("sip_username_matched username={$sipUsername}");
                // Route is already handled by this agent — no redirect.
                return null;
            }
            $this->logger->info("sip_username_not_matched username={$sipUsername}");
            return null;
        };

        $this->registerRoutingCallback($callback, $path);

        // auto_map defaults to true in the reference.
        $this->autoMapSipUsernames();

        return $this;
    }

    /**
     * Register a SIP username this agent answers on.
     *
     * Does two things: sets the `sip_username` (and, when a route is given,
     * `sip_route`) AI param, and adds the LOWERCASED username to the set the
     * SIP routing callback matches inbound requests against — so matching is
     * case-insensitive while the param keeps the caller's original casing.
     *
     * Successive calls overwrite the single `sip_username` param but ACCUMULATE
     * in the matchable set, so an agent can answer on several usernames.
     *
     * @param string $route optional route to record as `sip_route`; the empty
     *   default leaves the param unset.
     */
    public function registerSipUsername(string $username, string $route = ''): self
    {
        $this->setParam('sip_username', $username);
        if ($route !== '') {
            $this->setParam('sip_route', $route);
        }
        // Record the username in the consultable set so the SIP routing
        // callback can match it (Python: self._sip_usernames.add(lower)).
        $this->sipUsernames[strtolower($username)] = true;
        return $this;
    }

    /**
     * Automatically register common SIP usernames derived from this agent's
     * name and route.
     *
     * Mirrors Python `AgentBase.auto_map_sip_usernames()`: registers a
     * username from the cleaned agent name, one from the cleaned route (if
     * different), and a vowel-stripped variant of the name when it is long
     * enough. Returns `$this` for chaining.
     */
    public function autoMapSipUsernames(): self
    {
        // Register username based on agent name.
        $cleanName = preg_replace('/[^a-z0-9_]/', '', strtolower($this->name)) ?? '';
        if ($cleanName !== '') {
            $this->registerSipUsername($cleanName);
        }

        // Register username based on route (without slashes/punctuation).
        $cleanRoute = preg_replace('/[^a-z0-9_]/', '', strtolower($this->route)) ?? '';
        if ($cleanRoute !== '' && $cleanRoute !== $cleanName) {
            $this->registerSipUsername($cleanRoute);
        }

        // Register a vowel-stripped variation when the name is long enough.
        if (strlen($cleanName) > 3) {
            $noVowels = preg_replace('/[aeiou]/', '', $cleanName) ?? $cleanName;
            if ($noVowels !== $cleanName && strlen($noVowels) > 2) {
                $this->registerSipUsername($noVowels);
            }
        }

        return $this;
    }

    /**
     * Get this agent's name.
     *
     * Mirrors Python `AgentBase.get_name()`. Declared on AgentBase (in
     * addition to the inherited {@see Service::getName}) so the surface
     * enumerator records it on the agent_base module in the reference.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the full URL for this agent's endpoint.
     *
     * Mirrors Python `AgentBase.get_full_url(include_auth)`. Declared on
     * AgentBase so the surface records it on the agent_base module; delegates
     * to the parent {@see Service::getFullUrl} URL builder (which honours the
     * manual/env proxy base, host, port, and route).
     *
     * @param bool $includeAuth Whether to embed basic-auth credentials.
     */
    public function getFullUrl(bool $includeAuth = false): string
    {
        return parent::getFullUrl($includeAuth);
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
     * @param array<string, mixed>|null $requestBody
     * @param array<string, string> $headers
     * @return array<string, mixed> The SWML document array.
     */
    /**
     * @param array<string, mixed>|null $requestBody
     * @param array<string, string>     $headers
     * @param string|null               $callId Optional call id. When present,
     *   secure SWAIG functions get a per-tool ``__token`` appended to their
     *   ``web_hook_url`` (the wire manifestation of ``secure``), mirroring the
     *   python reference ``AgentBase._render_swml(call_id=...)``. Omitted →
     *   no per-tool token (the pre-render default; existing callers unaffected).
     * @return array<string, mixed>
     */
    public function renderSwml(?array $requestBody = null, array $headers = [], ?string $callId = null): array
    {
        // Build the document through the VALIDATING Service entry point rather
        // than hand-assembling the `{version, sections}` literal. The old code
        // path touched neither Service nor Document, so nothing ever checked a
        // verb name or its config — a third, entirely unvalidated way to emit
        // SWML. Mirrors the reference `AgentBase._render_swml`, which resets the
        // document and calls `add_verb(...)` for every phase before returning
        // `render_document()` (core/agent_base.py:1194-1330).
        $this->resetDocument();

        // 1. Pre-answer verbs
        foreach ($this->preAnswerVerbs as [$verb, $config]) {
            $this->addVerb($verb, $config);
        }

        // 2. Answer verb
        if ($this->autoAnswer) {
            $answerParams = array_merge(['max_duration' => 14400], $this->answerConfig);
            $this->addVerb('answer', $answerParams);
        }

        // 3. Record call verb
        if ($this->recordCall) {
            $this->addVerb('record_call', [
                'format' => $this->recordFormat,
                'stereo' => $this->recordStereo,
            ]);
        }

        // 4. Post-answer verbs
        foreach ($this->postAnswerVerbs as [$verb, $config]) {
            $this->addVerb($verb, $config);
        }

        // 5. AI verb
        $this->addVerb('ai', $this->buildAiVerb($headers, $callId));

        // 6. Post-AI verbs
        foreach ($this->postAiVerbs as [$verb, $config]) {
            $this->addVerb($verb, $config);
        }

        return $this->getDocument()->toArray();
    }

    /**
     * Build the AI verb configuration block.
     *
     * @param array<string, string> $headers
     * @param string|null            $callId Optional call id threaded to the
     *   SWAIG block so secure functions get their per-tool ``__token``.
     * @return array<string, mixed>
     */
    public function buildAiVerb(array $headers = [], ?string $callId = null): array
    {
        $ai = [];

        // ── Prompt ──────────────────────────────────────────────────────
        $prompt = [];
        if ($this->usePom && !empty($this->pomSections)) {
            $prompt['pom'] = $this->pomSections;
        } else {
            $text = $this->promptText;
            // When contexts are active and no prompt text was set, fall back to
            // the default prompt (matching the TypeScript reference, which uses
            // `prompt || \`You are ${this.name}, a helpful AI assistant.\``
            // in its contexts branch) rather than emitting an empty text.
            if ($text === '' && $this->contextBuilder !== null) {
                $text = "You are {$this->name}, a helpful AI assistant.";
            }
            $prompt['text'] = $text;
        }
        if (!empty($this->promptLlmParams)) {
            $prompt = array_merge($prompt, $this->promptLlmParams);
        }
        // ── Contexts (steps feature) ────────────────────────────────────
        // The contexts DEFINITION lives at ``ai.prompt.contexts`` — a sibling of
        // ``text``/``pom`` INSIDE the prompt object — NOT at a top-level
        // ``ai.context_switch``. This matches the SWML schema (swml_handler
        // validates ``prompt.contexts`` must be an object) and the python
        // reference (swml_handler.build_config: ``prompt_config['contexts'] =
        // contexts``). ``context_switch`` is a distinct RUNTIME SWAIG *action*
        // (FunctionResult::contextSwitch) returned at call time to change
        // context — it is NOT where the static contexts definition belongs.
        if ($this->contextBuilder !== null) {
            $contextArray = $this->contextBuilder->toArray();
            if (!empty($contextArray)) {
                $prompt['contexts'] = $contextArray;
            }
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
            $mergedParams['debug_webhook_level'] = $this->debugEventsLevel;
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

        // ── Multilingual (ASR-driven mode; top-level multilingual object) ─
        if (!empty($this->multilingual)) {
            $ai['multilingual'] = $this->multilingual;
        }

        // ── Pronunciations ──────────────────────────────────────────────
        if (!empty($this->pronunciations)) {
            $ai['pronounce'] = $this->pronunciations;
        }

        // ── SWAIG ──────────────────────────────────────────────────────
        $swaig = $this->buildSwaigBlock($headers, $callId);
        if (!empty($swaig)) {
            $ai['SWAIG'] = $swaig;
        }

        // ── Global data ─────────────────────────────────────────────────
        if (!empty($this->globalData)) {
            $ai['global_data'] = $this->globalData;
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
    /**
     * @param array<string, mixed>|null $requestData
     * @param array<string, string> $headers
     * @return array{int, array<string, string>, string}
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
     * An agent carries a SessionManager, so it CAN mint and check per-call
     * tokens — which is what turns `secure: true` from an unenforceable flag
     * into a real credential check. This is the switch that scopes SWAIG token
     * enforcement to agents (see Service::swaigValidateToken()).
     */
    protected function hasSwaigTokenValidation(): bool
    {
        return true;
    }

    /**
     * Validate a per-call SWAIG tool token against this agent's SessionManager.
     *
     * The parent Service has no session manager and so fails closed; an agent
     * DOES, which is what turns `secure: true` from a refuse-everything flag
     * into a real credential check. The decision itself — what an absent token
     * or an absent call_id means, and what the refusal looks like — stays in
     * the transport-agnostic {@see \SignalWire\SWML\Service::swaigValidateToken()};
     * this override supplies only the cryptographic answer.
     */
    protected function validateSwaigToolToken(string $functionName, string $token, string $callId): bool
    {
        return $this->validateToolToken($functionName, $token, $callId);
    }

    /**
     * Handle the post-prompt callback.
     *
     * @param array<string, mixed>|null $requestData
     * @param array<string, string> $headers
     * @return array{int, array<string, string>, string}
     */
    protected function handlePostPrompt(?array $requestData, array $headers): array
    {
        if ($requestData !== null) {
            $postPromptData = $requestData['post_prompt_data'] ?? null;
            $raw = is_array($postPromptData) ? ($postPromptData['raw'] ?? null) : null;
            $summaryRaw = $raw ?? $requestData['summary'] ?? '';
            /** @var array<string,mixed>|string|null $summary */
            $summary = (is_array($summaryRaw) || is_string($summaryRaw)) ? $summaryRaw : null;

            // Invoke the overridable lifecycle handler (Python/TS parity), then
            // the optionally-registered convenience callback.
            $this->onSummary($summary, $requestData);
            if ($this->summaryCallback !== null) {
                ($this->summaryCallback)($summary, $requestData, $headers);
            }
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
    /**
     * Handle a serverless-environment invocation (CGI, Lambda, Cloud
     * Functions, Azure). Mirrors the reference
     * `signalwire.core.mixins.serverless_mixin.ServerlessMixin.handle_serverless_request`:
     * detect (or accept an override for) the execution mode and dispatch to the
     * matching platform handler, returning a platform-appropriate response.
     *
     * Delegates the per-platform request extraction + response shaping to
     * {@see \SignalWire\Serverless\Adapter}, which reads the event/request and
     * drives this agent's handleRequest(). For 'server' mode it starts the
     * built-in server (no return).
     *
     * @param array<string,mixed>|null $event   Serverless event (Lambda/Azure API-gateway payload).
     * @param object|null $context Serverless context object (Lambda/Cloud Functions).
     * @param string|null $mode Override execution mode ('cgi'/'lambda'/'gcf'/'azure'/'server').
     * @return array<string,mixed>|null Platform response array (Lambda/Azure), or null when
     *   the handler writes directly to the output stream (CGI/GCF) or starts the server.
     */
    public function handleServerlessRequest(
        ?array $event = null,
        ?object $context = null,
        ?string $mode = null,
    ): ?array {
        $resolved = $mode === null
            ? \SignalWire\Serverless\Adapter::detectMode()
            : \SignalWire\Serverless\ExecutionMode::coerce($mode);

        switch ($resolved) {
            case \SignalWire\Serverless\ExecutionMode::Lambda:
                return \SignalWire\Serverless\Adapter::handleLambda(
                    $this,
                    $event ?? [],
                    $context ?? new \stdClass(),
                );

            case \SignalWire\Serverless\ExecutionMode::Azure:
                return \SignalWire\Serverless\Adapter::handleAzure($this, $event ?? []);

            case \SignalWire\Serverless\ExecutionMode::Gcf:
                \SignalWire\Serverless\Adapter::handleGcf($this);
                return null;

            case \SignalWire\Serverless\ExecutionMode::Cgi:
                \SignalWire\Serverless\Adapter::handleCgi($this);
                return null;

            case \SignalWire\Serverless\ExecutionMode::Server:
                $this->run();
                return null;
        }
    }

    /**
     * Produce an isolated per-request copy of this agent, so a dynamic-config
     * callback ({@see AgentBase::setDynamicConfigCallback()}) can reshape the
     * agent for ONE request without mutating the long-lived instance shared by
     * concurrent calls.
     *
     * The isolation is deliberate and partial:
     *   - mutable configuration arrays (POM sections, tools, hints, languages,
     *     pronunciations, params, global data, LLM params, the three verb
     *     lists, answer config, SWAIG query params, includes, MCP servers) are
     *     DEEP-copied, so per-request edits do not leak;
     *   - the session manager and the context builder are cloned;
     *   - the three callbacks (dynamic-config, summary, debug-event) are shared
     *     BY REFERENCE — they are behaviour, not per-request state.
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
        $clone->multilingual       = $this->deepCopyArray($this->multilingual);
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
        $clone->mcpServers         = $this->deepCopyArray($this->mcpServers);
        $clone->mcpServerEnabled   = $this->mcpServerEnabled;

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
     *
     * @param array<string, string> $headers
     * @param string|null            $callId When present, a SECURE tool gets a
     *   per-tool ``__token`` appended to its ``web_hook_url`` (the wire
     *   manifestation of ``secure`` — mirrors python agent_base.py:1040/1096-1100:
     *   ``if func.secure and call_id: url_params['__token'] = token``). An
     *   INSECURE tool (``secure=False``) never gets a token, and therefore gets
     *   NO per-tool ``web_hook_url`` at all — it falls back to the shared
     *   ``SWAIG.defaults.web_hook_url``. When $callId is null no token is minted
     *   (the render-without-call_id default).
     * @return array<string, mixed>
     */
    private function buildSwaigBlock(array $headers, ?string $callId = null): array
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
            $funcDef = array_filter($tool, fn (string $key): bool => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);

            // Resolve the per-tool web_hook_url for callable tools (those with a
            // handler). Mirrors python agent_base.py:1085-1099 EXACTLY:
            //
            //   1. an EXTERNAL url supplied by the caller wins verbatim;
            //   2. else emit a local URL ONLY when a token was minted OR SWAIG
            //      query params exist;
            //   3. else emit NO ``web_hook_url`` key at all.
            //
            // Case 3 is load-bearing security, not a cosmetic omission: an
            // INSECURE tool (``secure=false``, therefore no token) must fall back
            // to the shared ``SWAIG.defaults.web_hook_url``. Handing it its own
            // URL would publish an UNAUTHENTICATED function-specific callback —
            // a tokenless endpoint bound to one tool.
            if (isset($tool['_handler']) && !isset($funcDef['web_hook_url'])) {
                // Mint a per-tool token ONLY for a SECURE tool when we have a
                // call_id — the platform round-trips that token on the callback,
                // so its PRESENCE on the wire is what makes ``secure`` real.
                // (python: ``if func.secure and call_id``.)
                $token = null;
                if ($callId !== null && $callId !== '' && ($tool['_secure'] ?? false) === true) {
                    $minted = $this->createToolToken($name, $callId);
                    $token = $minted !== '' ? $minted : null;
                }
                // python's ``elif token or agent._swaig_query_params``.
                if ($token !== null || !empty($this->swaigQueryParams)) {
                    $funcDef['web_hook_url'] = $this->buildSwaigWebhookUrl($headers, $token);
                }
            }

            $functions[] = $funcDef;
        }
        if (!empty($functions)) {
            $swaig['functions'] = $functions;
            // The SHARED fallback endpoint every function without its own
            // ``web_hook_url`` calls — notably an INSECURE tool, which by
            // contract carries no per-tool URL. Mirrors python
            // agent_base.py:1109-1113 (emitted whenever functions exist) and
            // :972-979 (the ``setWebHookUrl`` override wins over the built URL).
            $swaig['defaults'] = [
                'web_hook_url' => $this->webhookUrl ?? $this->buildSwaigWebhookUrl($headers),
            ];
        }

        // Native functions
        if (!empty($this->nativeFunctions)) {
            $swaig['native_functions'] = $this->nativeFunctions;
        }

        // Includes
        if (!empty($this->functionIncludes)) {
            $swaig['includes'] = $this->functionIncludes;
        }

        // MCP servers
        if (!empty($this->mcpServers)) {
            $swaig['mcp_servers'] = $this->mcpServers;
        }

        return $swaig;
    }

    /**
     * Build the authenticated SWAIG webhook URL with query params.
     *
     * @param array<string, string> $headers
     * @param string|null            $token Optional per-tool SWAIG token. When
     *   present it is appended as the reserved ``__token`` query parameter
     *   (python uses ``__token`` to avoid collision with a caller's ``token``).
     */
    private function buildSwaigWebhookUrl(array $headers, ?string $token = null): string
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

        // Merge base SWAIG query params + the reserved per-tool __token (secure
        // tools only). Preserve base params first so __token is a sibling, not
        // an override — matches python's ``url_params[...] = token`` on a copy
        // of the swaig query params.
        $params = $this->swaigQueryParams;
        if ($token !== null && $token !== '') {
            $params['__token'] = $token;
        }
        if (!empty($params)) {
            $authUrl .= '?' . http_build_query($params);
        }

        return $authUrl;
    }

    /**
     * Resolve the proxy URL base, preferring manual override.
     *
     * @param array<string, string> $headers
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
     *
     * @template T of array<array-key, mixed>
     * @param T $array
     * @return T
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
        /** @var T $copy */
        return $copy;
    }
}
