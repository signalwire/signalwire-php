<?php

declare(strict_types=1);

namespace SignalWire\Livewire;

/**
 * Mirrors a LiveKit `AgentServer` — registers the session entry-point and (via
 * {@see LiveWire::runApp()}) starts it.
 *
 * `rtcSession()` registers the entry-point callback. It supports both the bare
 * form (`$server->rtcSession($fn)`) and the parameterized-decorator form
 * (`$server->rtcSession(agentName: 'x')($fn)`), returning a registrar closure
 * when no function is supplied — the PHP analog of livekit's decorator usage.
 */
class AgentServer
{
    use NoopLog;

    /** Optional prewarm hook called before the entry-point (Python's setup_fnc). */
    public mixed $setupFnc = null;

    /** @internal Registered entry-point callback. */
    private mixed $entrypoint = null;

    /** Agent-name hint registered via rtcSession() (LiveKit parity). */
    public string $agentName = '';

    /**
     * Constructor options, captured for LiveKit source-compatibility.
     *
     * @var array<string, mixed>
     */
    public array $kwargs;

    /**
     * @param array<string, mixed> $kwargs LiveKit-shaped options (ignored).
     */
    public function __construct(array $kwargs = [])
    {
        $this->kwargs = $kwargs;
    }

    /**
     * Register the session entry-point.
     *
     * @param callable|null $func         Entry-point (bare form), or null for the
     *                                    parameterized-decorator form.
     * @param string        $agentName    Agent-name hint.
     * @param string        $type         Server topology (advisory if != "room").
     * @param mixed         $onRequest    LiveKit hook (ignored).
     * @param mixed         $onSessionEnd LiveKit hook (ignored).
     *
     * @return callable|null The entry-point when supplied directly, otherwise a
     *                       registrar closure `fn(callable): callable`.
     */
    public function rtcSession(
        ?callable $func = null,
        string $agentName = '',
        string $type = 'room',
        mixed $onRequest = null,
        mixed $onSessionEnd = null,
    ): ?callable {
        if ($type !== 'room') {
            self::noopOnce(
                'server_type',
                "AgentServer.rtc_session(type='{$type}'): SignalWire's control "
                . 'plane handles server topology at scale automatically'
            );
        }

        $register = function (callable $fn) use ($agentName): callable {
            $this->entrypoint = $fn;
            if ($agentName !== '') {
                $this->agentName = $agentName;
            }
            return $fn;
        };

        if ($func !== null) {
            $register($func);
            return null;
        }
        return $register;
    }

    /**
     * The registered entry-point callback, or null if none registered.
     *
     * @internal LiveWire accessor consumed by {@see LiveWire::runApp()}.
     */
    public function getEntrypoint(): mixed
    {
        return $this->entrypoint;
    }
}
