<?php

declare(strict_types=1);

namespace SignalWire\Relay;

use SignalWire\Logging\Logger;

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
    protected array $media;
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
     */
    public function __construct(array $params = [])
    {
        $this->messageId = $params['message_id'] ?? $params['id'] ?? null;
        $this->context = $params['context'] ?? null;
        $this->direction = $params['direction'] ?? null;
        $this->fromNumber = $params['from_number'] ?? $params['from'] ?? null;
        $this->toNumber = $params['to_number'] ?? $params['to'] ?? null;
        $this->body = $params['body'] ?? null;
        $this->media = $params['media'] ?? [];
        $this->tags = $params['tags'] ?? [];
        $this->state = $params['state'] ?? null;
        $this->reason = $params['reason'] ?? null;
    }

    // ------------------------------------------------------------------
    // Event handling
    // ------------------------------------------------------------------

    /**
     * Process an inbound event for this message.
     *
     * Updates state/reason, fires any registered event listeners, and
     * auto-resolves the message when it reaches a terminal state.
     */
    public function dispatchEvent(Event $event): void
    {
        $params = $event->getParams();

        // Update mutable fields from the event payload.
        if (isset($params['state'])) {
            $this->state = $params['state'];
        }
        if (isset($params['reason'])) {
            $this->reason = $params['reason'];
        }
        if (isset($params['body'])) {
            $this->body = $params['body'];
        }
        if (isset($params['media'])) {
            $this->media = $params['media'];
        }
        if (isset($params['tags'])) {
            $this->tags = $params['tags'];
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

    public function getMedia(): array
    {
        return $this->media;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getState(): ?string
    {
        return $this->state;
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
