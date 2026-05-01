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
    public array  $device = [];
    public array  $peer = [];
    public ?string $endReason = null;
    public ?string $context = null;
    public bool   $dialWinner = false;
    public ?string $direction = null;

    // ── back-references ───────────────────────────────────────────────
    /** @var object  RELAY Client instance */
    public object $client;

    /** @var array<string, Action> controlId => Action */
    public array $actions = [];

    /** @var array<callable> */
    public array $onEventCallbacks = [];

    private Logger $logger;

    // ──────────────────────────────────────────────────────────────────
    //  Construction
    // ──────────────────────────────────────────────────────────────────

    public function __construct(array $params, object $client)
    {
        $this->client    = $client;
        $this->callId    = $params['call_id']   ?? null;
        $this->nodeId    = $params['node_id']   ?? null;
        $this->tag       = $params['tag']       ?? null;
        $this->device    = $params['device']    ?? [];
        $this->peer      = $params['peer']      ?? [];
        $this->context   = $params['context']   ?? null;
        $this->state     = $params['state']     ?? $params['call_state'] ?? 'created';
        $this->direction = $params['direction'] ?? null;

        $this->logger = Logger::getLogger('relay.call');
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
            if ($newState !== null) {
                $this->state = $newState;
            }
            if (isset($params['end_reason'])) {
                $this->endReason = $params['end_reason'];
            }
            if (isset($params['peer'])) {
                $this->peer = $params['peer'];
            }
            if (isset($params['direction'])) {
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
                $this->peer = $params['peer'];
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
                if ($actionState !== null && isset($terminalMap[$actionState])) {
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
     */
    public function hangup(?string $reason = null): array
    {
        $extra = $reason !== null ? ['reason' => $reason] : [];
        return $this->execute('calling.end', $extra);
    }

    public function pass(): array
    {
        return $this->execute('calling.pass');
    }

    public function connect(array $params): array
    {
        return $this->execute('calling.connect', $params);
    }

    public function disconnect(): array
    {
        return $this->execute('calling.disconnect');
    }

    public function hold(): array
    {
        return $this->execute('calling.hold');
    }

    public function unhold(): array
    {
        return $this->execute('calling.unhold');
    }

    public function denoise(): array
    {
        return $this->execute('calling.denoise');
    }

    public function denoiseStop(): array
    {
        return $this->execute('calling.denoise.stop');
    }

    public function transfer(array $params): array
    {
        return $this->execute('calling.transfer', $params);
    }

    public function joinConference(array $params): array
    {
        return $this->execute('calling.conference.join', $params);
    }

    public function leaveConference(): array
    {
        return $this->execute('calling.conference.leave');
    }

    public function echo(): array
    {
        return $this->execute('calling.echo');
    }

    public function bindDigit(array $params): array
    {
        return $this->execute('calling.bind_digit', $params);
    }

    public function clearDigitBindings(): array
    {
        return $this->execute('calling.clear_digit_bindings');
    }

    public function liveTranscribe(array $params): array
    {
        return $this->execute('calling.live_transcribe', $params);
    }

    public function liveTranslate(array $params): array
    {
        return $this->execute('calling.live_translate', $params);
    }

    public function joinRoom(array $params): array
    {
        return $this->execute('calling.room.join', $params);
    }

    public function leaveRoom(): array
    {
        return $this->execute('calling.room.leave');
    }

    public function amazonBedrock(array $params): array
    {
        return $this->execute('calling.amazon_bedrock', $params);
    }

    public function aiMessage(array $params): array
    {
        return $this->execute('calling.ai.message', $params);
    }

    public function aiHold(): array
    {
        return $this->execute('calling.ai.hold');
    }

    public function aiUnhold(): array
    {
        return $this->execute('calling.ai.unhold');
    }

    public function userEvent(array $params): array
    {
        return $this->execute('calling.user_event', $params);
    }

    public function queueEnter(array $params): array
    {
        return $this->execute('calling.queue.enter', $params);
    }

    public function queueLeave(): array
    {
        return $this->execute('calling.queue.leave');
    }

    public function refer(array $params): array
    {
        return $this->execute('calling.refer', $params);
    }

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
     * @param array<string,mixed> $audio  Recording config (``format``, etc.)
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
     * @param array<string,mixed> $detect Detection request
     *   (``{type: 'machine'|'fax'|'digit', params: {...}}``).
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

    public function stream(string $url, array $opts = []): StreamAction
    {
        $extra = ['url' => $url] + $opts;
        return $this->startAction('calling.stream', StreamAction::class, $extra, $opts);
    }

    public function pay(string $paymentConnectorUrl, array $opts = []): PayAction
    {
        $extra = ['payment_connector_url' => $paymentConnectorUrl] + $opts;
        return $this->startAction('calling.pay', PayAction::class, $extra, $opts);
    }

    public function transcribe(array $opts = []): TranscribeAction
    {
        return $this->startAction('calling.transcribe', TranscribeAction::class, $opts, $opts);
    }

    /**
     * @param array<string,mixed> $prompt AI prompt config.
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
        $controlId = $opts['control_id'] ?? $wireParams['control_id'] ?? $this->generateUuid();

        $callId = $this->callId ?? '';
        $nodeId = $this->nodeId ?? '';

        // Construct the action with the right signature.
        if ($actionClass === FaxAction::class) {
            $faxType = $opts['fax_type'] ?? 'send';
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

        return $action;
    }

    /**
     * Common params present in every RPC call for this call.
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
