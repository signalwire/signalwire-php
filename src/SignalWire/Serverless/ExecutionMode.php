<?php

declare(strict_types=1);

namespace SignalWire\Serverless;

/**
 * The runtime environment {@see Adapter} detects, as a typed, backed enum.
 *
 * The five members are exactly the modes {@see Adapter::detect()} can return
 * (`lambda`, `gcf`, `azure`, `cgi`, `server`). The backing string of each case
 * IS that wire/dispatch token, so the enum and the legacy bare string are
 * interchangeable:
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
 * NOTE: this is the {@see Adapter} platform-detection vocabulary ONLY. It is
 * deliberately NOT unified with {@see \SignalWire\Logging\LoggingConfig}'s
 * execution-mode set (`cgi`/`lambda`/`google_cloud_function`/`azure_function`/
 * `server`), which mirrors Python's `get_execution_mode` and uses different,
 * longer tokens. The two vocabularies must not be merged.
 */
enum ExecutionMode: string
{
    /** AWS Lambda (API Gateway / Lambda Function URL). */
    case Lambda = 'lambda';

    /** Google Cloud Functions / Cloud Run. */
    case Gcf = 'gcf';

    /** Azure Functions. */
    case Azure = 'azure';

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
