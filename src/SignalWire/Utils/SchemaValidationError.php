<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Utils;

/**
 * SchemaValidationError — PHP port of
 * signalwire.utils.schema_utils.SchemaValidationError.
 *
 * Thrown when SWML schema validation of a verb config fails.
 */
class SchemaValidationError extends \RuntimeException
{
    private string $verbName;

    /** @var list<string> */
    private array $errors;

    /**
     * Construct a SchemaValidationError. Mirrors Python's
     * SchemaValidationError(verb_name, errors).
     *
     * @param string       $verbName the verb whose validation failed
     * @param list<string> $errors   human-readable error messages
     */
    public function __construct(string $verbName, array $errors)
    {
        $this->verbName = $verbName;
        $this->errors = array_values(array_filter($errors, 'is_string'));
        $message = "Schema validation failed for '$verbName': " . implode('; ', $this->errors);
        parent::__construct($message);
    }

    public function getVerbName(): string
    {
        return $this->verbName;
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
