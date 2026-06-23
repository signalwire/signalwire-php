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

    /**
     * Narrow a genuinely-mixed value (nested user config / SWAIG args /
     * gateway JSON) to a string. Non-string scalars are stringified to
     * match the loose typing the platform sends; anything else → $default.
     */
    private static function asString(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return $default;
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
        $gatewayUrl = rtrim($this->paramString('gateway_url'), '/');
        $services = $this->paramArray('services');
        $authToken = $this->paramString('auth_token');
        $authUser = $this->paramString('auth_user');
        $authPassword = $this->paramString('auth_password');
        $toolPrefix = $this->paramString('tool_prefix', 'mcp_');
        $retryAttempts = max(1, $this->paramInt('retry_attempts', 3));
        $requestTimeout = max(2, $this->paramInt('request_timeout', 30));
        $sessionTimeout = $this->paramInt('session_timeout', 300);

        if (count($services) === 0) {
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
            $serviceName = self::asString($service['name'] ?? null);
            $serviceTools = $service['tools'] ?? [];
            if ($serviceName === '' || !is_array($serviceTools)) {
                continue;
            }
            foreach ($serviceTools as $tool) {
                if (!is_array($tool)) {
                    continue;
                }
                $toolName = self::asString($tool['name'] ?? null);
                $toolDescription = self::asString($tool['description'] ?? null);
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
                        $paramName = self::asString($param['name'] ?? null);
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
                : self::asString($args['service'] ?? null);
            $effectiveTool = $mcpToolName !== ''
                ? $mcpToolName
                : self::asString($args['tool'] ?? null);
            if ($effectiveService === '' || $effectiveTool === '') {
                return new FunctionResult(
                    'MCP gateway: service and tool are required.'
                );
            }

            // Per Python: prefer global_data.mcp_call_id, else call_id.
            $globalData = $rawData['global_data'] ?? [];
            $sessionId = is_array($globalData) && isset($globalData['mcp_call_id'])
                ? self::asString($globalData['mcp_call_id'])
                : self::asString($rawData['call_id'] ?? null, 'unknown');

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
                    ? self::asString($parsed['error'])
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

    /**
     * @return list<string>
     */
    public function getHints(): array
    {
        $hints = ['MCP', 'gateway'];
        foreach ($this->paramArray('services') as $service) {
            if (is_array($service)) {
                $name = self::asString($service['name'] ?? null);
                if ($name !== '' && !in_array($name, $hints, true)) {
                    $hints[] = $name;
                }
            }
        }
        return $hints;
    }

    /**
     * @return array<string,mixed>
     */
    public function getGlobalData(): array
    {
        $serviceNames = [];
        foreach ($this->paramArray('services') as $service) {
            if (is_array($service)) {
                $name = self::asString($service['name'] ?? null);
                if ($name !== '') {
                    $serviceNames[] = $name;
                }
            }
        }
        return [
            'mcp_gateway_url' => $this->paramString('gateway_url'),
            'mcp_session_id' => null,
            'mcp_services' => $serviceNames,
        ];
    }

    /**
     * @return list<array{title: string, body?: string, bullets?: list<string>}>
     */
    public function getPromptSections(): array
    {
        if (!empty($this->params['skip_prompt'])) {
            return [];
        }

        $bullets = [];
        foreach ($this->paramArray('services') as $service) {
            if (is_array($service)) {
                $name = self::asString($service['name'] ?? null);
                $description = self::asString($service['description'] ?? null);
                if ($name !== '') {
                    $bullets[] = $description !== ''
                        ? "Service: {$name} - {$description}"
                        : "Service: {$name}";
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
