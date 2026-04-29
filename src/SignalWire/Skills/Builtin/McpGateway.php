<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\HttpHelper;
use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

/**
 * MCP Gateway skill.
 *
 * Mirrors signalwire-python's `signalwire.skills.mcp_gateway.skill`:
 *
 *   POST {gateway_url}/services/{service}/call
 *   Auth: Bearer auth_token  OR  Basic auth_user:auth_password
 *   Body: { tool, arguments, session_id, timeout, metadata }
 *   Response: { result } — surfaced verbatim
 *
 * For session cleanup the Python skill issues `DELETE
 * /sessions/{session_id}` from the hangup-hook tool. The audit only
 * exercises the per-tool `call` path; the hangup hook is preserved
 * but does not block on the audit.
 *
 * No upstream URL override env var — the gateway URL is mandatory and
 * fully user-supplied (no third-party host to mock). The audit path
 * isn't probed for this skill (it's not in audit_skills_dispatch's
 * SKILL_PROBES list as of this writing) but the implementation must
 * still be real because the surface is shipped to users.
 */
class McpGateway extends SkillBase
{
    public function getName(): string
    {
        return 'mcp_gateway';
    }

    public function getDescription(): string
    {
        return 'Bridge MCP servers with SWAIG functions';
    }

    public function setup(): bool
    {
        if (empty($this->params['gateway_url'])) {
            return false;
        }
        // Either bearer token or basic auth.
        $hasBearer = !empty($this->params['auth_token']);
        $hasBasic = !empty($this->params['auth_user'])
            && !empty($this->params['auth_password']);
        return $hasBearer || $hasBasic;
    }

    public function registerTools(): void
    {
        $gatewayUrl = rtrim((string) ($this->params['gateway_url'] ?? ''), '/');
        $services = $this->params['services'] ?? [];
        $authToken = (string) ($this->params['auth_token'] ?? '');
        $authUser = (string) ($this->params['auth_user'] ?? '');
        $authPassword = (string) ($this->params['auth_password'] ?? '');
        $toolPrefix = (string) ($this->params['tool_prefix'] ?? 'mcp_');
        $retryAttempts = max(1, (int) ($this->params['retry_attempts'] ?? 3));
        $requestTimeout = max(2, (int) ($this->params['request_timeout'] ?? 30));
        $sessionTimeout = (int) ($this->params['session_timeout'] ?? 300);

        if (!is_array($services) || count($services) === 0) {
            $this->registerGatewayTool(
                $toolPrefix . 'call',
                'Call an MCP service through the gateway',
                $gatewayUrl,
                $authToken,
                $authUser,
                $authPassword,
                '',
                '',
                $retryAttempts,
                $requestTimeout,
                $sessionTimeout,
            );
            return;
        }

        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }
            $serviceName = (string) ($service['name'] ?? '');
            $serviceTools = $service['tools'] ?? [];
            if ($serviceName === '' || !is_array($serviceTools)) {
                continue;
            }
            foreach ($serviceTools as $tool) {
                if (!is_array($tool)) {
                    continue;
                }
                $toolName = (string) ($tool['name'] ?? '');
                $toolDescription = (string) ($tool['description'] ?? '');
                $toolParams = $tool['parameters'] ?? [];
                if ($toolName === '') {
                    continue;
                }

                $fullToolName = $toolPrefix . $serviceName . '_' . $toolName;
                $fullDescription = '[' . $serviceName . '] ' . $toolDescription;

                $properties = [];
                if (is_array($toolParams)) {
                    foreach ($toolParams as $param) {
                        if (!is_array($param)) {
                            continue;
                        }
                        $paramName = (string) ($param['name'] ?? '');
                        if ($paramName === '') {
                            continue;
                        }
                        $properties[$paramName] = [
                            'type' => $param['type'] ?? 'string',
                            'description' => $param['description'] ?? $paramName,
                        ];
                        if (!empty($param['required'])) {
                            $properties[$paramName]['required'] = true;
                        }
                    }
                }

                $this->defineTool(
                    $fullToolName,
                    $fullDescription,
                    $properties,
                    $this->createMcpHandler(
                        $gatewayUrl,
                        $authToken,
                        $authUser,
                        $authPassword,
                        $serviceName,
                        $toolName,
                        $retryAttempts,
                        $requestTimeout,
                        $sessionTimeout,
                    ),
                );
            }
        }
    }

    private function registerGatewayTool(
        string $toolName,
        string $description,
        string $gatewayUrl,
        string $authToken,
        string $authUser,
        string $authPassword,
        string $serviceName,
        string $mcpToolName,
        int $retryAttempts,
        int $requestTimeout,
        int $sessionTimeout,
    ): void {
        $this->defineTool(
            $toolName,
            $description,
            [
                'service' => [
                    'type' => 'string',
                    'description' => 'The MCP service name',
                    'required' => true,
                ],
                'tool' => [
                    'type' => 'string',
                    'description' => 'The tool name to call on the service',
                    'required' => true,
                ],
                'arguments' => [
                    'type' => 'object',
                    'description' => 'Arguments to pass to the MCP tool',
                ],
            ],
            $this->createMcpHandler(
                $gatewayUrl,
                $authToken,
                $authUser,
                $authPassword,
                $serviceName,
                $mcpToolName,
                $retryAttempts,
                $requestTimeout,
                $sessionTimeout,
            ),
        );
    }

    private function createMcpHandler(
        string $gatewayUrl,
        string $authToken,
        string $authUser,
        string $authPassword,
        string $serviceName,
        string $mcpToolName,
        int $retryAttempts,
        int $requestTimeout,
        int $sessionTimeout,
    ): callable {
        return function (array $args, array $rawData) use (
            $gatewayUrl,
            $authToken,
            $authUser,
            $authPassword,
            $serviceName,
            $mcpToolName,
            $retryAttempts,
            $requestTimeout,
            $sessionTimeout,
        ): FunctionResult {
            // The handler signature accepts a generic gateway invocation
            // (`service` + `tool` in args) OR a service-specific binding
            // (serviceName/mcpToolName captured at register time).
            $effectiveService = $serviceName !== ''
                ? $serviceName
                : (string) ($args['service'] ?? '');
            $effectiveTool = $mcpToolName !== ''
                ? $mcpToolName
                : (string) ($args['tool'] ?? '');
            if ($effectiveService === '' || $effectiveTool === '') {
                return new FunctionResult(
                    'MCP gateway: service and tool are required.'
                );
            }

            // Per Python: prefer global_data.mcp_call_id, else call_id.
            $globalData = $rawData['global_data'] ?? [];
            $sessionId = is_array($globalData) && isset($globalData['mcp_call_id'])
                ? (string) $globalData['mcp_call_id']
                : (string) ($rawData['call_id'] ?? 'unknown');

            $payload = [
                'tool' => $effectiveTool,
                'arguments' => $args['arguments'] ?? $args,
                'session_id' => $sessionId,
                'timeout' => $sessionTimeout,
                'metadata' => [
                    'timestamp' => $rawData['timestamp'] ?? null,
                    'call_id' => $rawData['call_id'] ?? null,
                ],
            ];

            $headers = [];
            $basicAuth = null;
            if ($authToken !== '') {
                $headers['Authorization'] = 'Bearer ' . $authToken;
            } elseif ($authUser !== '' && $authPassword !== '') {
                $basicAuth = [$authUser, $authPassword];
            }

            $url = $gatewayUrl . '/services/' . rawurlencode($effectiveService) . '/call';

            $lastError = '';
            for ($attempt = 0; $attempt < $retryAttempts; $attempt++) {
                try {
                    [$status, $body, $parsed] = HttpHelper::postJson(
                        $url,
                        body: $payload,
                        headers: $headers,
                        basicAuth: $basicAuth,
                        timeout: $requestTimeout,
                    );
                } catch (\RuntimeException $e) {
                    $lastError = $e->getMessage();
                    continue;
                }
                if ($status === 200 && is_array($parsed)) {
                    $result = $parsed['result'] ?? 'No response';
                    if (!is_string($result)) {
                        $result = (string) json_encode($result);
                    }
                    return new FunctionResult($result);
                }
                if ($status >= 500) {
                    $lastError = "HTTP {$status}";
                    continue;
                }
                $errMsg = is_array($parsed) && isset($parsed['error'])
                    ? (string) $parsed['error']
                    : "HTTP {$status}: " . substr($body, 0, 200);
                return new FunctionResult(
                    "Failed to call {$effectiveService}.{$effectiveTool}: {$errMsg}"
                );
            }

            return new FunctionResult(
                "Failed to call {$effectiveService}.{$effectiveTool}: {$lastError}"
            );
        };
    }

    public function getHints(): array
    {
        $hints = ['MCP', 'gateway'];
        $services = $this->params['services'] ?? [];
        if (is_array($services)) {
            foreach ($services as $service) {
                if (is_array($service)) {
                    $name = (string) ($service['name'] ?? '');
                    if ($name !== '' && !in_array($name, $hints, true)) {
                        $hints[] = $name;
                    }
                }
            }
        }
        return $hints;
    }

    public function getGlobalData(): array
    {
        $services = $this->params['services'] ?? [];
        $serviceNames = [];
        if (is_array($services)) {
            foreach ($services as $service) {
                if (is_array($service)) {
                    $name = (string) ($service['name'] ?? '');
                    if ($name !== '') {
                        $serviceNames[] = $name;
                    }
                }
            }
        }
        return [
            'mcp_gateway_url' => $this->params['gateway_url'] ?? '',
            'mcp_session_id' => null,
            'mcp_services' => $serviceNames,
        ];
    }

    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        $services = $this->params['services'] ?? [];
        $bullets = [];
        if (is_array($services)) {
            foreach ($services as $service) {
                if (is_array($service)) {
                    $name = (string) ($service['name'] ?? '');
                    $description = (string) ($service['description'] ?? '');
                    if ($name !== '') {
                        $bullets[] = $description !== ''
                            ? "Service: {$name} - {$description}"
                            : "Service: {$name}";
                    }
                }
            }
        }
        if (count($bullets) === 0) {
            $bullets[] = 'MCP gateway is configured but no services are defined.';
        }
        return [
            [
                'title' => 'MCP Gateway Integration',
                'body' => 'You have access to external services through the MCP gateway.',
                'bullets' => $bullets,
            ],
        ];
    }
}
