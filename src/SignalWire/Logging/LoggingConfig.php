<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Logging;

/**
 * Central logging configuration for the SignalWire SDK, plus the
 * cross-language serverless / deployment-mode detection contract.
 *
 * Mirrors signalwire.core.logging_config.get_execution_mode and
 * signalwire.utils.is_serverless_mode in the Python reference. Order
 * of precedence (FIRST match wins):
 *
 *   1. GATEWAY_INTERFACE                                          -> 'cgi'
 *   2. AWS_LAMBDA_FUNCTION_NAME or LAMBDA_TASK_ROOT               -> 'lambda'
 *   3. FUNCTION_TARGET, K_SERVICE, or GOOGLE_CLOUD_PROJECT        -> 'google_cloud_function'
 *   4. AZURE_FUNCTIONS_ENVIRONMENT, FUNCTIONS_WORKER_RUNTIME, or
 *      AzureWebJobsStorage                                        -> 'azure_function'
 *   5. otherwise                                                  -> 'server'
 *
 * The static methods getExecutionMode() and isServerlessMode() project
 * onto the Python free functions
 *   * signalwire.core.logging_config.get_execution_mode
 *   * signalwire.utils.is_serverless_mode
 * via scripts/enumerate_signatures.py FREE_FUNCTION_PROJECTIONS.
 */
final class LoggingConfig
{
    /** Idempotency guard for {@see configureLogging()} (mirrors Python's _logging_configured). */
    private static bool $configured = false;

    /**
     * Control characters stripped from log values to prevent log injection.
     * Mirrors Python's `_CONTROL_CHAR_RE` / TS's `CONTROL_CHAR_RE`:
     * \x00-\x08, \x0b, \x0c, \x0e-\x1f, \x7f-\x9f.
     */
    private const CONTROL_CHAR_RE = '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f-\x9f]/u';

    /**
     * Configure the logging system once, globally, from environment
     * variables. Idempotent — subsequent calls are a no-op until
     * {@see resetLoggingConfiguration()} is invoked. Mirrors Python's
     * `configure_logging()`.
     *
     * Projected to the module-level free function
     * signalwire.core.logging_config.configure_logging via
     * scripts/enumerate_signatures.py FREE_FUNCTION_PROJECTIONS.
     */
    public static function configureLogging(): void
    {
        if (self::$configured) {
            return;
        }
        self::$configured = true;
    }

    /**
     * Reset the logging configuration so the next {@see configureLogging()}
     * re-reads the environment. Clears the cached Logger instances (they
     * snapshot env-derived level/mode at construction). Mirrors Python's
     * `reset_logging_configuration()`.
     *
     * Projected to signalwire.core.logging_config.reset_logging_configuration.
     */
    public static function resetLoggingConfiguration(): void
    {
        self::$configured = false;
        Logger::reset();
    }

    /**
     * Get a Logger instance for the given name, configuring logging first.
     * The single entry point for all logging in the SDK. Mirrors Python's
     * `get_logger()`.
     *
     * Projected to signalwire.core.logging_config.get_logger.
     */
    public static function getLogger(string $name): Logger
    {
        self::configureLogging();
        return Logger::getLogger($name);
    }

    /**
     * Strip control characters from every string value in a data record to
     * prevent log injection. Nested arrays are processed recursively. Mirrors
     * Python's `strip_control_chars` structlog processor / TS's
     * `stripControlChars`.
     *
     * Projected to signalwire.core.logging_config.strip_control_chars.
     *
     * @param array<mixed> $eventDict
     * @return array<mixed>
     */
    public static function stripControlChars(array $eventDict): array
    {
        foreach ($eventDict as $key => $value) {
            if (is_string($value)) {
                $eventDict[$key] = (string) preg_replace(self::CONTROL_CHAR_RE, '', $value);
            } elseif (is_array($value)) {
                $eventDict[$key] = self::stripControlChars($value);
            }
        }
        return $eventDict;
    }

    /**
     * Detect the SDK's deployment environment based on well-known
     * environment variables.
     *
     * @return string One of 'cgi', 'lambda', 'google_cloud_function',
     *                'azure_function', or 'server'.
     */
    public static function getExecutionMode(): string
    {
        if (self::isSet('GATEWAY_INTERFACE')) {
            return 'cgi';
        }
        if (self::isSet('AWS_LAMBDA_FUNCTION_NAME') || self::isSet('LAMBDA_TASK_ROOT')) {
            return 'lambda';
        }
        if (
            self::isSet('FUNCTION_TARGET')
            || self::isSet('K_SERVICE')
            || self::isSet('GOOGLE_CLOUD_PROJECT')
        ) {
            return 'google_cloud_function';
        }
        if (
            self::isSet('AZURE_FUNCTIONS_ENVIRONMENT')
            || self::isSet('FUNCTIONS_WORKER_RUNTIME')
            || self::isSet('AzureWebJobsStorage')
        ) {
            return 'azure_function';
        }
        return 'server';
    }

    /**
     * @return bool True when running in any serverless invocation
     *              environment (anything other than 'server').
     */
    public static function isServerlessMode(): bool
    {
        return self::getExecutionMode() !== 'server';
    }

    private static function isSet(string $name): bool
    {
        $v = getenv($name);
        return $v !== false && $v !== '';
    }
}
