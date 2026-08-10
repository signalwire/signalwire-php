<?php

declare(strict_types=1);

namespace SignalWire\Serverless;

/**
 * The runtime environment {@see Adapter} detects, as a typed, backed enum.
 *
 * The five members are exactly the modes {@see Adapter::detect()} can return
 * (`lambda`, `google_cloud_function`, `azure_function`, `cgi`, `server`) — the
 * SAME closed vocabulary the Python reference's `get_execution_mode()` returns
 * and that `handle_serverless_request(mode=…)` dispatches on. The backing
 * string of each case IS that wire/dispatch token, so the enum and the bare
 * string are interchangeable:
 *
 *     Adapter::detect();                       // 'lambda'         (string)
 *     Adapter::detectMode();                   // ExecutionMode::Lambda (typed)
 *     Adapter::detectMode()->value;            // 'lambda'         (round-trips)
 *     ExecutionMode::from(Adapter::detect());  // ExecutionMode::Lambda
 *
 * {@see Adapter::serve()} accepts an explicit `ExecutionMode|string|null`
 * override alongside auto-detection, so callers may pin the mode in a typed,
 * typo-checked way while the bare string still works for matches the
 * stringly-typed original.
 *
 * This closed set is knowable from `Adapter::detect()`'s own implementation, so
 * per the idiom philosophy (knowable → type it) it is modelled as a native enum
 * *alongside* the string rather than left as a magic-string literal. It is a PHP
 * PORT_ADDITION — the Python reference has no equivalent (its serverless
 * handling lives in the broader `cli.simulation.mock_env` machinery).
 *
 * There is ONE execution-mode vocabulary across the SDK: these tokens are the
 * same ones {@see \SignalWire\Logging\LoggingConfig::getExecutionMode()}
 * reports, so a mode read from one may be passed to the other unchanged.
 *
 * Note for upgrades: the two cloud-function modes were previously spelled
 * `gcf` and `azure`. Those short spellings are no longer accepted — pass
 * `google_cloud_function` and `azure_function` instead.
 */
enum ExecutionMode: string
{
    /** AWS Lambda (API Gateway / Lambda Function URL). */
    case Lambda = 'lambda';

    /** Google Cloud Functions / Cloud Run. */
    case GoogleCloudFunction = 'google_cloud_function';

    /** Azure Functions. */
    case AzureFunction = 'azure_function';

    /** CGI / FastCGI. */
    case Cgi = 'cgi';

    /** The built-in PHP server (the default, non-serverless mode). */
    case Server = 'server';

    /**
     * True for every serverless invocation mode — anything other than the
     * built-in {@see ExecutionMode::Server}.
     */
    public function isServerless(): bool
    {
        return $this !== self::Server;
    }

    /**
     * Coerce a mode given as either this enum or its backing string into the
     * enum, validating the string against the closed set.
     *
     * Accepting `ExecutionMode|string` lets the public API take the typed enum
     * for safety while preserving the bare-string call style for compatibility.
     *
     * @throws \ValueError if $mode is a string outside the closed set.
     */
    public static function coerce(self|string $mode): self
    {
        return $mode instanceof self ? $mode : self::from($mode);
    }
}
