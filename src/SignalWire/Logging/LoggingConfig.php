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
 * Cross-language SDK contract for serverless / deployment-mode detection.
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
