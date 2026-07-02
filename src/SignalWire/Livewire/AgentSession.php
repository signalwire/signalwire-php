<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

/**
 * Mirrors a LiveKit `AgentSession` — the orchestrator that binds an {@see Agent}
 * to the SignalWire platform.
 *
 * {@see start()} builds a real SignalWire {@see AgentBase} from the bound agent,
 * mapping LiveKit-style options (llm model, interruption, endpointing delays)
 * onto SignalWire AI params and registering the agent's LiveWire tools as SWAIG
 * functions. Pipeline-related options are accepted for source-compatibility but
 * are no-ops server-side. The underlying `AgentBase` is reachable via
 * {@see getSwAgent()} for tests and advanced use.
 */
class AgentSession
{
    use NoopLog;

    private mixed $llm;
    /** @var list<array<string, mixed>> */
    private array $tools;
    private mixed $userdata;
    private bool $allowInterruptions;
    /** Accepted for LiveKit parity (unused server-side). */
    public float $minInterruptionDuration;
    private float $minEndpointingDelay;
    private float $maxEndpointingDelay;
    /** Accepted for LiveKit parity; advisory logged if != 3 (unused server-side). */
    public int $maxToolSteps;
    /** Accepted for LiveKit parity (unused server-side). */
    public bool $preemptiveGeneration;

    /** The currently-bound agent, or null before {@see start()}. */
    public ?Agent $agent = null;
    private ?AgentBase $swAgent = null;
    /** @var list<string> */
    private array $sayQueue = [];
    /** @var list<array<string, string>> */
    private array $history = [];
    /** True once {@see start()} has bound an agent. */
    public bool $started = false;

    /**
     * @param mixed                     $stt                    Pipeline option (no-op advisory).
     * @param mixed                     $tts                    Pipeline option (no-op advisory).
     * @param mixed                     $llm                    LLM hint mapped in start().
     * @param mixed                     $vad                    Pipeline option (no-op advisory).
     * @param mixed                     $turnDetection          Pipeline option (no-op advisory).
     * @param list<array<string,mixed>> $tools                  Session-level LiveWire tool descriptors.
     * @param mixed                     $mcpServers             Pipeline option (no-op advisory).
     * @param mixed                     $userdata               Per-session user data.
     * @param bool                      $allowInterruptions     Barge default.
     * @param float                     $minInterruptionDuration Ignored (parity).
     * @param float                     $minEndpointingDelay    Endpointing default (seconds).
     * @param float                     $maxEndpointingDelay    Endpointing default (seconds).
     * @param int                       $maxToolSteps           Tool-step depth (parity; advisory if != 3).
     * @param bool                      $preemptiveGeneration   Ignored (parity).
     */
    public function __construct(
        mixed $stt = null,
        mixed $tts = null,
        mixed $llm = null,
        mixed $vad = null,
        mixed $turnDetection = null,
        array $tools = [],
        mixed $mcpServers = null,
        mixed $userdata = null,
        bool $allowInterruptions = true,
        float $minInterruptionDuration = 0.5,
        float $minEndpointingDelay = 0.5,
        float $maxEndpointingDelay = 3.0,
        int $maxToolSteps = 3,
        bool $preemptiveGeneration = false,
    ) {
        if ($stt !== null) {
            self::noopOnce(
                'stt',
                "AgentSession(stt=...): SignalWire's control plane handles "
                . 'speech recognition at scale -- no configuration needed'
            );
        }
        if ($tts !== null) {
            self::noopOnce(
                'tts',
                "AgentSession(tts=...): SignalWire's control plane handles "
                . 'text-to-speech at scale -- no configuration needed'
            );
        }
        if ($vad !== null) {
            self::noopOnce(
                'vad',
                "AgentSession(vad=...): SignalWire's control plane handles "
                . 'voice activity detection at scale automatically'
            );
        }
        if ($turnDetection !== null) {
            self::noopOnce(
                'turn_detection',
                "AgentSession(turn_detection=...): SignalWire's control "
                . 'plane handles turn detection at scale automatically'
            );
        }
        if ($mcpServers !== null) {
            self::noopOnce(
                'mcp_servers',
                'AgentSession(mcp_servers=...): MCP servers are not yet '
                . 'supported in LiveWire -- tools should be registered via '
                . 'function_tool'
            );
        }

        $this->llm                     = $llm;
        $this->tools                   = $tools;
        $this->userdata                = $userdata ?? [];
        $this->allowInterruptions      = $allowInterruptions;
        $this->minInterruptionDuration = $minInterruptionDuration;
        $this->minEndpointingDelay     = $minEndpointingDelay;
        $this->maxEndpointingDelay     = $maxEndpointingDelay;
        $this->maxToolSteps            = $maxToolSteps;
        $this->preemptiveGeneration    = $preemptiveGeneration;

        if ($maxToolSteps !== 3) {
            self::noopOnce(
                'max_tool_steps',
                "AgentSession(max_tool_steps={$maxToolSteps}): SignalWire's "
                . 'control plane handles tool execution depth at scale '
                . 'automatically'
            );
        }
    }

    // ------------------------------------------------------------------
    // Properties
    // ------------------------------------------------------------------

    /** Current per-session user data. */
    public function getUserdata(): mixed
    {
        return $this->userdata;
    }

    public function setUserdata(mixed $val): void
    {
        $this->userdata = $val;
    }

    /**
     * Conversation history entries captured over the session's lifetime.
     *
     * @return list<array<string, string>>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    /**
     * Bind to an {@see Agent} and build the underlying SignalWire AgentBase.
     *
     * Mirrors Python's `start()` + `_build_sw_agent()` and TS's `start()`:
     * maps the agent's llm/interruption/endpointing hints onto SWML AI params,
     * flushes the `say` queue as an initial-greeting prompt section, and
     * registers all LiveWire tools (session-level + agent-level) as SWAIG tools.
     *
     * @param mixed $room   LiveKit room placeholder; ignored on SignalWire.
     * @param bool  $record Call-recording flag placeholder; ignored on SignalWire.
     */
    public function start(Agent $agent, mixed $room = null, bool $record = false): void
    {
        $this->agent = $agent;
        $agent->setSession($this);
        $this->started = true;

        $sw = new AgentBase(name: 'LiveWireAgent', route: '/');
        $sw->setPromptText($agent->instructions);

        // Map LLM model (session-level takes priority, then agent-level hint).
        $llmModel = $this->llm;
        if ($llmModel === null || $llmModel === Agent::NOT_GIVEN) {
            $llmModel = $agent->llmHint;
        }
        if ($llmModel !== null && $llmModel !== Agent::NOT_GIVEN) {
            // The llm hint may be a plain model string ("openai/gpt-4o") or a
            // plugin object carrying a ->model (e.g. Plugins\OpenAILLM).
            if (is_scalar($llmModel)) {
                $model = (string) $llmModel;
            } elseif (is_object($llmModel) && isset($llmModel->model) && is_string($llmModel->model)) {
                $model = $llmModel->model;
            } else {
                $model = '';
            }
            $slash = strpos($model, '/');
            if ($slash !== false) {
                $model = substr($model, $slash + 1);
            }
            if ($model !== '') {
                $sw->setParam('model', $model);
            }
        }

        // Map interruption / barge (agent-level override wins).
        $allow = $this->allowInterruptions;
        if ($agent->allowInterruptions !== Agent::NOT_GIVEN) {
            $allow = (bool) $agent->allowInterruptions;
        }
        if (!$allow) {
            $sw->setParam('barge_confidence', 1.0);
        }

        // Map endpointing delays (agent-level override wins), seconds -> ms.
        $minEp = $this->minEndpointingDelay;
        if (is_numeric($agent->minEndpointingDelay)) {
            $minEp = (float) $agent->minEndpointingDelay;
        }
        if ($minEp > 0) {
            $sw->setParam('end_of_speech_timeout', (int) round($minEp * 1000));
        }

        $maxEp = $this->maxEndpointingDelay;
        if (is_numeric($agent->maxEndpointingDelay)) {
            $maxEp = (float) $agent->maxEndpointingDelay;
        }
        if ($maxEp > 0) {
            $sw->setParam('attention_timeout', (int) round($maxEp * 1000));
        }

        // Initial greeting (say queue).
        foreach ($this->sayQueue as $text) {
            $sw->promptAddSection('Initial Greeting', $text);
        }

        // Register all tools (session-level + agent-level).
        $allTools = array_merge($this->tools, $agent->tools());
        foreach ($allTools as $tool) {
            $this->registerTool($sw, $tool);
        }

        $this->swAgent = $sw;
    }

    /**
     * Register a single LiveWire tool descriptor on the SignalWire AgentBase.
     *
     * @param array<string, mixed> $tool
     */
    private function registerTool(AgentBase $sw, array $tool): void
    {
        $rawName = $tool['name'] ?? '';
        $name = is_scalar($rawName) ? (string) $rawName : '';
        if ($name === '' || !isset($tool['handler']) || !is_callable($tool['handler'])) {
            return;
        }
        $rawDesc = $tool['description'] ?? '';
        $description = is_scalar($rawDesc) ? (string) $rawDesc : '';
        $parameters  = is_array($tool['parameters'] ?? null) ? $tool['parameters'] : [];
        $userHandler = $tool['handler'];
        $session     = $this;

        $handler = static function (array $args, ?array $rawData = null) use ($userHandler, $session): FunctionResult {
            $ctx    = new RunContext($session);
            $result = $userHandler($args, $ctx);
            if ($result instanceof FunctionResult) {
                return $result;
            }
            if (is_string($result)) {
                return new FunctionResult($result);
            }
            return new FunctionResult(is_scalar($result) ? (string) $result : (string) json_encode($result));
        };

        $properties = $parameters['properties'] ?? $parameters;
        if (!is_array($properties)) {
            $properties = [];
        }
        $sw->defineTool(
            name: $name,
            description: $description,
            parameters: $properties,
            handler: $handler,
        );
    }

    /**
     * Queue text to be spoken by the agent.
     *
     * Before {@see start()} the text is buffered and injected as the initial
     * greeting; after start it is added as an additional prompt section.
     */
    public function say(string $text): void
    {
        if ($this->swAgent !== null) {
            $this->swAgent->promptAddSection('Say', $text);
        } else {
            $this->sayQueue[] = $text;
        }
    }

    /**
     * Trigger the agent to generate a reply, optionally with extra instructions.
     *
     * When started, extra instructions are injected as a new prompt section;
     * otherwise they are buffered onto the say queue (mirrors Python).
     */
    public function generateReply(?string $instructions = null): void
    {
        if ($instructions === null || $instructions === '') {
            return;
        }
        if ($this->swAgent !== null) {
            $this->swAgent->promptAddSection('Initial Greeting', $instructions);
        } else {
            $this->sayQueue[] = $instructions;
        }
    }

    /** Interrupt current speech. No-op — SignalWire handles barge-in automatically. */
    public function interrupt(): void
    {
        self::noopOnce(
            'interrupt',
            'AgentSession.interrupt(): SignalWire handles barge-in '
            . 'automatically via its control plane'
        );
    }

    /**
     * Swap the {@see Agent} bound to this session.
     *
     * Preserves the underlying `AgentBase` but replaces its prompt with the new
     * agent's instructions.
     */
    public function updateAgent(Agent $agent): void
    {
        $this->agent = $agent;
        $agent->setSession($this);
        if ($this->swAgent !== null) {
            $this->swAgent->setPromptText($agent->instructions);
        }
    }

    /**
     * The underlying SignalWire {@see AgentBase}, or null before {@see start()}.
     *
     * LiveWire accessor for tests and advanced use that reach past the facade.
     */
    public function getSwAgent(): ?AgentBase
    {
        return $this->swAgent;
    }
}
