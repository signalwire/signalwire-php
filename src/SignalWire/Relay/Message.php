<?php

declare(strict_types=1);

namespace SignalWire\Relay;

/**
 * Represents a RELAY messaging message (SMS / MMS).
 *
 * A Message is created when you send or receive a message through the
 * RELAY messaging namespace.  It accumulates state-change events and
 * resolves once the message reaches a terminal state (delivered,
 * undelivered, or failed).
 */
class Message
{
    protected ?string $messageId;
    protected ?string $context;
    protected ?string $direction;
    protected ?string $fromNumber;
    protected ?string $toNumber;
    protected ?string $body;
    /** @var list<string> */
    protected array $media;
    /** @var list<string> */
    protected array $tags;
    protected ?string $state = null;
    protected ?string $reason = null;
    protected bool $completed = false;
    /** @var mixed */
    protected $result = null;
    /** @var callable|null */
    protected $onCompletedCallback = null;
    /** @var callable[] */
    protected array $onEventCallbacks = [];

    private bool $callbackFired = false;

    /**
     * Build a Message from a params array (as returned by the server).
     *
     * Expected keys (all optional with sane defaults):
     *   message_id, context, direction, from_number / from,
     *   to_number / to, body, media, tags, state, reason
     *
     * @param array<string,mixed> $params
     */
    public function __construct(array $params = [])
    {
        $this->messageId = self::asNullableString($params['message_id'] ?? $params['id'] ?? null);
        $this->context = self::asNullableString($params['context'] ?? null);
        $this->direction = self::asNullableString($params['direction'] ?? null);
        $this->fromNumber = self::asNullableString($params['from_number'] ?? $params['from'] ?? null);
        $this->toNumber = self::asNullableString($params['to_number'] ?? $params['to'] ?? null);
        $this->body = self::asNullableString($params['body'] ?? null);
        $this->media = self::asStringList($params['media'] ?? null);
        $this->tags = self::asStringList($params['tags'] ?? null);
        $this->state = self::asNullableString($params['state'] ?? null);
        $this->reason = self::asNullableString($params['reason'] ?? null);
    }

    /**
     * Narrow a JSON-decoded value to a string, or null when it is absent or
     * a non-string (the RELAY messaging fields below are all strings on the
     * wire — see the Python reference's ``str`` typing in relay/message.py).
     */
    private static function asNullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Narrow a JSON-decoded value to a ``list<string>`` — keeping only the
     * string entries (RELAY ``media``/``tags`` are arrays of strings; the
     * Python reference types them as ``list[str]``). A missing or non-array
     * value yields an empty list.
     *
     * @return list<string>
     */
    private static function asStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Event handling
    // ------------------------------------------------------------------

    /**
     * Process an inbound event for this message.
     *
     * Updates state/reason, fires any registered event listeners, and
     * auto-resolves the message when it reaches a terminal state.
     *
     * Accepts both ``state`` and ``message_state`` keys on the event
     * payload — production RELAY emits ``message_state`` while older
     * fixtures use ``state``.
     */
    public function dispatchEvent(Event $event): void
    {
        $params = $event->getParams();

        // Update mutable fields from the event payload.
        $newState = $params['state'] ?? $params['message_state'] ?? null;
        if (is_string($newState)) {
            $this->state = $newState;
        }
        if (is_string($params['reason'] ?? null)) {
            $this->reason = $params['reason'];
        }
        if (is_string($params['body'] ?? null)) {
            $this->body = $params['body'];
        }
        if (isset($params['media'])) {
            $this->media = self::asStringList($params['media']);
        }
        if (isset($params['tags'])) {
            $this->tags = self::asStringList($params['tags']);
        }

        // Notify all registered event listeners.
        foreach ($this->onEventCallbacks as $cb) {
            $cb($this, $event);
        }

        // Auto-resolve when we hit a terminal state.
        if ($this->state !== null && isset(Constants::MESSAGE_TERMINAL_STATES[$this->state])) {
            $this->resolve($this->state);
        }
    }

    /**
     * Alias for ``dispatchEvent`` so the Client's event router (which
     * calls ``handleEvent`` for symmetry with Action) doesn't need a
     * special case. Both names route the same way.
     */
    public function handleEvent(Event $event): void
    {
        $this->dispatchEvent($event);
    }

    // ------------------------------------------------------------------
    // Blocking helper
    // ------------------------------------------------------------------

    /**
     * Block until the message completes or $timeout seconds elapse.
     *
     * The caller is expected to feed events into dispatchEvent() from
     * another mechanism (e.g. the client's read loop).  This method
     * simply spins until completion.
     *
     * @return mixed The resolved result, or null on timeout.
     */
    public function wait(int $timeout = 30)
    {
        $deadline = microtime(true) + $timeout;

        while (!$this->completed && microtime(true) < $deadline) {
            // Yield the CPU briefly so we don't spin at 100%.
            usleep(5000);
        }

        return $this->result;
    }

    // ------------------------------------------------------------------
    // Callback registration
    // ------------------------------------------------------------------

    /**
     * Register a listener that fires on every state-change event.
     */
    public function on(callable $cb): self
    {
        $this->onEventCallbacks[] = $cb;
        return $this;
    }

    /**
     * Register a callback to fire when the message reaches a terminal state.
     *
     * If the message is already complete the callback fires immediately.
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
    // Accessors
    // ------------------------------------------------------------------

    public function isDone(): bool
    {
        return $this->completed;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function getDirection(): ?string
    {
        return $this->direction;
    }

    public function getFromNumber(): ?string
    {
        return $this->fromNumber;
    }

    public function getToNumber(): ?string
    {
        return $this->toNumber;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    /** @return list<string> */
    public function getMedia(): array
    {
        return $this->media;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    /**
     * The current delivery state as a typed {@see MessageState}, or null when
     * the raw {@see getState()} string is outside the known closed set
     * (a forward-compatible server value).
     *
     * Offered ALONGSIDE the bare-string {@see getState()} accessor for compatibility
     * with the Python reference: `getState()` stays the canonical string;
     * `messageState()` is the typed view for autocompletion + an exhaustive
     * `match` + `->isTerminal()`. PORT_ADDITION.
     */
    public function messageState(): ?MessageState
    {
        return MessageState::tryFromWire($this->state);
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /** @return mixed */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * The terminal result (RELAY event) once the message is done, or null if
     * it has not yet reached a terminal state.
     *
     * Mirrors Python's ``Message.result`` @property: returns the stored
     * terminal result only when {@see isDone()} is true, else null (unlike
     * {@see getResult()}, which always returns the raw stored value).
     *
     * @return mixed
     */
    public function result()
    {
        return $this->completed ? $this->result : null;
    }

    // ------------------------------------------------------------------
    // Resolution
    // ------------------------------------------------------------------

    /**
     * Mark this message as completed.
     *
     * The optional $result is stored and the onCompleted callback fires
     * exactly once.
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
