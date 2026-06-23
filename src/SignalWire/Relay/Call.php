<?php

declare(strict_types=1);

namespace SignalWire\Relay;

use SignalWire\Logging\Logger;

/**
 * Represents a RELAY voice call.
 *
 * Holds call-level state, dispatches server events to registered listeners
 * and to in-flight Action objects, and exposes every calling.* RPC method
 * as a first-class PHP method.
 */
class Call
{
    // ── identity ──────────────────────────────────────────────────────
    public ?string $callId;
    public ?string $nodeId;
    public ?string $tag;

    // ── state ─────────────────────────────────────────────────────────
    public string $state = 'created';
    /** @var array<string,mixed> */
    public array  $device = [];
    /** @var array<string,mixed> */
    public array  $peer = [];
    public ?string $endReason = null;
    public ?string $context = null;
    public bool   $dialWinner = false;
    public ?string $direction = null;

    // ── back-references ───────────────────────────────────────────────
    /**
     * RELAY client handle, typed via the internal RelayClientLike contract.
     * Mirrors the Python reference's PRIVATE ``self._client`` back-reference
     * (not part of the public surface); kept non-public so it is not a
     * port-only public extra.
     */
    protected RelayClientLike $client;

    /** @var array<string, Action> controlId => Action */
    public array $actions = [];

    /** @var array<callable> */
    public array $onEventCallbacks = [];

    private Logger $logger;

    // ──────────────────────────────────────────────────────────────────
    //  Construction
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $params
     */
    public function __construct(array $params, RelayClientLike $client)
    {
        $this->client    = $client;
        $this->callId    = self::asNullableString($params['call_id']   ?? null);
        $this->nodeId    = self::asNullableString($params['node_id']   ?? null);
        $this->tag       = self::asNullableString($params['tag']       ?? null);
        $this->device    = self::asStringKeyedArray($params['device']  ?? null);
        $this->peer      = self::asStringKeyedArray($params['peer']    ?? null);
        $this->context   = self::asNullableString($params['context']   ?? null);
        $state           = $params['state'] ?? $params['call_state'] ?? null;
        $this->state     = is_string($state) ? $state : 'created';
        $this->direction = self::asNullableString($params['direction'] ?? null);

        $this->logger = Logger::getLogger('relay.call');
    }

    /**
     * Narrow a JSON-decoded value to a string, or null when absent / a
     * non-string. The RELAY call identity fields (call_id, node_id, tag,
     * context, direction) are strings on the wire — see the Python
     * reference's ``str`` typing in relay/call.py.
     */
    private static function asNullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Narrow a JSON-decoded value to an ``array<string,mixed>`` (a JSON
     * object) — RELAY ``device``/``peer`` are objects (Python types them as
     * ``dict[str, Any]``). A missing or non-object value yields an empty
     * array.
     *
     * @return array<string,mixed>
     */
    private static function asStringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Event dispatch
    // ──────────────────────────────────────────────────────────────────

    /**
     * Central event router invoked by the Client whenever a server event
     * targets this call.
     */
    public function dispatchEvent(Event $event): void
    {
        $eventType = $event->getEventType();
        $params    = $event->getParams();

        $this->logger->debug("dispatchEvent: {$eventType}");

        // ── call-level state events ──────────────────────────────────
        if ($eventType === 'calling.call.state') {
            // Production wire uses ``call_state``; older fixtures use ``state``.
            $newState = $params['state'] ?? $params['call_state'] ?? null;
            if (is_string($newState)) {
                $this->state = $newState;
            }
            if (is_string($params['end_reason'] ?? null)) {
                $this->endReason = $params['end_reason'];
            }
            if (isset($params['peer'])) {
                $this->peer = self::asStringKeyedArray($params['peer']);
            }
            if (is_string($params['direction'] ?? null)) {
                $this->direction = $params['direction'];
            }

            // Terminal state – resolve every in-flight action
            if (isset(Constants::CALL_TERMINAL_STATES[$this->state])) {
                $this->resolveAllActions();
            }
        }

        // ── connect events carry peer info ───────────────────────────
        if ($eventType === 'calling.call.connect') {
            if (isset($params['peer'])) {
                $this->peer = self::asStringKeyedArray($params['peer']);
            }
        }

        // ── route by control_id to the owning Action ─────────────────
        $controlId = $event->getControlId();
        if ($controlId !== null && isset($this->actions[$controlId])) {
            $action = $this->actions[$controlId];

            // CollectAction silently ignores intermediate ``calling.call.play``
            // events that the play_and_collect verb interleaves with the
            // collect-side stream — see CollectAction::handleEvent. We
            // forward the event regardless and let the action decide.
            $action->handleEvent($event);

            // Detect resolves on the FIRST payload that carries a ``detect``
            // result, not on a state(finished) — see the Python
            // ``test_detect_resolves_on_first_detect_payload``.
            if (
                $action instanceof DetectAction
                && $eventType === 'calling.call.detect'
                && isset($params['detect'])
            ) {
                $action->resolve($event);
                unset($this->actions[$controlId]);
            } elseif (
                $action instanceof CollectAction
                && $eventType === 'calling.call.play'
            ) {
                // Ignore — handleEvent already swallowed it.
            } elseif (
                $action instanceof CollectAction
                && $eventType === 'calling.call.collect'
                && isset($params['result'])
            ) {
                // Production wire: collect emits a single payload carrying
                // ``result`` (digit / speech / both). Resolve on first one.
                $action->resolve($event);
                unset($this->actions[$controlId]);
            } else {
                $terminalMap = Constants::ACTION_TERMINAL_STATES[$eventType] ?? [];
                $actionState = $params['state'] ?? null;
                if (is_string($actionState) && isset($terminalMap[$actionState])) {
                    $action->resolve($event);
                    unset($this->actions[$controlId]);
                }
            }
        }

        // ── fire user-registered callbacks ───────────────────────────
        foreach ($this->onEventCallbacks as $cb) {
            $cb($event, $this);
        }
    }

    /**
     * Register a generic event listener on this call.
     */
    public function on(callable $cb): self
    {
        $this->onEventCallbacks[] = $cb;
        return $this;
    }

    /**
     * The current call lifecycle state as a typed {@see CallState}, or null
     * when the raw {@see $state} string is outside the known closed set
     * (a forward-compatible server value).
     *
     * Offered ALONGSIDE the bare-string {@see $state} property for parity with
     * the Python reference: `$call->state` stays the canonical string;
     * `$call->callState()` is the typed view for autocompletion + an
     * exhaustive `match` + `->isTerminal()`. PORT_ADDITION.
     */
    public function callState(): ?CallState
    {
        return CallState::tryFromWire($this->state);
    }

    // ──────────────────────────────────────────────────────────────────
    //  State-wait convenience (typed waits over the call lifecycle)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Lifecycle ordering used by the state-wait short-circuit.
     * ``created < ringing < answered < ending < ended`` — mirrors Python's
     * ``Call._wait_for_state`` ordering at relay/call.py.
     *
     * @var array<int,string>
     */
    private const STATE_ORDER = [
        Constants::CALL_STATE_CREATED,
        Constants::CALL_STATE_RINGING,
        Constants::CALL_STATE_ANSWERED,
        Constants::CALL_STATE_ENDING,
        Constants::CALL_STATE_ENDED,
    ];

    /**
     * Wait until the call reaches ``$target`` (or a later lifecycle state),
     * pumping inbound frames via ``$client->readOnce()`` — the same loop
     * {@see Action::wait()} uses, so we never mock the transport.
     *
     * If the call is ALREADY at or past ``$target`` this returns immediately
     * (matching Python's ``_wait_for_state`` which short-circuits when
     * ``rank(state) >= rank(target)``). Returns null on timeout.
     *
     * @param string         $target  Target lifecycle state.
     * @param int|float|null $timeout Seconds to wait; null uses the 30s default.
     */
    private function waitForState(string $target, int|float|null $timeout): bool
    {
        $targetRank = $this->stateRank($target);

        // Already at or past the target -> return immediately.
        if ($this->stateRank($this->state) >= $targetRank) {
            return true;
        }

        $deadline = microtime(true) + ($timeout ?? 30);
        while ($this->stateRank($this->state) < $targetRank
            && microtime(true) < $deadline
        ) {
            // readOnce() is part of the RelayClientLike contract.
            $this->client->readOnce();
        }

        return $this->stateRank($this->state) >= $targetRank;
    }

    /**
     * Rank a lifecycle state for the state-wait ordering. Unknown states
     * rank -1 (never satisfies a forward wait), matching Python's
     * ``order.index(s) if s in order else -1``.
     */
    private function stateRank(string $state): int
    {
        $idx = array_search($state, self::STATE_ORDER, true);
        return $idx === false ? -1 : (int) $idx;
    }

    /**
     * Wait until the call is answered (immediate if already answered or past
     * it). Typed wait over the call lifecycle, mirroring Python's
     * ``Call.wait_for_answered(timeout)``.
     *
     * @return bool True once the call is answered (or already past it); false
     *   on timeout.
     */
    public function waitForAnswered(int|float|null $timeout = null): bool
    {
        return $this->waitForState(Constants::CALL_STATE_ANSWERED, $timeout);
    }

    /**
     * Wait until the call is ringing (immediate if already ringing or past
     * it). Typed wait over the call lifecycle, mirroring Python's
     * ``Call.wait_for_ringing(timeout)``.
     *
     * @return bool True once the call is ringing (or already past it); false
     *   on timeout.
     */
    public function waitForRinging(int|float|null $timeout = null): bool
    {
        return $this->waitForState(Constants::CALL_STATE_RINGING, $timeout);
    }

    /**
     * Wait until the call is ending (immediate if already ending or past it).
     * Typed wait over the call lifecycle, mirroring Python's
     * ``Call.wait_for_ending(timeout)``.
     *
     * @return bool True once the call is ending (or already past it); false
     *   on timeout.
     */
    public function waitForEnding(int|float|null $timeout = null): bool
    {
        return $this->waitForState(Constants::CALL_STATE_ENDING, $timeout);
    }

    /**
     * Mark every outstanding action as completed.  Called when the call
     * enters a terminal state (ended).
     */
    public function resolveAllActions(): void
    {
        foreach ($this->actions as $controlId => $action) {
            $action->resolve();
        }
        $this->actions = [];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Simple RPC methods (fire-and-return)
    // ──────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function answer(): array
    {
        return $this->execute('calling.answer');
    }

    /**
     * Hang up the call. ``$reason`` is forwarded as ``reason`` on the
     * wire (RELAY accepts ``hangup``, ``busy``, ``decline``, etc.).
     *
     * The on-wire method is ``calling.end`` (production), which historically
     * was named ``calling.hangup`` — we send ``calling.end`` to match the
     * RELAY schema set extracted from switchblade.
     *
     * @return array<string,mixed>
     */
    public function hangup(?string $reason = null): array
    {
        $extra = $reason !== null ? ['reason' => $reason] : [];
        return $this->execute('calling.end', $extra);
    }

    /** @return array<string,mixed> */
    public function pass(): array
    {
        return $this->execute('calling.pass');
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function connect(array $params): array
    {
        return $this->execute('calling.connect', $params);
    }

    /** @return array<string,mixed> */
    public function disconnect(): array
    {
        return $this->execute('calling.disconnect');
    }

    /** @return array<string,mixed> */
    public function hold(): array
    {
        return $this->execute('calling.hold');
    }

    /** @return array<string,mixed> */
    public function unhold(): array
    {
        return $this->execute('calling.unhold');
    }

    /** @return array<string,mixed> */
    public function denoise(): array
    {
        return $this->execute('calling.denoise');
    }

    /** @return array<string,mixed> */
    public function denoiseStop(): array
    {
        return $this->execute('calling.denoise.stop');
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function transfer(array $params): array
    {
        return $this->execute('calling.transfer', $params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function joinConference(array $params): array
    {
        return $this->execute('calling.conference.join', $params);
    }

    /** @return array<string,mixed> */
    public function leaveConference(): array
    {
        return $this->execute('calling.conference.leave');
    }

    /** @return array<string,mixed> */
    public function echo(): array
    {
        return $this->execute('calling.echo');
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function bindDigit(array $params): array
    {
        return $this->execute('calling.bind_digit', $params);
    }

    /**
     * Clear all digit bindings, optionally filtered by realm.
     *
     * Mirrors Python's Call.clear_digit_bindings(*, realm=None, **kwargs).
     *
     * @param ?string $realm  Optional realm filter — restricts clearing to
     *                         bindings registered under that realm.
     * @param array<string,mixed> $kwargs Additional params forwarded to the
     *                                     wire call.
     * @return array<string,mixed>
     */
    public function clearDigitBindings(?string $realm = null, array $kwargs = []): array
    {
        $params = [];
        if ($realm !== null) {
            $params['realm'] = $realm;
        }
        $params = array_merge($params, $kwargs);
        return $this->execute('calling.clear_digit_bindings', $params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function liveTranscribe(array $params): array
    {
        return $this->execute('calling.live_transcribe', $params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function liveTranslate(array $params): array
    {
        return $this->execute('calling.live_translate', $params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function joinRoom(array $params): array
    {
        return $this->execute('calling.room.join', $params);
    }

    /** @return array<string,mixed> */
    public function leaveRoom(): array
    {
        return $this->execute('calling.room.leave');
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function amazonBedrock(array $params): array
    {
        return $this->execute('calling.amazon_bedrock', $params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function aiMessage(array $params): array
    {
        return $this->execute('calling.ai.message', $params);
    }

    /** @return array<string,mixed> */
    public function aiHold(): array
    {
        return $this->execute('calling.ai.hold');
    }

    /** @return array<string,mixed> */
    public function aiUnhold(): array
    {
        return $this->execute('calling.ai.unhold');
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function userEvent(array $params): array
    {
        return $this->execute('calling.user_event', $params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function queueEnter(array $params): array
    {
        return $this->execute('calling.queue.enter', $params);
    }

    /**
     * Remove the call from a named queue.
     *
     * Mirrors Python's Call.queue_leave(queue_name, *, control_id=None,
     * queue_id=None, status_url=None, **kwargs). When called with no args
     * the legacy "leave the current queue" behavior is preserved.
     *
     * @param ?string $queue_name  Name of the queue to leave. Required when
     *                             also supplying control_id / queue_id; when
     *                             null the legacy no-arg "leave-current"
     *                             behavior is preserved.
     * @param ?string $control_id  Optional control_id; auto-generated when
     *                             null and other named-args are supplied.
     * @param ?string $queue_id    Optional explicit queue_id passed to the
     *                             server.
     * @param ?string $status_url  Optional status callback URL.
     * @param array<string,mixed> $kwargs  Extra params merged onto the
     *                                     wire call.
     * @return array<string,mixed>
     */
    public function queueLeave(
        ?string $queue_name = null,
        ?string $control_id = null,
        ?string $queue_id = null,
        ?string $status_url = null,
        array $kwargs = []
    ): array {
        if ($queue_name === null
            && $control_id === null
            && $queue_id === null
            && $status_url === null
            && empty($kwargs)
        ) {
            // Legacy no-arg shape: leave whatever queue the call is in.
            return $this->execute('calling.queue.leave');
        }

        $params = [
            'control_id' => $control_id ?? bin2hex(random_bytes(16)),
        ];
        if ($queue_name !== null) {
            $params['queue_name'] = $queue_name;
        }
        if ($queue_id !== null) {
            $params['queue_id'] = $queue_id;
        }
        if ($status_url !== null) {
            $params['status_url'] = $status_url;
        }
        $params = array_merge($params, $kwargs);
        return $this->execute('calling.queue.leave', $params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function refer(array $params): array
    {
        return $this->execute('calling.refer', $params);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function sendDigits(array $params): array
    {
        return $this->execute('calling.send_digits', $params);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Action methods (return Action objects tracked by control_id)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Start a calling.play action.
     *
     * @param array<int,array<string,mixed>> $media   Play list
     *   (each entry is ``{type:..., params:...}``).
     * @param array<string,mixed> $opts ``control_id`` (auto-generated when
     *   omitted), ``on_completed`` callback fired on terminal events.
     */
    public function play(array $media, array $opts = []): PlayAction
    {
        return $this->startAction(
            'calling.play',
            PlayAction::class,
            ['play' => $media] + $opts,
            $opts,
        );
    }

    /**
     * Play text-to-speech. Typed convenience over {@see play()}.
     *
     * Restores the legacy ``play_tts(text=...)`` ergonomics so callers don't
     * hand-build the ``{type:"tts", params:{...}}`` media shape. Mirrors
     * Python's ``Call.play_tts(text, *, language, gender, voice, volume,
     * on_completed)``. Optional ``language`` / ``gender`` / ``voice`` are
     * nested under ``params`` only when supplied; ``volume`` rides at the
     * top level via the generic play frame; ``control_id`` / ``on_completed``
     * are honored from ``$opts``.
     *
     * Wire shape: play ``[{type:"tts", params:{text, language?, gender?,
     * voice?}}]`` with optional top-level ``volume``.
     *
     * @param array<string,mixed> $opts ``language``/``gender``/``voice``/
     *   ``volume`` plus the usual ``control_id`` / ``on_completed``.
     */
    public function playTts(string $text, array $opts = []): PlayAction
    {
        $params = ['text' => $text];
        foreach (['language', 'gender', 'voice'] as $key) {
            if (isset($opts[$key])) {
                $params[$key] = $opts[$key];
            }
        }
        $playOpts = $this->carryPlayOpts($opts);
        return $this->play([['type' => 'tts', 'params' => $params]], $playOpts);
    }

    /**
     * Play an audio file from a URL. Typed convenience over {@see play()}.
     *
     * Mirrors Python's ``Call.play_audio(url, *, volume, on_completed)``.
     *
     * Wire shape: play ``[{type:"audio", params:{url}}]`` with optional
     * top-level ``volume``.
     *
     * @param array<string,mixed> $opts ``volume`` plus ``control_id`` /
     *   ``on_completed``.
     */
    public function playAudio(string $url, array $opts = []): PlayAction
    {
        $playOpts = $this->carryPlayOpts($opts);
        return $this->play([['type' => 'audio', 'params' => ['url' => $url]]], $playOpts);
    }

    /**
     * Play silence for ``$duration`` seconds. Typed convenience over
     * {@see play()}. Mirrors Python's ``Call.play_silence(duration, *,
     * on_completed)``.
     *
     * Wire shape: play ``[{type:"silence", params:{duration}}]``.
     *
     * @param array<string,mixed> $opts ``control_id`` / ``on_completed``.
     */
    public function playSilence(int|float $duration, array $opts = []): PlayAction
    {
        $playOpts = $this->carryPlayOpts($opts);
        return $this->play(
            [['type' => 'silence', 'params' => ['duration' => $duration]]],
            $playOpts,
        );
    }

    /**
     * Play a named ringtone by country code. Typed convenience over
     * {@see play()}. Mirrors Python's ``Call.play_ringtone(name, *,
     * duration, volume, on_completed)``. ``duration`` is nested under
     * ``params`` only when supplied; ``volume`` rides at the top level.
     *
     * Wire shape: play ``[{type:"ringtone", params:{name, duration?}}]`` with
     * optional top-level ``volume``.
     *
     * @param array<string,mixed> $opts ``duration``/``volume`` plus
     *   ``control_id`` / ``on_completed``.
     */
    public function playRingtone(string $name, array $opts = []): PlayAction
    {
        $params = ['name' => $name];
        if (isset($opts['duration'])) {
            $params['duration'] = $opts['duration'];
        }
        $playOpts = $this->carryPlayOpts($opts);
        return $this->play([['type' => 'ringtone', 'params' => $params]], $playOpts);
    }

    /**
     * @param array<string,mixed> $audio  Recording config (``format``, etc.)
     * @param array<string,mixed> $opts   ``control_id`` / ``on_completed``.
     */
    public function record(array $audio, array $opts = []): RecordAction
    {
        return $this->startAction(
            'calling.record',
            RecordAction::class,
            ['record' => ['audio' => $audio]] + $opts,
            $opts,
        );
    }

    /**
     * Standalone collect (digits / speech / both).
     *
     * @param array<string,mixed> $opts
     *   - ``digits`` / ``speech`` / ``initial_timeout`` etc forwarded as-is
     *   - ``control_id``
     *   - ``start_input_timers`` (bool)
     *   - ``on_completed`` callback
     */
    public function collect(array $opts = []): CollectAction
    {
        // The collect verb takes top-level ``digits``/``speech``/``initial_timeout``
        // params directly — no wrapping object.
        return $this->startAction('calling.collect', CollectAction::class, $opts, $opts);
    }

    /**
     * Play media then collect a response (digits, speech).
     *
     * @param array<int,array<string,mixed>> $media
     * @param array<string,mixed> $collect
     * @param array<string,mixed> $opts ``control_id`` / ``on_completed``.
     */
    public function playAndCollect(array $media, array $collect, array $opts = []): CollectAction
    {
        return $this->startAction(
            'calling.play_and_collect',
            CollectAction::class,
            ['play' => $media, 'collect' => $collect] + $opts,
            $opts,
        );
    }

    /**
     * Play TTS then collect input. Typed media over {@see playAndCollect()}.
     *
     * Mirrors Python's ``Call.prompt_tts(text, collect, *, language, gender,
     * voice, volume, on_completed)``. Builds the same ``{type:"tts"}`` media
     * shape as {@see playTts()} and forwards the caller's ``$collect`` object
     * verbatim.
     *
     * Wire shape: play_and_collect ``[{type:"tts", params:{text, language?,
     * gender?, voice?}}]`` + the given ``collect`` with optional top-level
     * ``volume``.
     *
     * @param array<string,mixed> $collect Collect spec (``digits``/``speech``/…).
     * @param array<string,mixed> $opts ``language``/``gender``/``voice``/
     *   ``volume`` plus ``control_id`` / ``on_completed``.
     */
    public function promptTts(string $text, array $collect, array $opts = []): CollectAction
    {
        $params = ['text' => $text];
        foreach (['language', 'gender', 'voice'] as $key) {
            if (isset($opts[$key])) {
                $params[$key] = $opts[$key];
            }
        }
        $playOpts = $this->carryPlayOpts($opts);
        return $this->playAndCollect(
            [['type' => 'tts', 'params' => $params]],
            $collect,
            $playOpts,
        );
    }

    /**
     * Play an audio file then collect input. Typed media over
     * {@see playAndCollect()}. Mirrors Python's ``Call.prompt_audio(url,
     * collect, *, volume, on_completed)``.
     *
     * Wire shape: play_and_collect ``[{type:"audio", params:{url}}]`` + the
     * given ``collect`` with optional top-level ``volume``.
     *
     * @param array<string,mixed> $collect Collect spec (``digits``/``speech``/…).
     * @param array<string,mixed> $opts ``volume`` plus ``control_id`` /
     *   ``on_completed``.
     */
    public function promptAudio(string $url, array $collect, array $opts = []): CollectAction
    {
        $playOpts = $this->carryPlayOpts($opts);
        return $this->playAndCollect(
            [['type' => 'audio', 'params' => ['url' => $url]]],
            $collect,
            $playOpts,
        );
    }

    /**
     * @param array<string,mixed> $detect Detection request
     *   (``{type: 'machine'|'fax'|'digit', params: {...}}``).
     * @param array<string,mixed> $opts ``control_id`` / ``on_completed``.
     */
    public function detect(array $detect, array $opts = []): DetectAction
    {
        return $this->startAction(
            'calling.detect',
            DetectAction::class,
            ['detect' => $detect] + $opts,
            $opts,
        );
    }

    /**
     * Detect DTMF digits. Typed convenience over {@see detect()}.
     *
     * Mirrors Python's ``Call.detect_digit(*, digits, timeout,
     * on_completed)``. ``digits`` is nested under ``params`` only when
     * supplied; ``timeout`` rides at the top level via the generic detect
     * frame.
     *
     * Wire shape: detect ``{type:"digit", params:{digits?}}`` with optional
     * top-level ``timeout``.
     *
     * @param array<string,mixed> $opts ``digits``/``timeout`` plus
     *   ``control_id`` / ``on_completed``.
     */
    public function detectDigit(array $opts = []): DetectAction
    {
        $params = [];
        if (isset($opts['digits'])) {
            $params['digits'] = $opts['digits'];
        }
        $detectOpts = $this->carryDetectOpts($opts);
        // Empty params must serialize as a JSON object ({}), not an array ([]) —
        // the RELAY detect schema requires /detect/params to be an object.
        return $this->detect(
            ['type' => 'digit', 'params' => $params ?: (object) []],
            $detectOpts,
        );
    }

    /**
     * Detect human vs answering machine (AMD). Typed convenience over
     * {@see detect()}. Mirrors Python's ``Call.detect_answering_machine(*,
     * initial_timeout, end_silence_timeout, machine_voice_threshold,
     * machine_words_threshold, detect_interruptions, detect_message_end,
     * timeout, on_completed)``. Only the AMD keys the caller supplies are
     * emitted under ``params`` (matches Python's only-provided-keys behavior);
     * ``timeout`` rides at the top level.
     *
     * Wire shape: detect ``{type:"machine", params:{...only-provided...}}``
     * with optional top-level ``timeout``.
     *
     * @param array<string,mixed> $opts any of the AMD tuning keys above plus
     *   ``timeout`` / ``control_id`` / ``on_completed``.
     */
    public function detectAnsweringMachine(array $opts = []): DetectAction
    {
        $params = [];
        foreach ([
            'initial_timeout',
            'end_silence_timeout',
            'machine_voice_threshold',
            'machine_words_threshold',
            'detect_interruptions',
            'detect_message_end',
        ] as $key) {
            if (isset($opts[$key])) {
                $params[$key] = $opts[$key];
            }
        }
        $detectOpts = $this->carryDetectOpts($opts);
        // Empty params must serialize as a JSON object ({}), not an array ([]).
        return $this->detect(
            ['type' => 'machine', 'params' => $params ?: (object) []],
            $detectOpts,
        );
    }

    /**
     * Detect a fax tone (CED/CNG). Typed convenience over {@see detect()}.
     *
     * Mirrors Python's ``Call.detect_fax(*, tone, timeout, on_completed)``.
     * ``tone`` is nested under ``params`` only when supplied; ``timeout``
     * rides at the top level.
     *
     * Wire shape: detect ``{type:"fax", params:{tone?}}`` with optional
     * top-level ``timeout``.
     *
     * @param array<string,mixed> $opts ``tone``/``timeout`` plus
     *   ``control_id`` / ``on_completed``.
     */
    public function detectFax(array $opts = []): DetectAction
    {
        $params = [];
        if (isset($opts['tone'])) {
            $params['tone'] = $opts['tone'];
        }
        $detectOpts = $this->carryDetectOpts($opts);
        // Empty params must serialize as a JSON object ({}), not an array ([]).
        return $this->detect(
            ['type' => 'fax', 'params' => $params ?: (object) []],
            $detectOpts,
        );
    }

    /**
     * @param array<string,mixed> $opts ``control_id`` / ``on_completed``.
     */
    public function sendFax(string $document, ?string $identity = null, array $opts = []): FaxAction
    {
        $extra = ['document' => $document];
        if ($identity !== null) {
            $extra['identity'] = $identity;
        }
        $extra += $opts;
        return $this->startAction(
            'calling.send_fax',
            FaxAction::class,
            $extra,
            $opts + ['fax_type' => 'send'],
        );
    }

    /**
     * @param array<string,mixed> $opts ``control_id`` / ``on_completed``.
     */
    public function receiveFax(array $opts = []): FaxAction
    {
        return $this->startAction(
            'calling.receive_fax',
            FaxAction::class,
            $opts,
            $opts + ['fax_type' => 'receive'],
        );
    }

    /**
     * @param array<string,mixed> $tap     Tap config.
     * @param array<string,mixed> $device  Tap delivery device.
     * @param array<string,mixed> $opts    ``control_id`` / ``on_completed``.
     */
    public function tap(array $tap, array $device, array $opts = []): TapAction
    {
        return $this->startAction(
            'calling.tap',
            TapAction::class,
            ['tap' => $tap, 'device' => $device] + $opts,
            $opts,
        );
    }

    /**
     * @param array<string,mixed> $opts ``control_id`` / ``on_completed``.
     */
    public function stream(string $url, array $opts = []): StreamAction
    {
        $extra = ['url' => $url] + $opts;
        return $this->startAction('calling.stream', StreamAction::class, $extra, $opts);
    }

    /**
     * @param array<string,mixed> $opts ``control_id`` / ``on_completed``.
     */
    public function pay(string $paymentConnectorUrl, array $opts = []): PayAction
    {
        $extra = ['payment_connector_url' => $paymentConnectorUrl] + $opts;
        return $this->startAction('calling.pay', PayAction::class, $extra, $opts);
    }

    /**
     * @param array<string,mixed> $opts ``control_id`` / ``on_completed``.
     */
    public function transcribe(array $opts = []): TranscribeAction
    {
        return $this->startAction('calling.transcribe', TranscribeAction::class, $opts, $opts);
    }

    /**
     * @param array<string,mixed> $prompt AI prompt config.
     * @param array<string,mixed> $opts   ``control_id`` / ``on_completed``.
     */
    public function ai(array $prompt, array $opts = []): AIAction
    {
        return $this->startAction(
            'calling.ai',
            AIAction::class,
            ['prompt' => $prompt] + $opts,
            $opts,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    //  Private helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Send a simple (non-action) RPC call and return the decoded result.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function execute(string $method, array $extra = []): array
    {
        $params = array_merge($this->baseParams(), $extra);
        return $this->client->execute($method, $params);
    }

    /**
     * Spin up a long-running action tracked by a unique control_id.
     *
     * @template T of Action
     * @param string                $method      RPC method name
     * @param class-string<T>       $actionClass Concrete Action class
     * @param array<string,mixed>   $wireParams  Params merged into the RPC frame
     * @param array<string,mixed>   $opts        Caller-side options:
     *   - ``control_id`` (string) — explicit control_id, generated when absent
     *   - ``on_completed`` (callable) — fires on terminal event
     *   - ``fax_type`` (string) — for FaxAction discrimination
     * @return T
     */
    private function startAction(
        string $method,
        string $actionClass,
        array $wireParams = [],
        array $opts = [],
    ): object {
        // Force Action.php to load (it carries every Action subclass under
        // a single PSR-4 entry; without this, autoload misses on "first
        // touch" of PlayAction etc. when Call.php is the only ancestor of
        // the call chain that's been autoloaded).
        class_exists(Action::class);

        // Caller may pre-supply control_id; otherwise we generate one.
        $controlIdRaw = $opts['control_id'] ?? $wireParams['control_id'] ?? null;
        $controlId = is_string($controlIdRaw) ? $controlIdRaw : $this->generateUuid();

        $callId = $this->callId ?? '';
        $nodeId = $this->nodeId ?? '';

        // Construct the action with the right signature.
        if ($actionClass === FaxAction::class) {
            $faxTypeRaw = $opts['fax_type'] ?? 'send';
            $faxType = is_string($faxTypeRaw) ? $faxTypeRaw : 'send';
            $action = new FaxAction($controlId, $callId, $nodeId, $this->client, $faxType);
        } else {
            /** @psalm-suppress UnsafeInstantiation */
            $action = new $actionClass($controlId, $callId, $nodeId, $this->client);
        }

        // CollectAction is shared between calling.collect and
        // calling.play_and_collect — wire the right stop method on it.
        if ($action instanceof CollectAction && $method === 'calling.play_and_collect') {
            $action->setStopMethod('calling.play_and_collect.stop');
        }

        if (isset($opts['on_completed']) && is_callable($opts['on_completed'])) {
            $action->onCompleted($opts['on_completed']);
        }

        $this->actions[$controlId] = $action;

        // Strip caller-side options that must NOT leak onto the wire.
        $wireParams = $wireParams;
        unset(
            $wireParams['control_id'],
            $wireParams['on_completed'],
            $wireParams['fax_type'],
        );

        $params = array_merge(
            $this->baseParams(),
            ['control_id' => $controlId],
            $wireParams,
        );

        try {
            $this->client->execute($method, $params);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            if ($code === 404 || $code === 410) {
                $this->logger->warn("Action {$method} got HTTP {$code}, resolving immediately");
                $action->resolve();
                unset($this->actions[$controlId]);
            } else {
                unset($this->actions[$controlId]);
                throw $e;
            }
        }

        // Genuine invariant: the FaxAction branch only runs when $actionClass
        // === FaxAction::class, and otherwise $action is `new $actionClass`.
        // PHPStan cannot narrow the template T across the FaxAction branch, so
        // assert the real guarantee here rather than hand-waving the type.
        assert($action instanceof $actionClass);

        return $action;
    }

    /**
     * Build the ``$opts`` array forwarded to {@see play()} /
     * {@see playAndCollect()} from a convenience caller's ``$opts``. Carries
     * the top-level ``volume`` (when supplied) plus the pass-through
     * ``control_id`` / ``on_completed`` controls; the media-shaping keys
     * (text/url/duration/language/gender/voice/name) are consumed by the
     * caller and deliberately NOT forwarded onto the wire frame.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private function carryPlayOpts(array $opts): array
    {
        $out = [];
        if (isset($opts['volume'])) {
            $out['volume'] = $opts['volume'];
        }
        if (isset($opts['control_id'])) {
            $out['control_id'] = $opts['control_id'];
        }
        if (isset($opts['on_completed'])) {
            $out['on_completed'] = $opts['on_completed'];
        }
        return $out;
    }

    /**
     * Build the ``$opts`` array forwarded to {@see detect()} from a
     * convenience caller's ``$opts``. Carries the top-level ``timeout`` (when
     * supplied) plus ``control_id`` / ``on_completed``; the detect ``params``
     * keys are consumed by the caller and not forwarded directly.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private function carryDetectOpts(array $opts): array
    {
        $out = [];
        if (isset($opts['timeout'])) {
            $out['timeout'] = $opts['timeout'];
        }
        if (isset($opts['control_id'])) {
            $out['control_id'] = $opts['control_id'];
        }
        if (isset($opts['on_completed'])) {
            $out['on_completed'] = $opts['on_completed'];
        }
        return $out;
    }

    /**
     * Common params present in every RPC call for this call.
     *
     * @return array{node_id: ?string, call_id: ?string}
     */
    private function baseParams(): array
    {
        return [
            'node_id' => $this->nodeId,
            'call_id' => $this->callId,
        ];
    }

    /**
     * Generate a UUID v4.
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant RFC 4122

        return sprintf(
            '%08s-%04s-%04s-%04s-%012s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10, 6))
        );
    }
}
