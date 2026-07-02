<?php

declare(strict_types=1);

namespace SignalWire\SWML;

/**
 * Registry for SWML verb handlers.
 *
 * Maintains a registry of handlers for special SWML verbs and provides methods
 * for accessing them. The "ai" verb handler ({@see AIVerbHandler}) is
 * registered automatically on construction.
 *
 * Mirrors Python's `signalwire/core/swml_handler.py::VerbHandlerRegistry` and
 * the TypeScript `VerbHandlerRegistry`.
 */
class VerbHandlerRegistry
{
    /** @var array<string, SWMLVerbHandler> */
    private array $handlers = [];

    /**
     * Initialise the registry with default handlers.
     */
    public function __construct()
    {
        // Register default handlers (matches Python's __init__).
        $this->registerHandler(new AIVerbHandler());
    }

    /**
     * Register a new verb handler, replacing any existing handler for the same
     * verb name.
     */
    public function registerHandler(SWMLVerbHandler $handler): void
    {
        $verbName = $handler->getVerbName();
        $this->handlers[$verbName] = $handler;
    }

    /**
     * Get the handler for a specific verb, or null if none is registered.
     */
    public function getHandler(string $verbName): ?SWMLVerbHandler
    {
        return $this->handlers[$verbName] ?? null;
    }

    /**
     * Check whether a handler exists for a specific verb.
     */
    public function hasHandler(string $verbName): bool
    {
        return isset($this->handlers[$verbName]);
    }
}
