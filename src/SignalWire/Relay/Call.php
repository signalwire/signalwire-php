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
        $this->client  = $client;
        $this->callId  = $params['call_id']  ?? null;
        $this->nodeId  = $params['node_id']  ?? null;
        $this->tag     = $params['tag']      ?? null;
        $this->device  = $params['device']   ?? [];
        $this->peer    = $params['peer']     ?? [];
        $this->context = $params['context']  ?? null;
        $this->state   = $params['state']    ?? 'created';

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
            if (isset($params['state'])) {
                $this->state = $params['state'];
            }
            if (isset($params['end_reason'])) {
                $this->endReason = $params['end_reason'];
            }
            if (isset($params['peer'])) {
                $this->peer = $params['peer'];
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
            $action->handleEvent($event);

            // Check whether the action has reached a terminal state
            $terminalMap = Constants::ACTION_TERMINAL_STATES[$eventType] ?? [];
            $actionState = $params['state'] ?? null;
            if ($actionState !== null && isset($terminalMap[$actionState])) {
                $action->resolve();
                unset($this->actions[$controlId]);
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

    public function hangup(): array
    {
        return $this->execute('calling.hangup');
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

    public function play(array $params): PlayAction
    {
        return $this->startAction('calling.play', PlayAction::class, $params);
    }

    public function record(array $params): RecordAction
    {
        return $this->startAction('calling.record', RecordAction::class, $params);
    }

    public function collect(array $params): CollectAction
    {
        return $this->startAction('calling.collect', CollectAction::class, $params);
    }

    public function playAndCollect(array $params): CollectAction
    {
        return $this->startAction('calling.play_and_collect', CollectAction::class, $params);
    }

    public function detect(array $params): DetectAction
    {
        return $this->startAction('calling.detect', DetectAction::class, $params);
    }

    public function sendFax(array $params): FaxAction
    {
        return $this->startAction('calling.send_fax', FaxAction::class, $params);
    }

    public function receiveFax(array $params): FaxAction
    {
        return $this->startAction('calling.receive_fax', FaxAction::class, $params);
    }

    public function tap(array $params): TapAction
    {
        return $this->startAction('calling.tap', TapAction::class, $params);
    }

    public function stream(array $params): StreamAction
    {
        return $this->startAction('calling.stream', StreamAction::class, $params);
    }

    public function pay(array $params): PayAction
    {
        return $this->startAction('calling.pay', PayAction::class, $params);
    }

    public function transcribe(array $params): TranscribeAction
    {
        return $this->startAction('calling.transcribe', TranscribeAction::class, $params);
    }

    public function ai(array $params): AIAction
    {
        return $this->startAction('calling.ai', AIAction::class, $params);
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
     * @param string $method      RPC method name
     * @param class-string<T> $actionClass  Concrete Action class
     * @param array  $extra       Additional params for the RPC call
     * @return T
     */
    private function startAction(string $method, string $actionClass, array $extra = []): object
    {
        $controlId = $this->generateUuid();

        $action = new $actionClass($controlId, $this);
        $this->actions[$controlId] = $action;

        $params = array_merge($this->baseParams(), ['control_id' => $controlId], $extra);

        try {
            $this->client->execute($method, $params);
        } catch (\RuntimeException $e) {
            // 404 (call not found) or 410 (call gone) – resolve immediately
            $code = $e->getCode();
            if ($code === 404 || $code === 410) {
                $this->logger->warn("Action {$method} got HTTP {$code}, resolving immediately");
                $action->resolve();
                unset($this->actions[$controlId]);
            } else {
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
