<?php

declare(strict_types=1);

namespace SignalWire\Relay;

use SignalWire\Logging\Logger;

/**
 * Base class for all RELAY call actions (play, record, collect, etc.).
 *
 * An Action is the client-side handle returned when you start an
 * asynchronous operation on a call.  It accumulates events, tracks
 * state, and resolves once the operation reaches a terminal state.
 */
class Action
{
    protected string $controlId;
    protected string $callId;
    protected string $nodeId;
    protected ?string $state = null;
    protected bool $completed = false;
    /** @var mixed */
    protected $result = null;
    protected array $events = [];
    protected array $payload = [];
    /** @var callable|null */
    protected $onCompletedCallback = null;
    protected object $client;

    private bool $callbackFired = false;

    public function __construct(string $controlId, string $callId, string $nodeId, object $client)
    {
        $this->controlId = $controlId;
        $this->callId = $callId;
        $this->nodeId = $nodeId;
        $this->client = $client;
    }

    // ------------------------------------------------------------------
    // Blocking helpers
    // ------------------------------------------------------------------

    /**
     * Block until the action completes or $timeout seconds elapse.
     *
     * Each iteration calls $client->readOnce() so the event-loop
     * keeps processing inbound frames.
     *
     * Returns the resolving Event when the action completes (mirrors
     * Python's ``return self._wait_event.wait()``). Returns null on
     * timeout. The numeric ``$timeout`` is interpreted as seconds and
     * accepts integers or floats.
     *
     * @return Event|null
     */
    public function wait(int|float $timeout = 30): ?Event
    {
        $deadline = microtime(true) + $timeout;

        while (!$this->completed && microtime(true) < $deadline) {
            if (method_exists($this->client, 'readOnce')) {
                $this->client->readOnce();
            }
        }

        return $this->result instanceof Event ? $this->result : null;
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    public function isDone(): bool
    {
        return $this->completed;
    }

    /** @return mixed */
    public function getResult()
    {
        return $this->result;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @return Event[] */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function getControlId(): string
    {
        return $this->controlId;
    }

    public function getCallId(): string
    {
        return $this->callId;
    }

    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    // ------------------------------------------------------------------
    // Callback registration
    // ------------------------------------------------------------------

    /**
     * Register a callback to fire when the action completes.
     *
     * If the action is already done the callback fires immediately.
     */
    public function onCompleted(callable $cb): self
    {
        $this->onCompletedCallback = $cb;

        if ($this->completed && !$this->callbackFired) {
            $this->fireCallback();
        }

        return $this;
    }

    // ------------------------------------------------------------------
    // Event handling
    // ------------------------------------------------------------------

    /**
     * Append an incoming event and update local state / payload.
     */
    public function handleEvent(Event $event): void
    {
        $this->events[] = $event;
        $this->payload = array_merge($this->payload, $event->getParams());

        $state = $event->getState();
        if ($state !== null) {
            $this->state = $state;
        }
    }

    // ------------------------------------------------------------------
    // Resolution
    // ------------------------------------------------------------------

    /**
     * Mark this action as completed.
     *
     * The optional $result is stored and the onCompleted callback is
     * fired exactly once.
     *
     * @param mixed $result
     */
    public function resolve($result = null): void
    {
        if ($this->completed) {
            return;
        }

        $this->completed = true;
        $this->result = $result;

        $this->fireCallback();
    }

    // ------------------------------------------------------------------
    // Sub-command helpers (stop / pause / resume / etc.)
    // ------------------------------------------------------------------

    /**
     * Stop the running action by sending its stop sub-command.
     */
    public function stop(): void
    {
        $method = $this->getStopMethod();
        if ($method !== '') {
            $this->executeSubcommand($method);
        }
    }

    /**
     * Return the RELAY RPC method that stops this action.
     *
     * Subclasses MUST override this to return the correct method name
     * (e.g. 'calling.play.stop').
     */
    public function getStopMethod(): string
    {
        return '';
    }

    /**
     * Send a sub-command RPC through the client.
     *
     * The payload always includes control_id, call_id, and node_id so
     * the server knows which action instance to target.
     */
    public function executeSubcommand(string $method, array $extraParams = []): void
    {
        $params = array_merge([
            'control_id' => $this->controlId,
            'call_id' => $this->callId,
            'node_id' => $this->nodeId,
        ], $extraParams);

        if (method_exists($this->client, 'execute')) {
            $this->client->execute($method, $params);
        } else {
            Logger::getLogger('relay.action')->warn(
                "Client does not support execute(); cannot send {$method}"
            );
        }
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    private function fireCallback(): void
    {
        if ($this->callbackFired || $this->onCompletedCallback === null) {
            return;
        }
        $this->callbackFired = true;
        ($this->onCompletedCallback)($this);
    }
}

// ======================================================================
// Concrete action subclasses
// ======================================================================

/**
 * Handle for calling.play operations.
 */
class PlayAction extends Action
{
    public function getStopMethod(): string
    {
        return 'calling.play.stop';
    }

    public function pause(): void
    {
        $this->executeSubcommand('calling.play.pause');
    }

    public function resume(): void
    {
        $this->executeSubcommand('calling.play.resume');
    }

    /**
     * Adjust playback volume.
     *
     * @param float $db Volume adjustment in dB (e.g. -4.0 or 6.0).
     */
    public function volume(float $db): void
    {
        $this->executeSubcommand('calling.play.volume', [
            'volume' => $db,
        ]);
    }
}

/**
 * Handle for calling.record operations.
 */
class RecordAction extends Action
{
    public function getStopMethod(): string
    {
        return 'calling.record.stop';
    }

    /**
     * @param array<string,mixed> $extra Additional params to merge onto
     *   the wire (e.g. ``['behavior' => 'continuous']``).
     */
    public function pause(array $extra = []): void
    {
        $this->executeSubcommand('calling.record.pause', $extra);
    }

    public function resume(): void
    {
        $this->executeSubcommand('calling.record.resume');
    }

    public function getUrl(): ?string
    {
        return $this->payload['url'] ?? null;
    }

    public function getDuration(): ?float
    {
        $val = $this->payload['duration'] ?? null;
        return $val !== null ? (float) $val : null;
    }

    public function getSize(): ?int
    {
        $val = $this->payload['size'] ?? null;
        return $val !== null ? (int) $val : null;
    }
}

/**
 * Handle for calling.collect (and play_and_collect) operations.
 *
 * Note: play_and_collect emits intermediate calling.call.play events
 * that must be silently ignored so they do not pollute the collect
 * action's state.
 */
class CollectAction extends Action
{
    /**
     * Track the originating verb so ``stop()`` knows which sub-command
     * to send. ``calling.collect`` and ``calling.play_and_collect`` both
     * resolve to a CollectAction handle but their ``.stop`` sub-commands
     * differ on the wire.
     */
    private string $stopMethod = 'calling.collect.stop';

    public function setStopMethod(string $method): void
    {
        $this->stopMethod = $method;
    }

    public function getStopMethod(): string
    {
        return $this->stopMethod;
    }

    /**
     * Notify the server to start input timers now rather than waiting
     * for the initial-timeout to expire naturally.
     */
    public function startInputTimers(): void
    {
        $this->executeSubcommand('calling.collect.start_input_timers');
    }

    /**
     * Return the structured collect result from the payload.
     */
    public function getCollectResult(): ?array
    {
        return $this->payload['result'] ?? null;
    }

    /**
     * Override: silently ignore intermediate play events that arrive
     * during a play_and_collect operation.
     */
    public function handleEvent(Event $event): void
    {
        if ($event->getEventType() === 'calling.call.play') {
            return;
        }

        parent::handleEvent($event);
    }
}

/**
 * Handle for calling.detect operations (fax-tone, digit, machine, etc.).
 */
class DetectAction extends Action
{
    public function getStopMethod(): string
    {
        return 'calling.detect.stop';
    }

    public function getDetectResult(): ?array
    {
        return $this->payload['detect'] ?? $this->payload['result'] ?? null;
    }
}

/**
 * Handle for calling.fax operations (send or receive).
 */
class FaxAction extends Action
{
    protected string $faxType;

    /**
     * @param string $faxType 'send' or 'receive'
     */
    public function __construct(
        string $controlId,
        string $callId,
        string $nodeId,
        object $client,
        string $faxType = 'send'
    ) {
        parent::__construct($controlId, $callId, $nodeId, $client);
        $this->faxType = $faxType;
    }

    public function getFaxType(): string
    {
        return $this->faxType;
    }

    public function getStopMethod(): string
    {
        return $this->faxType === 'receive'
            ? 'calling.receive_fax.stop'
            : 'calling.send_fax.stop';
    }
}

/**
 * Handle for calling.tap operations.
 */
class TapAction extends Action
{
    public function getStopMethod(): string
    {
        return 'calling.tap.stop';
    }
}

/**
 * Handle for calling.stream operations.
 */
class StreamAction extends Action
{
    public function getStopMethod(): string
    {
        return 'calling.stream.stop';
    }
}

/**
 * Handle for calling.pay operations.
 */
class PayAction extends Action
{
    public function getStopMethod(): string
    {
        return 'calling.pay.stop';
    }
}

/**
 * Handle for calling.transcribe operations.
 */
class TranscribeAction extends Action
{
    public function getStopMethod(): string
    {
        return 'calling.transcribe.stop';
    }
}

/**
 * Handle for calling.ai operations.
 */
class AIAction extends Action
{
    public function getStopMethod(): string
    {
        return 'calling.ai.stop';
    }
}
