<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\HttpHelper;
use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

/**
 * MCP Gateway Skill — bridge MCP servers with SWAIG functions.
 *
 * Ports Python `MCPGatewaySkill`
 * (signalwire/skills/mcp_gateway/skill.py). This is a CLIENT: it connects to
 * an already-running MCP Gateway service over HTTP, authenticates (Bearer
 * token OR HTTP Basic), lists the gateway's tools, and dynamically registers
 * each one as a SWAIG function whose handler proxies the call back through the
 * gateway.
 *
 * NOTE — this skill is the CLIENT half only. The MCP Gateway *service* itself
 * (the long-running daemon that spawns and sandboxes MCP server processes and
 * manages sessions) is a separate component that is not part of this SDK; this
 * skill connects TO a running gateway over HTTP, it does not run one.
 */
class McpGateway extends SkillBase
{
    /** Resolved gateway base URL (trailing slash stripped). */
    private string $gatewayUrl = '';

    /** Bearer token when token auth is used; null selects Basic auth. */
    private ?string $authToken = null;

    /** [user, password] for Basic auth; null when token auth is used. */
    /** @var array{0:string,1:string}|null */
    private ?array $basicAuth = null;

    /** @var list<array<string,mixed>> Configured MCP services (empty = all). */
    private array $services = [];

    private int $sessionTimeout = 300;
    private string $toolPrefix = 'mcp_';
    private int $retryAttempts = 3;
    private int $requestTimeout = 30;

    /**
     * Whether to verify the gateway's TLS certificate. Secure by default
     * (true = verification ON); mirrors Python's `verify_ssl` (default True).
     * Threaded to every outbound HTTP call.
     */
    private bool $verifySsl = true;

    /** The name. */
    public function getName(): string
    {
        return 'mcp_gateway';
    }

    /** The description. */
    public function getDescription(): string
    {
        return 'Bridge MCP servers with SWAIG functions';
    }

    /** The version. */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * Parameter schema for the MCP Gateway skill.
     *
     * Mirrors Python `MCPGatewaySkill.get_parameter_schema`
     * (skill.py:35): merges the base schema with the gateway connection,
     * auth, service-selection, timeout, retry and verify_ssl parameters.
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        $schema = parent::getParameterSchema();
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $schema['properties'] = array_merge($properties, [
            'gateway_url' => [
                'type' => 'string',
                'description' => 'URL of the MCP Gateway service',
                'required' => true,
            ],
            'auth_token' => [
                'type' => 'string',
                'description' => 'Bearer token for authentication (alternative to basic auth)',
                'required' => false,
                'hidden' => true,
                'env_var' => 'MCP_GATEWAY_AUTH_TOKEN',
            ],
            'auth_user' => [
                'type' => 'string',
                'description' => 'Username for basic authentication (required if auth_token not provided)',
                'required' => false,
                'env_var' => 'MCP_GATEWAY_AUTH_USER',
            ],
            'auth_password' => [
                'type' => 'string',
                'description' => 'Password for basic authentication (required if auth_token not provided)',
                'required' => false,
                'hidden' => true,
                'env_var' => 'MCP_GATEWAY_AUTH_PASSWORD',
            ],
            'services' => [
                'type' => 'array',
                'description' => 'List of MCP services to connect to (empty for all available)',
                'default' => [],
                'required' => false,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Service name'],
                        'tools' => [
                            'type' => ['string', 'array'],
                            'description' => "Tools to expose ('*' for all, or list of tool names)",
                        ],
                    ],
                ],
            ],
            'session_timeout' => [
                'type' => 'integer',
                'description' => 'Session timeout in seconds',
                'default' => 300,
                'required' => false,
            ],
            'tool_prefix' => [
                'type' => 'string',
                'description' => 'Prefix for registered SWAIG function names',
                'default' => 'mcp_',
                'required' => false,
            ],
            'retry_attempts' => [
                'type' => 'integer',
                'description' => 'Number of retry attempts for failed requests',
                'default' => 3,
                'required' => false,
            ],
            'request_timeout' => [
                'type' => 'integer',
                'description' => 'Request timeout in seconds',
                'default' => 30,
                'required' => false,
            ],
            'verify_ssl' => [
                'type' => 'boolean',
                'description' => 'Verify SSL certificates',
                'default' => true,
                'required' => false,
            ],
        ]);
        return $schema;
    }

    /**
     * Setup and validate skill configuration.
     *
     * Mirrors Python `MCPGatewaySkill.setup` (skill.py:116): pick the auth
     * method (token vs basic), require the gateway_url, read all config
     * (incl. verify_ssl, secure default true), then validate the gateway
     * connection with a `/health` probe.
     */
    public function setup(): bool
    {
        $token = $this->params['auth_token'] ?? null;
        $this->authToken = is_string($token) && $token !== '' ? $token : null;

        if ($this->authToken === null) {
            // Basic auth required when no token.
            $missing = [];
            foreach (['gateway_url', 'auth_user', 'auth_password'] as $p) {
                $v = $this->params[$p] ?? null;
                if (!is_string($v) || $v === '') {
                    $missing[] = $p;
                }
            }
            if ($missing !== []) {
                $this->logger()->error('Missing required parameters: ' . implode(', ', $missing));
                return false;
            }
            $this->basicAuth = [
                $this->paramString('auth_user'),
                $this->paramString('auth_password'),
            ];
        } else {
            // Token auth: just need the gateway URL.
            $gw = $this->params['gateway_url'] ?? null;
            if (!is_string($gw) || $gw === '') {
                $this->logger()->error('Missing required parameter: gateway_url');
                return false;
            }
            $this->basicAuth = null;
        }

        $this->gatewayUrl = rtrim($this->paramString('gateway_url'), '/');
        $this->services = $this->normalizeServices($this->paramArray('services'));
        $this->sessionTimeout = $this->paramInt('session_timeout', 300);
        $this->toolPrefix = $this->paramString('tool_prefix', 'mcp_');
        $this->retryAttempts = $this->paramInt('retry_attempts', 3);
        $this->requestTimeout = $this->paramInt('request_timeout', 30);
        // Secure by default (verify ON). Only an explicit false opts out.
        $this->verifySsl = $this->paramBool('verify_ssl', true);

        // Validate gateway connection.
        try {
            [$status] = $this->httpGet('/health');
            if ($status < 200 || $status >= 300) {
                $this->logger()->error("Failed to connect to gateway: HTTP {$status}");
                return false;
            }
            $this->logger()->info("Connected to MCP Gateway at {$this->gatewayUrl}");
        } catch (\Throwable $e) {
            $this->logger()->error('Failed to connect to gateway: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Register SWAIG tools from the gateway's MCP services.
     *
     * Mirrors Python `MCPGatewaySkill.register_tools` (skill.py:190): if no
     * services were configured, list all available; then, per service, fetch
     * its tools (optionally filtered) and register each as a SWAIG function.
     * Finally register the internal hangup hook for session cleanup.
     */
    public function registerTools(): void
    {
        if ($this->services === []) {
            try {
                [$status, , $parsed] = $this->httpGet('/services');
                if ($status >= 200 && $status < 300 && is_array($parsed)) {
                    foreach ($parsed as $name) {
                        if (is_string($name)) {
                            $this->services[] = ['name' => $name];
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger()->error('Failed to list services: ' . $e->getMessage());
                return;
            }
        }

        foreach ($this->services as $serviceConfig) {
            $serviceName = $serviceConfig['name'] ?? null;
            if (!is_string($serviceName) || $serviceName === '') {
                continue;
            }

            try {
                [$status, , $parsed] = $this->httpGet('/services/' . rawurlencode($serviceName) . '/tools');
                if ($status < 200 || $status >= 300 || !is_array($parsed)) {
                    continue;
                }
                $tools = $parsed['tools'] ?? [];
                if (!is_array($tools)) {
                    $tools = [];
                }

                // Filter tools if a list is specified ('*' = all).
                $toolFilter = $serviceConfig['tools'] ?? '*';
                if (is_array($toolFilter)) {
                    $tools = array_values(array_filter(
                        $tools,
                        static fn ($t) => is_array($t)
                            && isset($t['name'])
                            && in_array($t['name'], $toolFilter, true)
                    ));
                }

                foreach ($tools as $tool) {
                    if (is_array($tool)) {
                        $this->registerMcpTool($serviceName, $tool);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger()->error("Failed to get tools for service '{$serviceName}': " . $e->getMessage());
            }
        }

        // Register the hangup hook for session cleanup.
        $this->defineTool(
            '_mcp_gateway_hangup',
            'Internal cleanup function for MCP sessions',
            [],
            fn (array $args, array $rawData): FunctionResult => $this->hangupHandler($args, $rawData)
        );
    }

    /**
     * Speech-recognition hints.
     *
     * Mirrors Python `MCPGatewaySkill.get_hints` (skill.py:410): the base
     * MCP/gateway hints plus each configured service name.
     *
     * @return list<string>
     */
    public function getHints(): array
    {
        $hints = ['MCP', 'gateway'];
        foreach ($this->services as $service) {
            $name = $service['name'] ?? null;
            if (is_string($name)) {
                $hints[] = $name;
            }
        }
        return $hints;
    }

    /**
     * Global data for DataMap variables.
     *
     * Mirrors Python `MCPGatewaySkill.get_global_data` (skill.py:423).
     *
     * @return array<string,mixed>
     */
    public function getGlobalData(): array
    {
        $serviceNames = [];
        foreach ($this->services as $service) {
            $serviceNames[] = $service['name'] ?? null;
        }
        return [
            'mcp_gateway_url' => $this->gatewayUrl,
            'mcp_session_id' => null,
            'mcp_services' => $serviceNames,
        ];
    }

    /**
     * Prompt sections describing the MCP integration.
     *
     * Mirrors Python `MCPGatewaySkill.get_prompt_sections` (skill.py:433):
     * emits a single section only when at least one service is configured.
     *
     * @return list<array{title: string, body?: string, bullets?: list<string>}>
     */
    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        $descriptions = [];
        foreach ($this->services as $service) {
            $name = is_string($service['name'] ?? null) ? $service['name'] : 'Unknown';
            $tools = $service['tools'] ?? '*';
            if ($tools === '*') {
                $descriptions[] = "{$name} (all tools)";
            } elseif (is_array($tools)) {
                $descriptions[] = $name . ' (' . count($tools) . ' tools)';
            } else {
                $descriptions[] = $name;
            }
        }

        if ($descriptions === []) {
            return [];
        }

        return [
            [
                'title' => 'MCP Gateway Integration',
                'body' => 'You have access to external MCP (Model Context Protocol) services through a gateway.',
                'bullets' => [
                    "Connected to gateway at {$this->gatewayUrl}",
                    'Available services: ' . implode(', ', $descriptions),
                    "Functions are prefixed with '{$this->toolPrefix}' followed by service name",
                    'Each service maintains its own session state throughout the call',
                ],
            ],
        ];
    }

    // ── internal helpers (not part of the oracle surface) ───────────────────

    /**
     * Normalize the configured services into a list of assoc arrays. Accepts
     * plain string entries (name-only) and assoc entries.
     *
     * @param array<mixed> $raw
     * @return list<array<string,mixed>>
     */
    private function normalizeServices(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $out[] = ['name' => $entry];
            } elseif (is_array($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Register a single MCP tool as a SWAIG function.
     *
     * Mirrors Python `MCPGatewaySkill._register_mcp_tool` (skill.py:241):
     * builds SWAIG parameters from the MCP inputSchema and registers a handler
     * that proxies the call through the gateway.
     *
     * @param array<mixed> $toolDef The MCP tool definition decoded from the
     *   gateway's JSON (keys are genuinely mixed until narrowed below).
     */
    private function registerMcpTool(string $serviceName, array $toolDef): void
    {
        $toolName = $toolDef['name'] ?? null;
        if (!is_string($toolName) || $toolName === '') {
            return;
        }

        $swaigName = $this->toolPrefix . $serviceName . '_' . $toolName;

        $inputSchema = is_array($toolDef['inputSchema'] ?? null) ? $toolDef['inputSchema'] : [];
        $properties = is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];
        $required = is_array($inputSchema['required'] ?? null) ? $inputSchema['required'] : [];

        $swaigParams = [];
        foreach ($properties as $propName => $propDef) {
            if (!is_array($propDef)) {
                continue;
            }
            $paramDef = [
                'type' => $propDef['type'] ?? 'string',
                'description' => $propDef['description'] ?? '',
            ];
            if (array_key_exists('enum', $propDef)) {
                $paramDef['enum'] = $propDef['enum'];
            }
            if (array_key_exists('default', $propDef) && !in_array($propName, $required, true)) {
                $paramDef['default'] = $propDef['default'];
            }
            $swaigParams[$propName] = $paramDef;
        }

        $description = '[' . $serviceName . '] '
            . (is_string($toolDef['description'] ?? null) ? $toolDef['description'] : $toolName);

        $this->defineTool(
            $swaigName,
            $description,
            $swaigParams,
            fn (array $args, array $rawData): FunctionResult =>
                $this->callMcpTool($serviceName, $toolName, $args, $rawData)
        );

        $this->logger()->info("Registered SWAIG function: {$swaigName}");
    }

    /**
     * Call an MCP tool through the gateway, with retries.
     *
     * Mirrors Python `MCPGatewaySkill._call_mcp_tool` (skill.py:292).
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $rawData
     */
    private function callMcpTool(
        string $serviceName,
        string $toolName,
        array $args,
        array $rawData
    ): FunctionResult {
        $sessionId = $this->resolveSessionId($rawData);

        $requestData = [
            'tool' => $toolName,
            'arguments' => $args,
            'session_id' => $sessionId,
            'timeout' => $this->sessionTimeout,
            'metadata' => [
                'agent_id' => $this->agent->getName(),
                'timestamp' => $rawData['timestamp'] ?? null,
                'call_id' => $rawData['call_id'] ?? null,
            ],
        ];

        $lastError = null;
        for ($attempt = 0; $attempt < $this->retryAttempts; $attempt++) {
            try {
                [$status, $body, $parsed] = $this->httpPost(
                    '/services/' . rawurlencode($serviceName) . '/call',
                    $requestData
                );

                if ($status === 200) {
                    $resultText = is_array($parsed) && isset($parsed['result'])
                        ? $parsed['result']
                        : 'No response';
                    $result = new FunctionResult();
                    $result->setResponse(is_string($resultText) ? $resultText : (string) json_encode($resultText));
                    return $result;
                }

                if (is_array($parsed) && isset($parsed['error']) && is_string($parsed['error'])) {
                    $lastError = $parsed['error'];
                } else {
                    $lastError = "HTTP {$status}: " . substr($body, 0, 200);
                }

                if ($status >= 500) {
                    $this->logger()->warn('Gateway error (attempt ' . ($attempt + 1) . "): {$lastError}");
                    continue;
                }
                // Client error — don't retry.
                break;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->logger()->warn('Error calling MCP tool (attempt ' . ($attempt + 1) . "): {$lastError}");
            }
        }

        $errorMsg = "Failed to call {$serviceName}.{$toolName}: " . ($lastError ?? 'unknown error');
        $this->logger()->error($errorMsg);
        $result = new FunctionResult();
        $result->setResponse($errorMsg);
        return $result;
    }

    /**
     * Handle call hangup — cleanup the MCP session on the gateway.
     *
     * Mirrors Python `MCPGatewaySkill._hangup_handler` (skill.py:378).
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $rawData
     */
    private function hangupHandler(array $args, array $rawData): FunctionResult
    {
        $sessionId = $this->resolveSessionId($rawData);
        try {
            [$status] = $this->httpRequest('DELETE', '/sessions/' . rawurlencode($sessionId));
            if ($status === 200 || $status === 404) {
                $this->logger()->info("Cleaned up MCP session: {$sessionId}");
            } else {
                $this->logger()->warn("Failed to cleanup session: HTTP {$status}");
            }
        } catch (\Throwable $e) {
            $this->logger()->error('Error cleaning up session: ' . $e->getMessage());
        }

        $result = new FunctionResult();
        $result->setResponse('Session cleanup complete');
        return $result;
    }

    /**
     * Resolve the MCP session ID: prefer global_data.mcp_call_id, else the
     * top-level call_id. Mirrors Python's resolution in _call_mcp_tool /
     * _hangup_handler.
     *
     * @param array<string,mixed> $rawData
     */
    private function resolveSessionId(array $rawData): string
    {
        $globalData = $rawData['global_data'] ?? [];
        if (is_array($globalData) && isset($globalData['mcp_call_id']) && is_string($globalData['mcp_call_id'])) {
            return $globalData['mcp_call_id'];
        }
        $callId = $rawData['call_id'] ?? 'unknown';
        return is_string($callId) ? $callId : 'unknown';
    }

    /**
     * GET the gateway path with auth + verify_ssl applied.
     *
     * @return array{int, string, mixed}
     */
    private function httpGet(string $path): array
    {
        return $this->httpRequest('GET', $path);
    }

    /**
     * POST a JSON body to the gateway path with auth + verify_ssl applied.
     *
     * @param array<string,mixed> $body
     * @return array{int, string, mixed}
     */
    private function httpPost(string $path, array $body): array
    {
        return $this->httpRequest('POST', $path, $body);
    }

    /**
     * Issue an authenticated request to the gateway. Threads the skill's
     * verify_ssl setting to the underlying cURL call so certificate
     * verification is really controlled by the config param (not a no-op).
     *
     * @param array<string,mixed>|null $body
     * @return array{int, string, mixed}
     */
    private function httpRequest(string $method, string $path, ?array $body = null): array
    {
        $url = $this->gatewayUrl . $path;
        $headers = [];
        if ($this->authToken !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->authToken;
        }

        if ($method === 'GET') {
            return HttpHelper::get(
                $url,
                $headers,
                null,
                $this->authToken === null ? $this->basicAuth : null,
                $this->requestTimeout,
                $this->verifySsl,
            );
        }

        if ($method === 'POST') {
            return HttpHelper::postJson(
                $url,
                $body,
                $headers,
                $this->authToken === null ? $this->basicAuth : null,
                $this->requestTimeout,
                $this->verifySsl,
            );
        }

        // DELETE and any other verbs go through the raw request engine.
        return HttpHelper::request(
            $method,
            $url,
            $headers,
            $body === null ? null : (string) json_encode($body),
            $this->authToken === null ? $this->basicAuth : null,
            $this->requestTimeout,
            $this->verifySsl,
        );
    }

    private function logger(): \SignalWire\Logging\Logger
    {
        return \SignalWire\Logging\LoggingConfig::getLogger('signalwire.skills.mcp_gateway');
    }
}
