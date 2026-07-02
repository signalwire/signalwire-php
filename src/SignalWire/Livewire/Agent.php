<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

/**
 * Mirrors a LiveKit `voice.Agent` — holds instructions and tool definitions.
 *
 * Pipeline options (`stt`, `tts`, `vad`, `llm`, `turnDetection`, `mcpServers`)
 * are accepted for LiveKit source-compatibility but are **no-ops** — SignalWire's
 * control plane handles the entire AI pipeline server-side. Each supplied option
 * logs a one-time advisory and is otherwise stored as a hint consumed later by
 * {@see AgentSession::start()} (llm / interruption / endpointing).
 *
 * Tools are LiveWire tool descriptors — the associative arrays returned by
 * {@see LiveWire::functionTool()} (`['name', 'description', 'parameters',
 * 'handler']`). This is the PHP idiom for livekit's `@function_tool`-decorated
 * callables / TS `FunctionTool` objects.
 */
class Agent
{
    use NoopLog;

    /** Sentinel distinguishing "argument omitted" from an explicit null. */
    public const NOT_GIVEN = "\0__livewire_not_given__\0";

    /** System instructions passed through to the SignalWire AI prompt. */
    public string $instructions;

    /**
     * Registered tool descriptors (LiveWire tool arrays), in registration order.
     *
     * @var list<array<string, mixed>>
     */
    private array $tools;

    private ?AgentSession $session = null;

    /** LiveKit chat context, captured for source-compatibility (unused server-side). */
    public mixed $chatCtx;

    /** @internal Pipeline hints stored for AgentSession::start() mapping. */
    public mixed $llmHint;
    /** @internal */
    public mixed $allowInterruptions;
    /** @internal */
    public mixed $minEndpointingDelay;
    /** @internal */
    public mixed $maxEndpointingDelay;

    /**
     * @param string                    $instructions       System instructions.
     * @param list<array<string,mixed>> $tools              LiveWire tool descriptors.
     * @param mixed                     $chatCtx            LiveKit chat context (ignored).
     * @param mixed                     $stt                Pipeline option (no-op advisory).
     * @param mixed                     $tts                Pipeline option (no-op advisory).
     * @param mixed                     $llm                LLM hint (mapped in start()).
     * @param mixed                     $vad                Pipeline option (no-op advisory).
     * @param mixed                     $turnDetection      Pipeline option (no-op advisory).
     * @param mixed                     $mcpServers         Pipeline option (no-op advisory).
     * @param mixed                     $allowInterruptions Barge hint (mapped in start()).
     * @param mixed                     $minEndpointingDelay Endpointing hint (mapped in start()).
     * @param mixed                     $maxEndpointingDelay Endpointing hint (mapped in start()).
     */
    public function __construct(
        string $instructions = '',
        array $tools = [],
        mixed $chatCtx = self::NOT_GIVEN,
        mixed $stt = self::NOT_GIVEN,
        mixed $tts = self::NOT_GIVEN,
        mixed $llm = self::NOT_GIVEN,
        mixed $vad = self::NOT_GIVEN,
        mixed $turnDetection = self::NOT_GIVEN,
        mixed $mcpServers = self::NOT_GIVEN,
        mixed $allowInterruptions = self::NOT_GIVEN,
        mixed $minEndpointingDelay = self::NOT_GIVEN,
        mixed $maxEndpointingDelay = self::NOT_GIVEN,
    ) {
        $this->instructions = $instructions;
        $this->tools        = $tools;
        $this->chatCtx      = $chatCtx;

        if ($stt !== self::NOT_GIVEN) {
            self::noopOnce(
                'agent_stt',
                "Agent(stt=...): SignalWire's control plane handles speech "
                . 'recognition at scale -- no configuration needed'
            );
        }
        if ($tts !== self::NOT_GIVEN) {
            self::noopOnce(
                'agent_tts',
                "Agent(tts=...): SignalWire's control plane handles "
                . 'text-to-speech at scale -- no configuration needed'
            );
        }
        if ($vad !== self::NOT_GIVEN) {
            self::noopOnce(
                'agent_vad',
                "Agent(vad=...): SignalWire's control plane handles voice "
                . 'activity detection at scale automatically'
            );
        }
        if ($turnDetection !== self::NOT_GIVEN) {
            self::noopOnce(
                'agent_turn_detection',
                "Agent(turn_detection=...): SignalWire's control plane "
                . 'handles turn detection at scale automatically'
            );
        }
        if ($mcpServers !== self::NOT_GIVEN) {
            self::noopOnce(
                'agent_mcp_servers',
                'Agent(mcp_servers=...): MCP servers are not yet supported '
                . 'in LiveWire -- tools should be registered via function_tool'
            );
        }

        // Store pipeline hints for later mapping in AgentSession::start().
        $this->llmHint             = $llm;
        $this->allowInterruptions  = $allowInterruptions;
        $this->minEndpointingDelay = $minEndpointingDelay;
        $this->maxEndpointingDelay = $maxEndpointingDelay;
    }

    // ------------------------------------------------------------------
    // session property (getter + setter)
    // ------------------------------------------------------------------

    /** The currently-bound {@see AgentSession}, or null until start() is called. */
    public function getSession(): ?AgentSession
    {
        return $this->session;
    }

    public function setSession(?AgentSession $session): void
    {
        $this->session = $session;
    }

    /**
     * Registered tool descriptors, in order. (LiveWire accessor.)
     *
     * @return list<array<string, mixed>>
     */
    public function tools(): array
    {
        return $this->tools;
    }

    // ------------------------------------------------------------------
    // Lifecycle hooks (override in subclass) — default no-ops.
    // ------------------------------------------------------------------

    /** Called when the agent enters an active call. Override in a subclass. */
    public function onEnter(): void
    {
    }

    /** Called when the agent exits (call ended or handoff). Override in a subclass. */
    public function onExit(): void
    {
    }

    /**
     * Called when the user finishes speaking. Override to inspect / mutate the
     * turn context before the LLM responds. Default is a no-op.
     */
    public function onUserTurnCompleted(mixed $turnCtx = null, mixed $newMessage = null): void
    {
    }

    // ------------------------------------------------------------------
    // Pipeline nodes -- all no-op + log (SignalWire handles these).
    // ------------------------------------------------------------------

    /** LiveKit-compatible STT node. No-op — SignalWire handles STT server-side. */
    public function sttNode(mixed $audio = null, mixed $modelSettings = null): void
    {
        self::noopOnce(
            'stt_node',
            "Agent.stt_node(): SignalWire's control plane handles speech "
            . 'recognition -- this node is a no-op'
        );
    }

    /** LiveKit-compatible LLM node. No-op — SignalWire handles LLM server-side. */
    public function llmNode(mixed $chatCtx = null, mixed $tools = null, mixed $modelSettings = null): void
    {
        self::noopOnce(
            'llm_node',
            "Agent.llm_node(): SignalWire's control plane handles LLM "
            . 'inference -- this node is a no-op'
        );
    }

    /** LiveKit-compatible TTS node. No-op — SignalWire handles TTS server-side. */
    public function ttsNode(mixed $text = null, mixed $modelSettings = null): void
    {
        self::noopOnce(
            'tts_node',
            "Agent.tts_node(): SignalWire's control plane handles "
            . 'text-to-speech -- this node is a no-op'
        );
    }

    // ------------------------------------------------------------------
    // Dynamic updates
    // ------------------------------------------------------------------

    /** Update the agent's instructions mid-session. */
    public function updateInstructions(string $instructions): void
    {
        $this->instructions = $instructions;
    }

    /**
     * Update the agent's tool list mid-session.
     *
     * @param list<array<string, mixed>> $tools LiveWire tool descriptors.
     */
    public function updateTools(array $tools): void
    {
        $this->tools = $tools;
    }
}
