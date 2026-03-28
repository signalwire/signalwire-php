<?php

declare(strict_types=1);

namespace SignalWire\Skills\Builtin;

use SignalWire\Skills\SkillBase;
use SignalWire\SWAIG\FunctionResult;

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

        return true;
    }

    public function registerTools(): void
    {
        $gatewayUrl = $this->params['gateway_url'] ?? '';
        $services = $this->params['services'] ?? [];
        $authToken = $this->params['auth_token'] ?? '';
        $toolPrefix = $this->params['tool_prefix'] ?? 'mcp_';
        $retryAttempts = $this->params['retry_attempts'] ?? 3;
        $requestTimeout = $this->params['request_timeout'] ?? 30;

        if (empty($services) || !is_array($services)) {
            // If no services defined, register a generic gateway tool
            $this->registerGatewayTool(
                $toolPrefix . 'call',
                'Call an MCP service through the gateway',
                $gatewayUrl,
                $authToken,
                '',
                ''
            );
            return;
        }

        // Register one tool per service
        foreach ($services as $service) {
            $serviceName = $service['name'] ?? '';
            $serviceTools = $service['tools'] ?? [];

            if ($serviceName === '' || empty($serviceTools)) {
                continue;
            }

            foreach ($serviceTools as $tool) {
                $toolName = $tool['name'] ?? '';
                $toolDescription = $tool['description'] ?? '';
                $toolParams = $tool['parameters'] ?? [];

                if ($toolName === '') {
                    continue;
                }

                $fullToolName = $toolPrefix . $serviceName . '_' . $toolName;
                $fullDescription = '[' . $serviceName . '] ' . $toolDescription;

                $properties = [];
                if (is_array($toolParams)) {
                    foreach ($toolParams as $param) {
                        $paramName = $param['name'] ?? '';
                        if ($paramName === '') {
                            continue;
                        }
                        $properties[$paramName] = [
                            'type' => $param['type'] ?? 'string',
                            'description' => $param['description'] ?? $paramName,
                        ];
                        if (isset($param['required']) && $param['required']) {
                            $properties[$paramName]['required'] = true;
                        }
                    }
                }

                $this->defineTool(
                    $fullToolName,
                    $fullDescription,
                    $properties,
                    $this->createMcpHandler($gatewayUrl, $authToken, $serviceName, $toolName)
                );
            }
        }
    }

    private function registerGatewayTool(
        string $toolName,
        string $description,
        string $gatewayUrl,
        string $authToken,
        string $serviceName,
        string $mcpToolName
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
            $this->createMcpHandler($gatewayUrl, $authToken, $serviceName, $mcpToolName)
        );
    }

    private function createMcpHandler(
        string $gatewayUrl,
        string $authToken,
        string $serviceName,
        string $mcpToolName
    ): callable {
        return function (array $args, array $rawData) use ($gatewayUrl, $authToken, $serviceName, $mcpToolName): FunctionResult {
            $result = new FunctionResult();

            $service = $serviceName !== '' ? $serviceName : ($args['service'] ?? 'unknown');
            $tool = $mcpToolName !== '' ? $mcpToolName : ($args['tool'] ?? 'unknown');

            // Stub: in production, this would POST to the MCP gateway
            // POST {gatewayUrl}/call
            // Headers: Authorization: Bearer {authToken}
            // Body: { service, tool, arguments: $args }
            $result->setResponse(
                'MCP gateway call to service "' . $service . '", tool "' . $tool . '" '
                . 'via gateway at "' . $gatewayUrl . '". '
                . 'Arguments: ' . json_encode($args) . '. '
                . 'In production, this would forward the request to the MCP gateway service.'
            );

            return $result;
        };
    }

    public function getHints(): array
    {
        $hints = ['MCP', 'gateway'];
        $services = $this->params['services'] ?? [];

        foreach ($services as $service) {
            $name = $service['name'] ?? '';
            if ($name !== '' && !in_array($name, $hints, true)) {
                $hints[] = $name;
            }
        }

        return $hints;
    }

    public function getGlobalData(): array
    {
        $services = $this->params['services'] ?? [];
        $serviceNames = [];

        foreach ($services as $service) {
            $name = $service['name'] ?? '';
            if ($name !== '') {
                $serviceNames[] = $name;
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

        foreach ($services as $service) {
            $name = $service['name'] ?? '';
            $description = $service['description'] ?? '';

            if ($name !== '') {
                $bullet = 'Service: ' . $name;
                if ($description !== '') {
                    $bullet .= ' - ' . $description;
                }
                $bullets[] = $bullet;
            }
        }

        if (empty($bullets)) {
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
