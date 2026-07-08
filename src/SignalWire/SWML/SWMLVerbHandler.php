<?php

declare(strict_types=1);

namespace SignalWire\SWML;

/**
 * Base interface for SWML verb handlers.
 *
 * This abstract class defines the interface that all SWML verb handlers must
 * implement. Verb handlers provide specialized logic for complex SWML verbs
 * that cannot be handled generically by the schema-driven builder (the "ai"
 * verb, in particular, needs special prompt/SWAIG handling).
 *
 * Mirrors Python's `signalwire/core/swml_handler.py::SWMLVerbHandler`
 * (abstract base) and the TypeScript `SWMLVerbHandler` abstract class.
 */
abstract class SWMLVerbHandler
{
    /**
     * Get the name of the SWML verb this handler handles.
     *
     * @return string The verb name (e.g. "ai", "play").
     */
    abstract public function getVerbName(): string;

    /**
     * Validate the configuration for this verb.
     *
     * @param array<string, mixed> $config The configuration for this verb.
     * @return array{0: bool, 1: list<string>} (isValid, errorMessages) tuple.
     */
    abstract public function validateConfig(array $config): array;

    /**
     * Build a configuration for this verb from the provided arguments.
     *
     * @param array<string, mixed> $kwargs Keyword arguments specific to this verb.
     * @return array<string, mixed> The verb configuration.
     */
    abstract public function buildConfig(array $kwargs = []): array;
}
