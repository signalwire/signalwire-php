<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

/**
 * Static facade hosting LiveWire's package-level free functions.
 *
 * PHP has no module-level free functions (PSR-4 is one-class-per-file), so the
 * Python module-level `signalwire.livewire.function_tool` / `run_app` are hosted
 * here as static methods and projected back onto the module-level names via the
 * enumerator's FREE_FUNCTION_PROJECTIONS (mirrors the LoggingConfig / SignalWire
 * static-facade precedent). Not a LiveKit type — a PHP hosting device.
 */
final class LiveWire
{
    use NoopLog;

    /**
     * Create a LiveWire tool descriptor — mirrors livekit's `@function_tool`.
     *
     * PHP has no function decorators, so a "tool" is the associative array
     * `['name', 'description', 'parameters', 'handler']` that {@see Agent} and
     * {@see AgentSession} accept. The handler is invoked as
     * `fn(array $args, RunContext $ctx): FunctionResult|string|mixed`.
     *
     * @param callable            $handler     Tool handler.
     * @param string|null         $name        Tool name (defaults to '').
     * @param string|null         $description Description shown to the LLM.
     * @param array<string,mixed> $parameters  JSON-schema `properties` map.
     *
     * @return array{name: string, description: string, parameters: array<string,mixed>, handler: callable}
     */
    public static function functionTool(
        callable $handler,
        ?string $name = null,
        ?string $description = null,
        array $parameters = [],
    ): array {
        return [
            'name'        => $name ?? '',
            'description' => $description ?? '',
            'parameters'  => $parameters,
            'handler'     => $handler,
        ];
    }

    /**
     * Print the banner + a random tip, run the entry-point, start the agent.
     *
     * Mirrors `livekit.agents.cli.run_app`:
     *  1. Print the LiveWire banner.
     *  2. Run the registered prewarm/setup hook (if any) with a fresh JobProcess.
     *  3. Create a fresh {@see JobContext}.
     *  4. Print a random tip.
     *  5. Invoke the entry-point with the context.
     *  6. Start the underlying SignalWire agent if the entry bound one to `ctx`.
     */
    public static function runApp(AgentServer $server): void
    {
        self::printBanner();

        $setup = $server->setupFnc;
        if (is_callable($setup)) {
            $proc = new JobProcess();
            $setup($proc);
        }

        $ctx = new JobContext();

        self::printTip();

        $entry = $server->getEntrypoint();
        if (is_callable($entry)) {
            $entry($ctx);
        }

        // The entry-point may bind a started AgentBase (or session) to ctx.
        $agent = $ctx->agent;
        if ($agent !== null && is_object($agent) && method_exists($agent, 'run')) {
            $agent->run();
        }
    }

    /** The LiveWire ASCII banner. */
    public const BANNER = <<<'BANNER'

    __    _            _       ___
   / /   (_)   _____  | |     / (_)_______
  / /   / / | / / _ \ | | /| / / / ___/ _ \
 / /___/ /| |/ /  __/ | |/ |/ / / /  /  __/
/_____/_/ |___/\___/  |__/|__/_/_/   \___/

 LiveKit-compatible agents powered by SignalWire
BANNER;

    /**
     * Rotating "Did you know?" tips (same 10 as the Python/Go module).
     *
     * @var list<string>
     */
    public const TIPS = [
        'SignalWire agents support DataMap tools that execute server-side -- no webhook infrastructure needed. See: docs/datamap_guide.md',
        'SignalWire Contexts & Steps give you mechanical state control over conversations -- no prompt engineering needed. See: docs/contexts_guide.md',
        'SignalWire agents can transfer calls between agents with a single SwmlTransfer() action',
        'SignalWire handles 18 built-in skills (datetime, math, web search, etc.) with one-liner integration via agent.AddSkill()',
        'SignalWire agents support SMS, conferencing, call recording, and SIP -- all from the same agent',
        "Your agent's entire AI pipeline (STT, LLM, TTS, VAD) runs in SignalWire's cloud -- zero infrastructure to manage",
        'SignalWire prefab agents (Survey, Receptionist, FAQ, Concierge) give you production patterns in 10 lines of code',
        "SignalWire's RELAY client gives you real-time WebSocket call control with 57+ methods -- play, record, detect, conference, and more",
        'SignalWire agents auto-generate SWML documents -- the platform handles media, turn detection, and barge-in for you',
        'You can host multiple agents on one server with AgentServer -- each with its own route, prompt, and tools',
    ];

    /** @internal Print the ASCII banner to stderr (ANSI cyan when a TTY). */
    private static function printBanner(): void
    {
        $out = fopen('php://stderr', 'w');
        if ($out === false) {
            return;
        }
        if (function_exists('posix_isatty') && @posix_isatty($out)) {
            fwrite($out, "\033[36m" . self::BANNER . "\033[0m\n");
        } else {
            fwrite($out, self::BANNER . "\n");
        }
        fclose($out);
    }

    /** @internal Print a random "Did you know?" tip to stderr. */
    private static function printTip(): void
    {
        $tip = self::TIPS[array_rand(self::TIPS)];
        $out = fopen('php://stderr', 'w');
        if ($out === false) {
            return;
        }
        fwrite($out, "\n\u{1f4a1} Did you know?  {$tip}\n\n");
        fclose($out);
    }
}
