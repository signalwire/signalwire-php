<?php

declare(strict_types=1);

namespace SignalWire\SWML;

use SignalWire\Core\SecurityConfig;
use SignalWire\Logging\Logger;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\Utils\SchemaUtils;

/**
 * SWML service — builds and serves an SWML document over HTTP.
 *
 * Every SWML schema verb is auto-vivified through {@see __call()} and
 * dispatched as
 * `$service->verb([$section], [$config])` — the first argument may be the
 * target section name OR the verb config array (see __call). Because that
 * receiver arity is genuinely polymorphic, the verbs are documented on
 * __call rather than as fixed-signature `@method` tags. (SWMLBuilder, whose
 * verb signature is the simpler `verb(array $config = [])`, DOES carry
 * per-verb @method tags.)
 */
class Service implements RequestHandlerLike
{
    protected string $name;
    protected string $route;
    protected string $host;
    protected int $port;
    protected Document $document;
    /**
     * SchemaUtils helper exposed via getSchemaUtils(). Built lazily so
     * existing subclasses constructed without the schema env still work.
     */
    protected ?SchemaUtils $schemaUtils = null;

    /**
     * Constructor-supplied schema path, forwarded to SchemaUtils when the
     * helper is built. Null means "use the bundled schema.json".
     */
    protected ?string $schemaPath = null;

    /**
     * Whether SWML schema validation is enabled. Forwarded to SchemaUtils
     * (which additionally honours SWML_SKIP_SCHEMA_VALIDATION).
     */
    protected bool $schemaValidation = true;

    /**
     * Unified security configuration (SSL/CORS/HSTS/basic-auth/limits), built
     * from defaults + env + the optional config file.
     */
    protected SecurityConfig $security;

    /**
     * Whether TLS serving is enabled. Mirrored off ``$this->security`` at
     * construction. Public because it is a caller-observable VALUE: a caller
     * reads it to know which scheme the service serves, and may SET it before
     * ``serve()`` to flip TLS on.
     */
    public bool $sslEnabled = false;

    /**
     * TLS certificate path (PEM). Mirrored off ``$this->security``; see
     * ``$sslEnabled``. Null when TLS is not configured.
     */
    public ?string $sslCertPath = null;

    /**
     * TLS private-key path (PEM). Mirrored off ``$this->security``; see
     * ``$sslEnabled``. Null when TLS is not configured.
     */
    public ?string $sslKeyPath = null;

    /**
     * The domain this service is served under. Mirrored off
     * ``$this->security``. Used as the host part of the public URL when TLS
     * is enabled.
     */
    public ?string $domain = null;

    protected Logger $logger;

    protected string $basicAuthUser;
    protected string $basicAuthPassword;

    /** @var array<string, callable> */
    protected array $routingCallbacks = [];

    /**
     * Registry of specialized verb handlers (e.g. the "ai" verb). Consulted
     * by add_verb / add_verb_to_section before falling back to schema
     * validation.
     */
    protected VerbHandlerRegistry $verbRegistry;

    /**
     * Manually-set proxy base URL for webhook URL generation. Lives on the
     * base Service so both a plain SWMLService and AgentBase share one
     * field; getProxyUrlBase() reads it.
     */
    protected ?string $manualProxyUrl = null;

    /**
     * Whether the web server is running. Flipped off by stop().
     */
    protected bool $running = false;

    /**
     * Whether X-Forwarded-Proto / X-Forwarded-Host may be honoured when
     * reconstructing the URL that webhook signatures are validated against.
     * Lives on the base Service because reconstructPublicUrl() does; the
     * constructor knob is AgentBase's `trustProxyForSignature`. Defaults
     * false: proxy headers are spoofable.
     */
    protected bool $trustProxyForSignature = false;

    /**
     * SWAIG tool registry — lifted from AgentBase so any Service (sidecar,
     * non-agent verb host, etc.) can register and dispatch SWAIG functions.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $tools = [];

    /** @var list<string> */
    protected array $toolOrder = [];

    /**
     * @param string $name
     * @param string $route
     * @param string|null $host
     * @param int|null $port
     * @param string|null $basicAuthUser
     * @param string|null $basicAuthPassword
     * @param string|null $schemaPath Optional path to a SWML schema.json;
     *   null uses the bundled schema. Forwarded to SchemaUtils.
     * @param string|null $configFile Optional path to a JSON configuration
     *   file. Forwarded to SecurityConfig, which layers its `security`
     *   section over env defaults (basic auth, SSL, CORS, limits). When null,
     *   the standard search paths are consulted for `<name>_config.json` etc.
     * @param bool $schemaValidation Enable SWML schema validation (default
     *   true). Can also be disabled via SWML_SKIP_SCHEMA_VALIDATION=1.
     */
    public function __construct(
        string $name,
        string $route = '/',
        ?string $host = null,
        ?int $port = null,
        ?string $basicAuthUser = null,
        ?string $basicAuthPassword = null,
        ?string $schemaPath = null,
        ?string $configFile = null,
        bool $schemaValidation = true,
    ) {
        $this->name = $name;
        $this->schemaPath = $schemaPath;
        $this->schemaValidation = $schemaValidation;
        $this->route = rtrim($route, '/') ?: '/';
        $this->host = $host ?? '0.0.0.0';
        if ($port !== null) {
            $this->port = $port;
        } else {
            $envPort = $_ENV['PORT'] ?? getenv('PORT');
            $this->port = (is_string($envPort) || is_int($envPort)) && (int) $envPort !== 0
                ? (int) $envPort
                : 3000;
        }
        $this->document = new Document();
        $this->verbRegistry = new VerbHandlerRegistry();
        $this->logger = Logger::getLogger('swml_service');

        // Unified security config (defaults -> env -> config file). Mirrors
        // Python SWMLService.__init__: `self.security = SecurityConfig(
        // config_file=config_file, service_name=name)`.
        $this->security = new SecurityConfig(configFile: $configFile, serviceName: $name);

        // Mirror the TLS-serving values onto the service itself, exactly as the
        // reference does (core/swml_service.py:143-146). These are the values a
        // caller reads to know how the service is served, and writes to flip TLS
        // on before serve().
        $this->sslEnabled = $this->security->sslEnabled;
        $this->domain = $this->security->domain;
        $this->sslCertPath = $this->security->sslCertPath;
        $this->sslKeyPath = $this->security->sslKeyPath;

        // Auth: explicit > env > config file > auto-generated. The middle two
        // layers both live in SecurityConfig (env first, then the config
        // file's `security` section overriding it), mirroring Python's
        // `self._basic_auth = self.security.get_basic_auth()` fallback.
        $passwordAutoGenerated = false;
        if ($basicAuthUser !== null && $basicAuthPassword !== null) {
            $this->basicAuthUser = $basicAuthUser;
            $this->basicAuthPassword = $basicAuthPassword;
        } elseif ($this->security->basicAuthUser !== null
            && $this->security->basicAuthUser !== ''
            && $this->security->basicAuthPassword !== null
            && $this->security->basicAuthPassword !== '') {
            $this->basicAuthUser = $this->security->basicAuthUser;
            $this->basicAuthPassword = $this->security->basicAuthPassword;
        } else {
            $envUserOnly = getenv('SWML_BASIC_AUTH_USER');
            $this->basicAuthUser = is_string($envUserOnly) && $envUserOnly !== ''
                ? $envUserOnly
                : $this->randomHex(16);
            $this->basicAuthPassword = $this->randomHex(32);
            $passwordAutoGenerated = true;
        }

        $this->logger->info("Service '{$this->name}' initialised (route={$this->route}, port={$this->port})");

        // Warn loudly if the password was auto-generated. This is the
        // silent cause of every external caller hitting HTTP 401 when
        // .env wasn't loaded — the password lives only in this process
        // and changes on every restart.
        if ($passwordAutoGenerated) {
            $this->logger->warn(
                "basic_auth_password_autogenerated: username=\"{$this->basicAuthUser}\". "
                . 'No SWML_BASIC_AUTH_PASSWORD found in environment and no '
                . 'basic_auth_password passed to the agent constructor. The SDK '
                . 'generated a random password that exists only in this process; '
                . 'external callers will get HTTP 401 unless they read the value '
                . "from this process's env. To fix, set SWML_BASIC_AUTH_USER and "
                . 'SWML_BASIC_AUTH_PASSWORD in your environment, or pass '
                . "'basic_auth_user' and 'basic_auth_password' to the agent constructor."
            );
        }
    }

    // ------------------------------------------------------------------
    // Verb auto-vivification via __call
    // ------------------------------------------------------------------

    /**
     * Dynamic verb methods from schema.
     *
     *   $service->answer('main', ['max_duration' => 3600]);
     *   $service->sleep('main', 2000);
     *   $service->hangup();
     *
     * @param list<mixed> $args
     */
    public function __call(string $method, array $args): static
    {
        $schema = Schema::instance();
        if (!$schema->isValidVerb($method)) {
            throw new \BadMethodCallException("Unknown method: {$method}");
        }

        $section = 'main';
        $config = [];

        if ($method === 'sleep') {
            // sleep(2000) or sleep('main', 2000)
            if (count($args) === 1 && is_int($args[0])) {
                $config = $args[0];
            } elseif (count($args) === 2 && is_string($args[0]) && is_int($args[1])) {
                $section = $args[0];
                $config = $args[1];
            } else {
                throw new \InvalidArgumentException('sleep requires an integer duration');
            }
        } else {
            // verb() or verb({}) or verb('section') or verb('section', {})
            if (count($args) === 0) {
                // defaults
            } elseif (count($args) === 1) {
                if (is_string($args[0])) {
                    $section = $args[0];
                } elseif (is_array($args[0])) {
                    $config = $args[0];
                }
            } elseif (count($args) === 2) {
                $section = is_string($args[0]) ? $args[0] : 'main';
                $config = is_array($args[1]) ? $args[1] : [];
            }
        }

        // Route through the VALIDATING addVerbToSection, not the raw Document.
        // A caller writing `$service->play([...])` reads exactly like the
        // validating `$service->addVerb('play', [...])` and must behave the
        // same; going raw here made this a silent second entry point that
        // accepted schema-invalid configs. Mirrors the reference's generated
        // verb methods, which call `self_instance.add_verb(name, config)`
        // (core/swml_service.py:316) — the validating path.
        $this->addVerbToSection($section, $method, $config);
        return $this;
    }

    // ------------------------------------------------------------------
    // Auth helpers
    // ------------------------------------------------------------------

    /**
     * @return array{string, string} [user, password]
     */
    public function getBasicAuthCredentials(): array
    {
        return [$this->basicAuthUser, $this->basicAuthPassword];
    }

    /** Validate provided basic-auth credentials against the configured ones
     * using a constant-time comparison. */
    public function validateBasicAuth(string $username, string $password): bool
    {
        return hash_equals($this->basicAuthUser, $username)
            && hash_equals($this->basicAuthPassword, $password);
    }

    /** Get (user, password, source) where source is "provided",
     * "environment", or "generated".
     *
     * @return array{string, string, string} [user, password, source]
     */
    public function getBasicAuthCredentialsWithSource(): array
    {
        $envUser = getenv('SWML_BASIC_AUTH_USER');
        $envPass = getenv('SWML_BASIC_AUTH_PASSWORD');
        if ($envUser !== false && $envUser !== ''
            && $envPass !== false && $envPass !== ''
            && $this->basicAuthUser === $envUser
            && $this->basicAuthPassword === $envPass) {
            $source = 'environment';
        } elseif (str_starts_with($this->basicAuthUser, 'user_') && strlen($this->basicAuthPassword) > 20) {
            $source = 'generated';
        } else {
            $source = 'provided';
        }
        return [$this->basicAuthUser, $this->basicAuthPassword, $source];
    }

    /**
     * Build the full URL for this service.
     *
     * Honours the TLS-serving values ($sslEnabled / $domain): https when TLS
     * is on, the domain as the host part when one is configured, and the port
     * elided for the scheme's standard port (443/80).
     */
    public function getFullUrl(bool $includeAuth = false): string
    {
        $auth = $includeAuth
            ? "{$this->basicAuthUser}:{$this->basicAuthPassword}@"
            : '';
        $scheme = $this->sslEnabled ? 'https' : 'http';
        if ($this->sslEnabled && $this->domain !== null && $this->domain !== '') {
            $hostPart = ($this->port === 443 || $this->port === 80)
                ? $this->domain
                : "{$this->domain}:{$this->port}";
        } else {
            $hostPart = "{$this->host}:{$this->port}";
        }
        $path = $this->route;
        return "{$scheme}://{$auth}{$hostPart}{$path}";
    }

    // ------------------------------------------------------------------
    // Routing callbacks
    // ------------------------------------------------------------------

    /**
     * Register a routing callback at ``$path`` (default "/sip").
     *
     * @param callable(array<string,mixed>, array<string,mixed>): ?string $callback
     */
    public function registerRoutingCallback(callable $callback, string $path = '/sip'): void
    {
        // Normalize the path for consistent lookup (Python parity: strip a
        // trailing slash, ensure a single leading slash). "/sip/" -> "/sip",
        // "voice" -> "/voice".
        $normalized = rtrim($path, '/');
        if (!str_starts_with($normalized, '/')) {
            $normalized = '/' . $normalized;
        }
        $this->routingCallbacks[$normalized] = $callback;
    }

    // ------------------------------------------------------------------
    // SWML document mutation (Python SWMLService parity)
    // ------------------------------------------------------------------

    /**
     * Add a verb to the main section of the current document.
     *
     * Consults the verb-handler registry first (e.g. the "ai" verb), falling
     * back to schema-based validation for standard verbs. Raises
     * {@see \SignalWire\Utils\SchemaValidationError} on an invalid config.
     *
     * @param array<string, mixed>|int $config Verb config, or a direct integer
     *                                          for verbs like `sleep`.
     * @return bool True if the verb was added, false if the config was rejected
     *              as the wrong type (non-array for a non-direct-value verb).
     */
    public function addVerb(string $verbName, array|int $config): bool
    {
        return $this->addVerbToSection('main', $verbName, $config);
    }

    /**
     * Add a new section to the document.
     *
     * Returns true if created, false if the section already existed.
     */
    public function addSection(string $sectionName): bool
    {
        return $this->document->addSection($sectionName);
    }

    /**
     * Add a verb to a specific named section (auto-creating it if missing).
     *
     * Consults the verb-handler registry first, falling back to schema
     * validation; raises {@see \SignalWire\Utils\SchemaValidationError} on an
     * invalid config.
     *
     * @param array<string, mixed>|int $config
     */
    public function addVerbToSection(string $sectionName, string $verbName, array|int $config): bool
    {
        // Make sure the section exists.
        if (!$this->document->hasSection($sectionName)) {
            $this->document->addSection($sectionName);
        }

        // Special case for verbs that take a direct integer value (like sleep).
        if ($verbName === 'sleep' && is_int($config)) {
            $this->document->addVerbToSection($sectionName, $verbName, $config);
            return true;
        }

        if (!is_array($config)) {
            $this->logger->warn(
                "invalid_config_type: verb=\"{$verbName}\" section=\"{$sectionName}\" "
                . 'expected=array got=int. Only the sleep verb accepts a direct integer.'
            );
            return false;
        }

        // Handler-based validation takes priority over schema validation.
        $handler = $this->verbRegistry->getHandler($verbName);
        if ($handler !== null) {
            [$isValid, $errors] = $handler->validateConfig($config);
            // A handler's validateConfig carries verb-specific diagnostics
            // (e.g. the ai verb's prompt/SWAIG shape checks) but does NOT run
            // the schema's closed-key check -- so unknown/misspelled top-level
            // keys would slip through silently (r5 silent-drop family, GAP1).
            // Run the schema pass too so a handler verb rejects stray keys like
            // every other verb. The schema pass is a no-op when validation is
            // disabled, so this never tightens the validation-off path. Mirrors
            // Python SWMLService.add_verb / add_verb_to_section.
            if ($isValid) {
                [$isValid, $errors] = $this->getSchemaUtils()->validateVerb($verbName, $config);
            }
        } else {
            [$isValid, $errors] = $this->getSchemaUtils()->validateVerb($verbName, $config);
        }

        if (!$isValid) {
            throw new \SignalWire\Utils\SchemaValidationError($verbName, $errors);
        }

        $this->document->addVerbToSection($sectionName, $verbName, $config);
        return true;
    }

    /**
     * Reset the current document to an empty state.
     */
    public function resetDocument(): void
    {
        $this->document->reset();
    }

    /**
     * Render the current SWML document as a JSON string.
     */
    public function renderDocument(): string
    {
        return $this->document->render();
    }

    /**
     * Register a custom verb handler.
     */
    public function registerVerbHandler(SWMLVerbHandler $handler): void
    {
        $this->verbRegistry->registerHandler($handler);
    }

    /**
     * Whether full JSON Schema validation is enabled.
     */
    public function fullValidationEnabled(): bool
    {
        return $this->getSchemaUtils()->fullValidationAvailable();
    }

    /**
     * Return this service as a mountable request handler.
     *
     * PHP has no framework router, so the Service itself — which implements
     * {@see RequestHandlerLike} via handleRequest — IS the mountable unit.
     * Callers mount the returned handler into their framework and dispatch
     * through it.
     */
    public function asRouter(): RequestHandlerLike
    {
        return $this;
    }

    /**
     * Manually override the proxy base URL used for webhook URL generation.
     */
    public function manualSetProxyUrl(string $url): static
    {
        $this->manualProxyUrl = rtrim($url, '/');
        return $this;
    }

    /**
     * Stop the web server — flips the running flag off.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    // ------------------------------------------------------------------
    // SWAIG tool registry (lifted from AgentBase)
    // ------------------------------------------------------------------

    /**
     * Define a SWAIG function the AI can call.
     *
     * Tool descriptions and parameter descriptions are LLM-facing prompt
     * engineering, not internal documentation. See PORTING_GUIDE for guidance.
     *
     * @param array<string, mixed> $parameters JSON-Schema `properties` map for the tool argument,
     *   OR a COMPLETE JSON-Schema object ({type, properties[, required]}) which is passed through
     *   as-is.
     * @param array<string, mixed> $extraFields Additional SWAIG-only fields (e.g.
     *   `meta_data_token`, `web_hook_auth_user`) merged at the TOP LEVEL of the generated
     *   function definition — siblings of `argument`, NOT nested inside it.
     */
    public function defineTool(
        string $name,
        string $description,
        array $parameters,
        callable $handler,
        bool $secure = true,
        array $extraFields = [],
    ): static {
        $this->tools[$name] = array_merge($extraFields, [
            'function' => $name,
            'purpose' => $description,
            'argument' => $this->ensureParameterStructure($parameters),
            '_handler' => $handler,
            '_secure' => $secure,
        ]);
        if (!in_array($name, $this->toolOrder, true)) {
            $this->toolOrder[] = $name;
        }
        return $this;
    }

    /**
     * Normalize a defineTool $parameters value into the SWML argument schema.
     *
     * When $parameters is a bare `properties` map it is wrapped in
     * {type: object, properties: $parameters}. When it is ALREADY a complete
     * JSON-Schema object (has both `type` and `properties`) it is passed
     * through unchanged — wrapping it would double-nest the schema.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function ensureParameterStructure(array $parameters): array
    {
        if (array_key_exists('type', $parameters) && array_key_exists('properties', $parameters)) {
            return $parameters;
        }
        return [
            'type' => 'object',
            'properties' => $parameters,
        ];
    }

    /**
     * Register a raw SWAIG function definition (e.g. DataMap tools).
     *
     * @param array<string, mixed> $funcDef
     */
    public function registerSwaigFunction(array $funcDef): static
    {
        $name = $funcDef['function'] ?? '';
        if (!is_string($name) || $name === '') {
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
     *
     * @param list<array<string, mixed>> $toolDefs
     */
    public function defineTools(array $toolDefs): static
    {
        foreach ($toolDefs as $def) {
            $this->registerSwaigFunction($def);
        }
        return $this;
    }

    /**
     * Return the registered SWAIG tool definitions.
     *
     * Used by introspection (CLI --list-tools, the skills audit
     * harness, and any test that needs to inspect what's been
     * registered without going through a HTTP round trip).
     *
     * @return array<string, array<string, mixed>> name => tool definition
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /** Whether a SWAIG function with the given name is registered. */
    public function hasFunction(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** Get a registered SWAIG function by name, or null when absent.
     *
     * @return array<string, mixed>|null */
    public function getFunction(string $name): ?array
    {
        return $this->tools[$name] ?? null;
    }

    /** Snapshot of all registered SWAIG functions keyed by name.
     *
     * @return array<string, array<string, mixed>> */
    public function getAllFunctions(): array
    {
        return $this->tools;  // copy on read in PHP arrays
    }

    /** Remove a registered SWAIG function. True on success, false if absent. */
    public function removeFunction(string $name): bool
    {
        if (!isset($this->tools[$name])) {
            return false;
        }
        unset($this->tools[$name]);
        return true;
    }

    /**
     * Return the names of registered tools in the order they were
     * registered. Stable ordering matters for CLI output and for
     * tests that rely on deterministic enumeration.
     *
     * @return list<string>
     */
    public function getToolNames(): array
    {
        return $this->toolOrder;
    }

    /**
     * Dispatch a function call to the registered handler.
     *
     * @param array<string, mixed>      $args    parsed function arguments
     * @param array<string, mixed>|null $rawData full SWAIG request payload
     */
    public function onFunctionCall(string $name, array $args, ?array $rawData = null): ?FunctionResult
    {
        if (!isset($this->tools[$name])) {
            return null;
        }
        $tool = $this->tools[$name];
        $handler = $tool['_handler'] ?? null;
        if ($handler === null || !is_callable($handler)) {
            return null;
        }

        try {
            $result = $handler($args, $rawData);
        } catch (\Throwable $e) {
            return new FunctionResult("Error executing function '{$name}': {$e->getMessage()}");
        }

        if ($result instanceof FunctionResult) {
            return $result;
        }
        if (is_array($result)) {
            if (isset($result['response']) && is_string($result['response'])) {
                return new FunctionResult($result['response']);
            }
            return new FunctionResult((string) json_encode($result));
        }
        $type = is_object($result) ? get_class($result) : gettype($result);
        $this->logger->warn(
            "unexpected_function_result_type: function=\"{$name}\" "
            . "result_type=\"{$type}\". SWAIG function returned a "
            . 'value that is neither a FunctionResult nor an array; '
            . 'falling back to wrapping the stringified value. Return '
            . 'a \\SignalWire\\SWAIG\\FunctionResult object or an array '
            . "with at least a 'response' key."
        );
        return new FunctionResult((string) $result);
    }

    /**
     * Enforce `secure=true` for one SWAIG call, independent of transport.
     *
     * This is the SOLE security decision for a SWAIG call, deliberately free of
     * any request/transport type so that EVERY transport — the direct HTTP
     * dispatcher and all four serverless envelopes (lambda, cgi, google cloud
     * function, azure function) — reaches the identical check with identical
     * semantics. A transport is responsible only for EXTRACTING the credential
     * from its own payload shape; none of them re-implements the decision.
     *
     * A tool registered with `secure: true` REQUIRES a valid `__token`. An
     * ABSENT token is refused exactly like an invalid one — omitting the
     * credential must never be weaker than presenting a wrong one, or `secure`
     * would be a flag that permits anonymous calls. Likewise a missing
     * `$callId`: a token is only meaningful bound to a call, so with none there
     * is nothing to validate against and the call counts as unvalidated.
     *
     * The refusal is delivered as a **200 + FunctionResult body**, never an
     * HTTP error status: the engine has no handling for a SWAIG refusal
     * status, so the tool reports that it cannot execute and the model relays
     * that to the caller.
     *
     * @param  string|null $token   The `__token` credential, or null when absent.
     * @param  string|null $callId  The call the token must be bound to, or null.
     * @return array<string, mixed>|null null to proceed with dispatch, or the
     *         FunctionResult-shaped refusal to return INSTEAD of dispatching.
     */
    protected function swaigValidateToken(
        string $functionName,
        ?string $token,
        ?string $callId,
    ): ?array {
        $func = $this->tools[$functionName] ?? null;
        if ($func === null) {
            // Unknown function: not this hook's decision — dispatch reports it.
            return null;
        }

        // No token machinery on this service — nothing can be minted, so
        // nothing can be validated, and enforcing would make every secure tool
        // permanently unreachable rather than protected. The reference draws
        // the same line: its base SWMLService hook returns "proceed", and the
        // enforcing override is guarded on the session manager's presence
        // (`hasattr(self, "_session_manager")`). Enforcement therefore begins
        // exactly where credentials can exist: at AgentBase.
        if (!$this->hasSwaigTokenValidation()) {
            return null;
        }

        // A token can only be validated against a call_id; without one there is
        // nothing to check it against, so treat it as unvalidated.
        $isValid = false;
        if ($token !== null && $token !== '' && $callId !== null && $callId !== '') {
            $isValid = $this->validateSwaigToolToken($functionName, $token, $callId);
        }

        if ($isValid) {
            return null;
        }

        if (($func['_secure'] ?? true) === true) {
            $this->logger->warn(
                'secure_function_refused: function="' . $functionName . '" '
                . 'token_present="' . (($token !== null && $token !== '') ? 'true' : 'false') . '"'
            );
            return (new FunctionResult(
                "I'm sorry, the security token for this function is invalid "
                . 'or expired. I cannot execute this action.'
            ))->toArray();
        }

        // secure: false — the tool runs ungated regardless of the credential.
        return null;
    }

    /**
     * Whether this service can validate per-call SWAIG tool tokens at all.
     *
     * False on the bare Service (it has no session manager, so it can neither
     * MINT nor check a token); AgentBase overrides it to true. This is what
     * scopes enforcement to the services that actually issue credentials —
     * see {@see swaigValidateToken()} for why.
     */
    protected function hasSwaigTokenValidation(): bool
    {
        return false;
    }

    /**
     * Validate a per-call SWAIG tool token. Only ever reached when
     * {@see hasSwaigTokenValidation()} is true; AgentBase overrides it to
     * delegate to its SessionManager.
     */
    protected function validateSwaigToolToken(string $functionName, string $token, string $callId): bool
    {
        return false;
    }

    /**
     * Extract the `__token` credential from a parsed query mapping.
     *
     * The reserved `__token` name is preferred (it cannot collide with a
     * caller's own `token` parameter); a bare `token` is accepted as an
     * alias.
     *
     * @param array<string, mixed> $query
     */
    protected static function swaigTokenFromQuery(array $query): ?string
    {
        foreach (['__token', 'token'] as $key) {
            $value = $query[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * Extension point: invoked between argument parsing and function dispatch.
     * AgentBase may override to add ephemeral per-request config. Returns
     * [target, shortCircuit]: shortCircuit non-null replies directly without
     * dispatch.
     *
     * Security is NOT decided here — {@see swaigValidateToken()} owns that, and
     * {@see handleSwaigRequest()} runs it BEFORE this hook, so a subclass that
     * overrides this hook cannot accidentally drop token enforcement.
     *
     * @param array<string, mixed> $requestData
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @return array{0: self, 1: ?array<string, mixed>}
     */
    protected function swaigPreDispatch(
        array $requestData,
        array $headers,
        string $functionName,
        array $query = [],
    ): array {
        return [$this, null];
    }

    // ------------------------------------------------------------------
    // SWML customization hooks
    // ------------------------------------------------------------------

    /**
     * Customization hook called when SWML is requested. Default
     * delegates to {@see onSwmlRequest()}; subclasses typically
     * override `onSwmlRequest` rather than this method.
     *
     * Return null to use the default SWML rendering, or an array of
     * modifications to merge into the rendered document.
     *
     * @param array<string, mixed>|null $requestData
     * @return array<string, mixed>|null
     */
    public function onRequest(?array $requestData = null, ?string $callbackPath = null): ?array
    {
        return $this->onSwmlRequest($requestData, $callbackPath);
    }

    /**
     * Customization point for subclasses to modify SWML based on
     * request data. The default implementation returns null (no
     * modification). Subclasses override to inspect the body or
     * callback path and return an associative array of overrides.
     *
     * @param array<string, mixed>|null $requestData
     * @return array<string, mixed>|null
     */
    public function onSwmlRequest(?array $requestData = null, ?string $callbackPath = null): ?array
    {
        return null;
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    /** The name. */
    public function getName(): string
    {
        return $this->name;
    }

    /** The route. */
    public function getRoute(): string
    {
        return $this->route;
    }

    /**
     * This service itself.
     *
     * SWAIG registration is flattened ONTO the service rather than split into
     * a registry collaborator that holds a reference back to it, so the
     * back-reference an SDK consumer would follow resolves to `$this`.
     */
    public function getAgent(): self
    {
        return $this;
    }

    /** The host. */
    public function getHost(): string
    {
        return $this->host;
    }

    /** The port. */
    public function getPort(): int
    {
        return $this->port;
    }

    /** The document. */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * SchemaUtils helper bound to this Service, built from the constructor's
     * `schemaPath` / `schemaValidation`. Built lazily on first access.
     */
    public function getSchemaUtils(): SchemaUtils
    {
        if ($this->schemaUtils === null) {
            $this->schemaUtils = new SchemaUtils($this->schemaPath, $this->schemaValidation);
        }
        return $this->schemaUtils;
    }

    /**
     * Render this service's document as compact SWML JSON, with slashes and
     * unicode left unescaped.
     *
     * Renders the DOCUMENT as built — it does not go through
     * {@see Service::renderSwml()}, so a subclass that overrides that
     * per-request hook is bypassed here.
     *
     * @throws \RuntimeException if the document cannot be JSON-encoded.
     */
    public function render(): string
    {
        return $this->document->render();
    }

    /**
     * As {@see Service::render()}, but pretty-printed for human reading.
     * Same content, different whitespace.
     *
     * @throws \RuntimeException if the document cannot be JSON-encoded.
     */
    public function renderPretty(): string
    {
        return $this->document->renderPretty();
    }

    /**
     * Render SWML for a request. Subclasses override this.
     *
     * @param array<string, mixed>|null $requestBody
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function renderSwml(?array $requestBody = null, array $headers = []): array
    {
        return $this->document->toArray();
    }

    // ------------------------------------------------------------------
    // HTTP handling (PHP built-in server / CGI / SAPI)
    // ------------------------------------------------------------------

    /**
     * Handle an HTTP request. Returns [status, headers, body].
     *
     * @param array<string, string> $headers
     * @return array{int, array<string, string>, string}
     */
    public function handleRequest(
        string $method,
        string $path,
        array $headers,
        ?string $body = null,
    ): array {
        // Split any query string off the path BEFORE routing. Routing compares
        // $path against $this->route by exact match / prefix, so a path that
        // still carries "?..." would never match and would 404. The parsed
        // query is load-bearing for SWAIG: the per-call `__token` credential
        // rides the query string on every transport (the call_id rides the
        // POST body), so it must survive to handleSwaigRequest().
        $query = [];
        $qPos = strpos($path, '?');
        if ($qPos !== false) {
            $parsed = [];
            parse_str(substr($path, $qPos + 1), $parsed);
            // parse_str yields int keys for numeric parameter names; the query
            // map is string-keyed by contract, so those are dropped.
            foreach ($parsed as $k => $v) {
                if (is_string($k)) {
                    $query[$k] = $v;
                }
            }
            $path = substr($path, 0, $qPos);
        }

        // Health/ready: no auth
        if ($path === '/health') {
            return $this->jsonResponse(200, ['status' => 'healthy']);
        }
        if ($path === '/ready') {
            return $this->jsonResponse(200, ['status' => 'ready']);
        }

        // Determine if path matches our route
        $normalRoute = $this->route === '/' ? '' : $this->route;
        $subPath = null;

        if ($this->route === '/') {
            $subPath = $path;
        } elseif ($path === $this->route || str_starts_with($path, $this->route . '/')) {
            $subPath = substr($path, strlen($this->route)) ?: '/';
        }

        if ($subPath === null) {
            return $this->jsonResponse(404, ['error' => 'Not found']);
        }

        // Auth required for everything under the route.
        // Python parity (SWMLService._handle_request_core): the framework-free
        // core returns a bare (401, {"WWW-Authenticate": "Basic"}, JSON error)
        // triple. Security headers / Content-Type are applied by the serving
        // layer (WebService / AgentServer), mirroring Python's FastAPI
        // add_security_headers middleware — NOT baked into this primitive.
        if (!$this->checkBasicAuth($headers)) {
            return [
                401,
                ['WWW-Authenticate' => 'Basic'],
                (string) json_encode(['error' => 'Unauthorized']),
            ];
        }

        // Parse body
        $requestData = null;
        if ($body !== null && $body !== '') {
            // Enforce 1MB body size limit
            if (strlen($body) > 1_048_576) {
                return $this->jsonResponse(413, ['error' => 'Request body too large']);
            }
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $requestData = [];
                foreach ($decoded as $k => $v) {
                    if (is_string($k)) {
                        $requestData[$k] = $v;
                    }
                }
            }
        }

        // Webhook signature validation (when signing_key is configured) —
        // applies to POSTs of the signed routes only: /, /swaig, /post_prompt.
        // Subclasses (AgentBase) carry $signingKey; Service queries via
        // property_exists for loose coupling, mirroring manualProxyUrl.
        if (
            strtoupper($method) === 'POST'
            && in_array($subPath, ['/', '', '/swaig', '/post_prompt'], true)
            && property_exists($this, 'signingKey')
            && is_string($this->signingKey ?? null)
            && $this->signingKey !== ''
        ) {
            $sigCheck = $this->checkWebhookSignature($headers, $body ?? '', $path, $subPath);
            if ($sigCheck !== null) {
                return $sigCheck;
            }
        }

        // Route dispatch
        if ($subPath === '/' || $subPath === '') {
            return $this->handleSwmlRequest($method, $requestData, $headers);
        }
        if ($subPath === '/swaig') {
            return $this->handleSwaigRequest($method, $requestData, $headers, $query);
        }
        if ($subPath === '/post_prompt') {
            return $this->handlePostPrompt($requestData, $headers);
        }

        // Check routing callbacks. Mirrors Python's routing-callback contract
        // (agent_base.py:1757, swml_service.py:1063): the callback is
        // (body, headers) -> route|null.
        //   - returns a route string  -> 307 redirect preserving method+body,
        //     with the Location header set to that route.
        //   - returns null            -> the request is handled here; render
        //     and serve the SWML document for this service (200).
        // A stored-but-unconsulted mapping (returning the callback result as a
        // 200 JSON blob) would never redirect and would fail the served-path
        // SIP dispatch contract.
        if (isset($this->routingCallbacks[$subPath])) {
            $route = null;
            try {
                $route = ($this->routingCallbacks[$subPath])($requestData ?? [], $headers);
            } catch (\Throwable $e) {
                $this->logger->error("error_in_routing_callback: {$e->getMessage()}");
                $route = null;
            }
            if (is_string($route) && $route !== '') {
                $this->logger->info("routing_request route={$route}");
                // Python parity: bare (307, {"Location": route}, "") from the
                // framework-free core; security headers are a serving-layer
                // concern (WebService / AgentServer).
                return [
                    307,
                    ['Location' => $route],
                    '',
                ];
            }
            // Callback declined to redirect: serve this service's SWML.
            return $this->handleSwmlRequest($method, $requestData, $headers);
        }

        return $this->jsonResponse(404, ['error' => 'Not found']);
    }

    /**
     * Handle SWML document request.
     */
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $requestData
     * @return array{int, array<string, string>, string}
     */
    protected function handleSwmlRequest(string $method, ?array $requestData, array $headers): array
    {
        $swml = $this->renderSwml($requestData, $headers);
        // Python parity: the framework-free core returns a bare (200, {}, body)
        // triple for the served SWML document (no Content-Type / security
        // headers — those belong to the serving/middleware layer).
        $body = json_encode($swml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('json_encode failed');
        }
        return [200, [], $body];
    }

    /**
     * Handle SWAIG function dispatch.
     *
     * GET: return the rendered SWML document (parallel to root /).
     * POST: parse {function, argument, call_id}, enforce `secure` via
     * {@see swaigValidateToken()}, run the pre-dispatch hook, call
     * onFunctionCall, return the FunctionResult.
     *
     * Lifted from AgentBase so non-agent SWMLServices (e.g. ai_sidecar host)
     * can serve /swaig without subclassing AgentBase.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $requestData
     * @param array<string, mixed> $query parsed query string — where the
     *        per-call `__token` credential rides, on every transport.
     * @return array{int, array<string, string>, string}
     */
    protected function handleSwaigRequest(
        string $method,
        ?array $requestData,
        array $headers,
        array $query = [],
    ): array {
        if (strtoupper($method) === 'GET') {
            $swml = $this->renderSwml($requestData, $headers);
            return $this->jsonResponse(200, $swml);
        }

        if ($requestData === null) {
            return $this->jsonResponse(400, ['error' => 'Missing request body']);
        }

        $functionName = $requestData['function'] ?? '';
        if (!is_string($functionName) || $functionName === '') {
            return $this->jsonResponse(400, ['error' => 'Missing function name']);
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $functionName)) {
            return $this->jsonResponse(400, ['error' => "Invalid function name format: '{$functionName}'"]);
        }

        $argument = $requestData['argument'] ?? null;
        $parsedFirst = null;
        if (is_array($argument)) {
            $parsed = $argument['parsed'] ?? null;
            if (is_array($parsed)) {
                $parsedFirst = $parsed[0] ?? null;
            }
        }
        $args = $parsedFirst
            ?? (is_array($requestData['arguments'] ?? null) ? $requestData['arguments'] : []);
        if (!is_array($args)) {
            $args = [];
        }

        // Security FIRST, and unconditionally — before the (overridable)
        // pre-dispatch hook and before any handler runs. The credential rides
        // the query string; the call_id rides the POST body. That split is
        // identical on every transport, so serverless is not a weaker one.
        $callIdRaw = $requestData['call_id'] ?? null;
        $callId = is_string($callIdRaw) && $callIdRaw !== '' ? $callIdRaw : null;
        $refusal = $this->swaigValidateToken($functionName, self::swaigTokenFromQuery($query), $callId);
        if ($refusal !== null) {
            return $this->jsonResponse(200, $refusal);
        }

        [$target, $shortCircuit] = $this->swaigPreDispatch($requestData, $headers, $functionName, $query);
        if ($shortCircuit !== null) {
            return $this->jsonResponse(200, $shortCircuit);
        }

        $result = $target->onFunctionCall($functionName, $args, $requestData);
        if ($result === null) {
            return $this->jsonResponse(404, ['error' => "Unknown function: {$functionName}"]);
        }
        return $this->jsonResponse(200, $result->toArray());
    }

    /**
     * Handle post-prompt callback. Override in AgentBase.
     *
     * @param array<string, mixed>|null $requestData
     * @param array<string, string> $headers
     * @return array{int, array<string, string>, string}
     */
    protected function handlePostPrompt(?array $requestData, array $headers): array
    {
        return $this->jsonResponse(200, []);
    }

    // ------------------------------------------------------------------
    // SIP username extraction
    // ------------------------------------------------------------------

    /**
     * Extract SIP username from a request body.
     * Validates format: only [a-zA-Z0-9._-], max 64 chars.
     *
     * @param array<string, mixed>|null $requestBody
     */
    public static function extractSipUsername(?array $requestBody): ?string
    {
        if ($requestBody === null) {
            return null;
        }

        // Only the call.to field is consulted (Python parity: it reads
        // request_body["call"]["to"], catching KeyError/AttributeError -> None).
        $call = $requestBody['call'] ?? null;
        $toField = is_array($call) ? ($call['to'] ?? null) : null;
        if (!is_string($toField)) {
            return null;
        }

        // SIP URIs "sip:username@domain" -> the part between "sip:" and "@".
        if (str_starts_with($toField, 'sip:')) {
            return explode('@', substr($toField, 4), 2)[0];
        }

        // TEL URIs "tel:+1234567890" -> the phone number after "tel:".
        if (str_starts_with($toField, 'tel:')) {
            return substr($toField, 4);
        }

        // Otherwise return the whole 'to' field verbatim.
        return $toField;
    }

    // ------------------------------------------------------------------
    // Proxy URL
    // ------------------------------------------------------------------

    /**
     * Detect or construct the proxy URL base from request headers.
     *
     * @param array<string, mixed> $headers
     */
    public function getProxyUrlBase(array $headers = []): string
    {
        // 0. Manually-set proxy URL takes priority (manualSetProxyUrl, on the
        //    base Service — shared by AgentBase).
        if (is_string($this->manualProxyUrl) && $this->manualProxyUrl !== '') {
            return $this->manualProxyUrl;
        }

        // 1. Explicit env var
        $envProxy = getenv('SWML_PROXY_URL_BASE');
        if ($envProxy !== false && $envProxy !== '') {
            return rtrim($envProxy, '/');
        }

        // 2. X-Forwarded-Proto + X-Forwarded-Host
        $proto = $headers['X-Forwarded-Proto'] ?? $headers['x-forwarded-proto'] ?? null;
        $fwdHost = $headers['X-Forwarded-Host'] ?? $headers['x-forwarded-host'] ?? null;
        if (is_string($proto) && is_string($fwdHost)) {
            return "{$proto}://{$fwdHost}";
        }

        // 3. X-Original-URL
        $origUrl = $headers['X-Original-URL'] ?? $headers['x-original-url'] ?? null;
        if (is_string($origUrl)) {
            return rtrim($origUrl, '/');
        }

        // 4. Fallback to server config
        return "http://{$this->host}:{$this->port}";
    }

    // ------------------------------------------------------------------
    // Server
    // ------------------------------------------------------------------

    /**
     * Start serving using PHP's built-in server (blocking).
     *
     * In CLI mode: spawns `php -S host:port <entry-script>` where entry-script
     * is the example file the user ran. The entry script is responsible for
     * (re)building the service and calling run() — under the cli-server SAPI,
     * run() dispatches from $_SERVER instead of re-spawning.
     *
     * Under cli-server SAPI: directly dispatches the inbound request to
     * handleRequest() and writes the result to the response.
     */
    public function serve(): void
    {
        if (PHP_SAPI === 'cli-server') {
            // Already running inside a php -S worker — dispatch the
            // inbound request and emit the response directly.
            $this->dispatchFromGlobals();
            return;
        }

        $this->logger->info("Starting server on {$this->host}:{$this->port} ...");
        $this->logger->info("Basic-auth credentials — user: {$this->basicAuthUser}  password: [REDACTED]");

        $addr = "{$this->host}:{$this->port}";

        // The router script is the ORIGINAL CLI script (the example file the
        // user ran). When `php -S` re-invokes it for each request, the
        // builder runs, the service is constructed, run() is called again —
        // but this time under cli-server SAPI, where the early-return branch
        // above takes over and dispatches.
        $entry = $this->resolveEntryScript();
        $cmd = sprintf(
            '%s -S %s %s',
            escapeshellcmd(PHP_BINARY),
            escapeshellarg($addr),
            escapeshellarg($entry),
        );
        passthru($cmd);
    }

    /**
     * Run the service (alias for serve).
     */
    public function run(): void
    {
        $this->serve();
    }

    /**
     * Dispatch the current PHP request (cli-server / php-fpm / mod_php) to
     * handleRequest() and write the response. Must be called inside a SAPI
     * that has populated $_SERVER for the inbound request.
     */
    public function dispatchFromGlobals(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $method = is_string($method) ? $method : 'GET';
        $requestUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $parsedPath = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';
        // Keep the query string: the per-call SWAIG `__token` rides it, and
        // handleRequest() splits it back off before routing.
        $parsedQuery = parse_url($requestUri, PHP_URL_QUERY);
        if (is_string($parsedQuery) && $parsedQuery !== '') {
            $path .= '?' . $parsedQuery;
        }

        // Reconstruct headers from $_SERVER (cli-server doesn't populate
        // getallheaders() reliably; this works in every SAPI).
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'HTTP_') && is_string($v)) {
                $name = str_replace('_', '-', substr($k, 5));
                // Title-case for Authorization, Content-Type compatibility
                $name = ucwords(strtolower($name), '-');
                $headers[$name] = $v;
            }
        }
        $contentType = $_SERVER['CONTENT_TYPE'] ?? null;
        if (is_string($contentType)) {
            $headers['Content-Type'] = $contentType;
        }
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if (is_string($contentLength)) {
            $headers['Content-Length'] = $contentLength;
        }
        // PHP's cli-server eats the Authorization header for CGI safety;
        // recover it from REDIRECT_HTTP_AUTHORIZATION / PHP_AUTH_USER if set.
        if (!isset($headers['Authorization'])) {
            $authVal = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? null;
            if (is_string($authVal) && $authVal !== '') {
                $headers['Authorization'] = $authVal;
            } elseif (is_string($_SERVER['PHP_AUTH_USER'] ?? null)) {
                // Reconstruct Basic auth from PHP_AUTH_USER/PW which are
                // always present when the front-end has parsed the header.
                $user = $_SERVER['PHP_AUTH_USER'];
                $pwRaw = $_SERVER['PHP_AUTH_PW'] ?? '';
                $pw = is_string($pwRaw) ? $pwRaw : '';
                $headers['Authorization'] = 'Basic ' . base64_encode($user . ':' . $pw);
            }
        }

        $body = file_get_contents('php://input');
        if ($body === false || $body === '') {
            $body = null;
        }

        [$status, $respHeaders, $respBody] = $this->handleRequest($method, $path, $headers, $body);

        // Emit response
        if (!headers_sent()) {
            http_response_code($status);
            foreach ($respHeaders as $k => $v) {
                header("{$k}: {$v}", true);
            }
        }
        echo $respBody;
    }

    /**
     * Resolve the path of the original example script that constructed
     * this Service, so `php -S` can re-invoke it as the router.
     *
     * Order of preference:
     *   1. SWML_SERVICE_ENTRY env var (explicit override; harnesses set this)
     *   2. $_SERVER['SCRIPT_FILENAME'] (most-reliable absolute path under CLI)
     *   3. $_SERVER['argv'][0] (resolves to absolute via realpath)
     *   4. The first frame of the call stack outside the SDK (last resort)
     */
    private function resolveEntryScript(): string
    {
        $env = getenv('SWML_SERVICE_ENTRY');
        if (is_string($env) && $env !== '' && file_exists($env)) {
            return realpath($env) ?: $env;
        }
        $script = $_SERVER['SCRIPT_FILENAME'] ?? null;
        if (is_string($script) && $script !== '' && file_exists($script)) {
            return realpath($script) ?: $script;
        }
        $argv = $_SERVER['argv'] ?? null;
        $argv0 = is_array($argv) ? ($argv[0] ?? null) : null;
        if (is_string($argv0) && $argv0 !== '') {
            $abs = realpath($argv0);
            if ($abs !== false && file_exists($abs)) {
                return $abs;
            }
        }
        // Walk back through the stack until we find a file outside of
        // the SignalWire SDK source tree.
        $sdkDir = realpath(__DIR__ . '/../..');
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;
            if (!is_string($file) || $file === '') {
                continue;
            }
            $abs = realpath($file);
            if ($abs === false) {
                continue;
            }
            if ($sdkDir !== false && str_starts_with($abs, $sdkDir)) {
                continue;
            }
            return $abs;
        }
        throw new \RuntimeException(
            'Could not locate the entry script for SWML\\Service::serve(). '
            . 'Set the SWML_SERVICE_ENTRY env var to the example path or '
            . 'invoke the script via `php examples/<your-example>.php`.'
        );
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Validate the X-SignalWire-Signature header on a signed webhook POST.
     *
     * Reconstructs the platform-public URL from request headers (proxy-aware)
     * and runs both Scheme A (RELAY/JSON hex) and Scheme B (Compat/cXML
     * base64) via WebhookValidator. Returns null on success (caller proceeds
     * with normal dispatch) or a 403 response tuple on failure / missing
     * header.
     *
     * Only invoked when a $signingKey is set on the subclass — see the
     * call site in handleRequest().
     *
     * @param array<string,mixed>  $headers Inbound headers; values may be non-string
     *                                      (e.g. multi-value headers), validated below.
     * @param string               $rawBody Raw request body bytes.
     * @param string               $path    Path the SDK sees on the inbound request.
     * @param string               $subPath Path under the service route ('/' / '/swaig' / '/post_prompt').
     *
     * @return array{int, array<string,string>, string}|null
     */
    protected function checkWebhookSignature(
        array $headers,
        string $rawBody,
        string $path,
        string $subPath,
    ): ?array {
        $signature = null;
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-SignalWire-Signature') === 0) {
                $signature = is_string($v) ? $v : null;
                break;
            }
        }
        if ($signature === null) {
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, 'X-Twilio-Signature') === 0) {
                    $signature = is_string($v) ? $v : null;
                    break;
                }
            }
        }
        if ($signature === null || $signature === '') {
            return [
                403,
                array_merge(['Content-Type' => 'text/plain'], $this->securityHeaders()),
                'Forbidden',
            ];
        }

        $url = $this->reconstructPublicUrl($headers, $path);

        $signingKey = $this->signingKey ?? null;
        if (!is_string($signingKey) || $signingKey === '') {
            // Defensive — caller should have guarded this.
            return null;
        }

        $valid = \SignalWire\Security\WebhookValidator::validateWebhookSignature(
            $signingKey,
            $signature,
            $url,
            $rawBody,
        );
        if (!$valid) {
            return [
                403,
                array_merge(['Content-Type' => 'text/plain'], $this->securityHeaders()),
                'Forbidden',
            ];
        }
        return null;
    }

    /**
     * Reconstruct the URL SignalWire POSTed to, honouring proxy headers /
     * env overrides via getProxyUrlBase(), then appending $path and the
     * raw query string from REQUEST_URI / X-Original-URL when available.
     *
     * Handles edge cases:
     *  - Headers may pass either a full URL via X-Original-URL or just
     *    proto + host. getProxyUrlBase already normalises this.
     *  - The query string MUST be preserved (Scheme A signs URL+body and
     *    cXML bodySHA256 lives in the query).
     *
     * @param array<string, mixed> $headers
     */
    protected function reconstructPublicUrl(array $headers, string $path): string
    {
        // Proxy headers are spoofable, so they are honoured for the SIGNED URL
        // only when the agent opted in via `trustProxyForSignature`. Mirrors
        // the reference `_reconstruct_url(request, trust_proxy=...)`
        // (core/security/webhook_middleware.py:111-135): SWML_PROXY_URL_BASE
        // always wins, then X-Forwarded-* only under trust_proxy, else the
        // URL the SDK itself sees.
        $base = $this->getProxyUrlBase($this->trustProxyForSignature ? $headers : []);

        // Find query string (raw, not parsed) from common sources.
        $query = '';
        $reqUri = $_SERVER['REQUEST_URI'] ?? null;
        if (is_string($reqUri) && $reqUri !== '' && str_contains($reqUri, '?')) {
            $query = '?' . substr($reqUri, strpos($reqUri, '?') + 1);
        } else {
            // Allow callers (tests, custom dispatchers) to pass the query
            // explicitly via X-Original-Query-String. This is a SignalWire-
            // private header purely for the validator, never consumed by
            // anything else.
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, 'X-Original-Query-String') === 0 && is_string($v) && $v !== '') {
                    $query = $v[0] === '?' ? $v : '?' . $v;
                    break;
                }
            }
        }

        return rtrim($base, '/') . $path . $query;
    }

    /**
     * Check Basic Auth from request headers.
     *
     * @param array<string, string> $headers
     */
    protected function checkBasicAuth(array $headers): bool
    {
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        if ($authHeader === null) {
            return false;
        }

        $param = self::schemeParam($authHeader, 'Basic');
        if ($param === null) {
            return false;
        }

        $decoded = base64_decode($param, true);
        if ($decoded === false) {
            return false;
        }

        $colonPos = strpos($decoded, ':');
        if ($colonPos === false) {
            return false;
        }

        $inputUser = substr($decoded, 0, $colonPos);
        $inputPass = substr($decoded, $colonPos + 1);

        // Timing-safe comparison
        $userOk = hash_equals($this->basicAuthUser, $inputUser);
        $passOk = hash_equals($this->basicAuthPassword, $inputPass);

        return $userOk && $passOk;
    }

    /**
     * Split an ``Authorization`` header into its scheme and credential:
     * partition on the FIRST space, strip the credential.
     *
     * Returns ``null`` when the header is empty or its scheme token does not
     * case-insensitively equal ``$expectedScheme``. RFC 7235 makes the
     * auth-scheme token case-insensitive, so ``basic <cred>`` is legal.
     */
    private static function schemeParam(string $authHeader, string $expectedScheme): ?string
    {
        $sep = strpos($authHeader, ' ');
        if ($sep === false) {
            return null;
        }
        if (strcasecmp(substr($authHeader, 0, $sep), $expectedScheme) !== 0) {
            return null;
        }
        return trim(substr($authHeader, $sep + 1));
    }

    /**
     * Security headers applied to all authenticated responses.
     *
     * @return array<string, string>
     */
    protected function securityHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cache-Control' => 'no-store',
        ];
    }

    /**
     * Build a JSON response tuple.
     *
     * @return array{int, array<string, string>, string}
     */
    protected function jsonResponse(int $status, mixed $data): array
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('json_encode failed');
        }
        $headers = array_merge(
            ['Content-Type' => 'application/json'],
            $this->securityHeaders(),
        );
        return [$status, $headers, $body];
    }

    /**
     * Generate cryptographically secure random hex string.
     *
     * @param int<1, max> $bytes Number of random bytes (must be positive).
     */
    protected function randomHex(int $bytes): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (\Exception $e) {
            // random_bytes() will throw if no entropy source available
            throw new \RuntimeException(
                'Failed to generate secure random bytes. Cannot start without secure entropy.',
                0,
                $e,
            );
        }
    }

}
