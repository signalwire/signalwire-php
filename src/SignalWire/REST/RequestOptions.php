<?php

declare(strict_types=1);

namespace SignalWire\REST;

/**
 * RequestOptions — the REST request-options envelope (plan 4.2).
 *
 * A single value object controlling per-request transport behavior: timeout,
 * retries (with an idempotency-aware retry policy + exponential backoff), and
 * cooperative cancellation. Supplied at two levels:
 *
 * - **Client default**: ``new RestClient(..., requestOptions: ...)`` stored on
 *   the {@see HttpClient} and applied to every request.
 * - **Per-request override**: each verb accepts an optional ``$requestOptions``
 *   that *shallow-overrides* the client default for that one call — an unset
 *   (``null``) field falls back to the client default, then the built-in
 *   default.
 *
 * The timeout + retry semantics are a wire-observable contract (the server sees
 * N attempts and the backoff ordering is honored). ``abortSignal``
 * fidelity is per-port idiom: every port exposes the field; how deeply the
 * cancellation cuts is the language's business. PHP's REST client is
 * synchronous (cURL blocks), so it cannot interrupt an in-flight socket read;
 * cancellation is checked cooperatively *between* attempts — the honest,
 * portable minimum. Mirrors the Python reference's
 * ``signalwire.rest._request_options.RequestOptions``.
 *
 * All fields are optional; ``null`` means "inherit" and resolves at apply-time
 * (via {@see resolve()}) to the client default, then the built-in default.
 *
 * The static {@see resolve()} / {@see statusIsRetryable()} helpers mirror
 * Python's module-level ``signalwire.rest._request_options.resolve`` /
 * ``status_is_retryable`` free functions (PHP is PSR-4 file-per-class, so the
 * module-level functions are hosted as static methods here and projected onto
 * the canonical module-level names by the signature enumerator).
 */
final class RequestOptions
{
    /** Built-in defaults — the contract floor an unset field resolves to. */
    public const DEFAULT_TIMEOUT = 30.0;
    public const DEFAULT_RETRIES = 0;
    /** @var list<int> */
    public const DEFAULT_RETRY_ON_STATUS = [429, 500, 502, 503, 504];
    public const DEFAULT_RETRY_BACKOFF = 0.5;

    /**
     * Methods with no server-side side effect — safe to retry on ANY status in
     * the retry_on_status set. POST/PATCH are excluded (they may create/mutate),
     * so they retry ONLY on 429/503 (throttles), never 500/502/504, to avoid
     * duplicate side effects. Mirrors Python's _IDEMPOTENT_METHODS.
     *
     * @var list<string>
     */
    private const IDEMPOTENT_METHODS = ['GET', 'PUT', 'DELETE', 'HEAD', 'OPTIONS'];

    /** Max wall-clock seconds per attempt (null = inherit, built-in 30.0). */
    public ?float $timeout;

    /**
     * Number of RETRY attempts (total attempts = retries + 1) on a retryable
     * failure (null = inherit, built-in 0 — opt-in resilience).
     */
    public ?int $retries;

    /**
     * HTTP statuses that trigger a retry for an idempotent method (null =
     * inherit, built-in {429, 500, 502, 503, 504}).
     *
     * @var list<int>|null
     */
    public ?array $retryOnStatus;

    /**
     * Base seconds for exponential backoff between retries
     * (``backoff * 2 ** (attempt - 1)``), honoring ``Retry-After`` when present
     * (null = inherit, built-in 0.5).
     */
    public ?float $retryBackoff;

    /**
     * A cooperative-cancellation primitive checked BEFORE each attempt; when it
     * reports "set" the request raises the transport-error type before the send.
     * PHP idiom: a ``callable(): bool`` (returns true when cancelled) OR any
     * object exposing an ``isSet(): bool`` method. Null = no cancellation.
     *
     * @var (callable(): bool)|object|null
     */
    public $abortSignal;

    /**
     * @param list<int>|null $retryOnStatus
     * @param (callable(): bool)|object|null $abortSignal
     */
    public function __construct(
        ?float $timeout = null,
        ?int $retries = null,
        ?array $retryOnStatus = null,
        ?float $retryBackoff = null,
        $abortSignal = null
    ) {
        $this->timeout = $timeout;
        $this->retries = $retries;
        $this->retryOnStatus = $retryOnStatus;
        $this->retryBackoff = $retryBackoff;
        $this->abortSignal = $abortSignal;
    }

    /**
     * Return a copy of ``$this`` with any set (non-null) field of ``$override``
     * applied — the per-request-over-client-default shallow merge. An unset
     * field on ``$override`` leaves ``$this``'s value intact. Mirrors Python's
     * ``RequestOptions.merge``.
     */
    public function merge(?RequestOptions $override): RequestOptions
    {
        if ($override === null) {
            return $this;
        }
        return new RequestOptions(
            $override->timeout ?? $this->timeout,
            $override->retries ?? $this->retries,
            $override->retryOnStatus ?? $this->retryOnStatus,
            $override->retryBackoff ?? $this->retryBackoff,
            $override->abortSignal ?? $this->abortSignal,
        );
    }

    /**
     * Resolve the effective options: per-request over client-default over the
     * built-in floor. Returns a fully-concrete option set (no null fields) as an
     * associative array {timeout, retries, retryOnStatus, retryBackoff,
     * abortSignal} so the request loop reads concrete values without re-checking
     * defaults on every attempt. Mirrors Python's module-level
     * ``resolve(client_default, per_request) -> _EffectiveOptions``.
     *
     * @return array{timeout: float, retries: int, retryOnStatus: list<int>, retryBackoff: float, abortSignal: (callable(): bool)|object|null}
     */
    public static function resolve(?RequestOptions $clientDefault, ?RequestOptions $perRequest): array
    {
        $merged = ($clientDefault ?? new RequestOptions())->merge($perRequest);
        return [
            'timeout' => $merged->timeout ?? self::DEFAULT_TIMEOUT,
            'retries' => $merged->retries ?? self::DEFAULT_RETRIES,
            'retryOnStatus' => $merged->retryOnStatus ?? self::DEFAULT_RETRY_ON_STATUS,
            'retryBackoff' => $merged->retryBackoff ?? self::DEFAULT_RETRY_BACKOFF,
            'abortSignal' => $merged->abortSignal,
        ];
    }

    /**
     * Whether an HTTP $status for $method should trigger a retry, given the
     * resolved effective options. Idempotent methods (GET/PUT/DELETE/HEAD/
     * OPTIONS) retry on the full retry_on_status set; non-idempotent methods
     * (POST/PATCH) retry only on 429/503 (the Retry-After-bearing throttles that
     * mean "the request was NOT processed"), never 500/502/504 —
     * duplicate-side-effect safety. Mirrors Python's module-level
     * ``status_is_retryable(method, status, opts)``.
     *
     * @param array{timeout: float, retries: int, retryOnStatus: list<int>, retryBackoff: float, abortSignal: (callable(): bool)|object|null} $opts
     */
    public static function statusIsRetryable(string $method, int $status, array $opts): bool
    {
        if (!in_array($status, $opts['retryOnStatus'], true)) {
            return false;
        }
        if (in_array(strtoupper($method), self::IDEMPOTENT_METHODS, true)) {
            return true;
        }
        return $status === 429 || $status === 503;
    }
}
