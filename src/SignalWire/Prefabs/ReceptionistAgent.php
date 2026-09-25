<?php

declare(strict_types=1);

namespace SignalWire\Prefabs;

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

/**
 * Ready-made receptionist agent: greets the caller, collects who they are,
 * and routes them to a department.
 *
 * Each `$departments` entry carries a `name` and `description` the model
 * matches against, plus its routing target. `transfer_type` (default
 * `phone`) selects the mechanism: `swml` uses the entry's `swml_url`,
 * anything else falls through to connecting the entry's `number`. Registers
 * the `collect_caller_info` and `transfer_call` SWAIG tools. Default route
 * `/receptionist`; an empty `$name` falls back to `receptionist`.
 */
class ReceptionistAgent extends AgentBase
{
    /** @var list<array{name: string, description: string, number?: string, transfer_type?: string, swml_url?: string}> */
    protected array $departments;

    protected string $greeting;

    /**
     * @param string $name Agent name
     * @param list<array{name: string, description: string, number?: string, transfer_type?: string, swml_url?: string}> $departments
     * @param string $route
     * @param string|null $host
     * @param int|null $port
     * @param string|null $basicAuthUser
     * @param string|null $basicAuthPassword
     * @param bool $autoAnswer
     * @param bool $recordCall
     * @param bool $usePom
     * @param string|null $greeting
     */
    public function __construct(
        string $name,
        array $departments,
        string $route = '/receptionist',
        ?string $host = null,
        ?int $port = null,
        ?string $basicAuthUser = null,
        ?string $basicAuthPassword = null,
        bool $autoAnswer = true,
        bool $recordCall = false,
        bool $usePom = true,
        ?string $greeting = null,
    ) {
        $this->greeting = $greeting ?? 'Thank you for calling. How can I help you today?';

        $name = $name !== '' ? $name : 'receptionist';

        parent::__construct(
            name: $name,
            route: $route,
            host: $host,
            port: $port,
            basicAuthUser: $basicAuthUser,
            basicAuthPassword: $basicAuthPassword,
            autoAnswer: $autoAnswer,
            recordCall: $recordCall,
            usePom: $usePom,
        );

        $this->departments = $departments;
        $this->usePom      = true;

        // Global data
        $this->setGlobalData([
            'departments' => $this->departments,
            'caller_info' => new \stdClass(),
        ]);

        // Build department list for prompt
        $deptBullets = [];
        foreach ($this->departments as $dept) {
            $deptBullets[] = "{$dept['name']}: {$dept['description']}";
        }

        $this->promptAddSection(
            'Receptionist Role',
            $this->greeting,
            array_merge([
                'Greet the caller warmly',
                'Determine which department they need',
                'Transfer them to the correct department',
            ], $deptBullets),
        );

        // Tool: collect_caller_info
        $this->defineTool(
            name: 'collect_caller_info',
            description: 'Collect and store caller identification information',
            parameters: [
                'caller_name'  => ['type' => 'string', 'description' => 'Name of the caller'],
                'caller_phone' => ['type' => 'string', 'description' => 'Phone number of the caller'],
                'reason'       => ['type' => 'string', 'description' => 'Reason for calling'],
            ],
            handler: function (array $args, array $rawData): FunctionResult {
                $callerName = $args['caller_name'] ?? 'Unknown';
                $reason     = $args['reason'] ?? 'Not specified';
                return new FunctionResult("Caller info recorded: {$callerName}, reason: {$reason}");
            },
        );

        // Tool: transfer_call
        $capturedDepts = $this->departments;
        $this->defineTool(
            name: 'transfer_call',
            description: 'Transfer the caller to the specified department',
            parameters: [
                'department' => ['type' => 'string', 'description' => 'Department name to transfer to'],
            ],
            handler: function (array $args, array $rawData) use ($capturedDepts): FunctionResult {
                $deptName = $args['department'] ?? '';

                foreach ($capturedDepts as $dept) {
                    if (strtolower($dept['name']) === strtolower($deptName)) {
                        $transferType = $dept['transfer_type'] ?? 'phone';
                        $result = new FunctionResult("Transferring to {$deptName}");

                        if ($transferType === 'swml' && isset($dept['swml_url'])) {
                            $result->swmlTransfer($dept['swml_url'], "Transferring you to {$deptName} now.");
                        } elseif (isset($dept['number'])) {
                            $result->connect($dept['number']);
                        }

                        return $result;
                    }
                }

                return new FunctionResult("Department '{$deptName}' not found");
            },
        );
    }

    /**
     * Process the conversation summary.
     *
     * Mirrors Python `ReceptionistAgent.on_summary`: a no-op hook that
     * subclasses override to handle the end-of-call summary.
     *
     * @param array<string,mixed>|string|null $summary
     * @param array<string,mixed>|null        $rawData
     */
    public function onSummary(array|string|null $summary, ?array $rawData = null): void
    {
        // Subclasses can override this to handle the summary.
    }

    /**
     * @return list<array>
     */
    public function getDepartments(): array
    {
        return $this->departments;
    }

    /** The greeting. */
    public function getGreeting(): string
    {
        return $this->greeting;
    }
}
