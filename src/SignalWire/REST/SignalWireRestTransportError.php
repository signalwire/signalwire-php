<?php

declare(strict_types=1);

namespace SignalWire\REST;

/**
 * Raised when a REST request never reached a response — a transport-level
 * failure (connection refused, DNS failure, connection reset, TLS error).
 *
 * A member of the {@see SignalWireRestError} family: there is no HTTP response,
 * so there is no status code (``getStatusCode()`` is ``0``, the php idiom for
 * "no HTTP status") and the body is empty; the underlying transport (cURL)
 * error message is carried in the exception message. Because it extends
 * ``SignalWireRestError``, a caller catching that one type handles both an
 * HTTP-error response and a transport failure with a single ``catch`` — instead
 * of a bare cURL/transport error leaking through. Mirrors the Python reference's
 * ``SignalWireRestTransportError(SignalWireRestError)`` (plan 1.3b).
 */
class SignalWireRestTransportError extends SignalWireRestError
{
    /**
     * @param string $message The underlying transport error (cURL) description.
     * @param string $url The request URL that failed to reach a server.
     * @param string $method The HTTP method of the failed request.
     */
    public function __construct(string $message, string $url = '', string $method = '')
    {
        // statusCode 0 == "no HTTP response" (transport failure); empty body.
        parent::__construct($message, 0, '', $url, $method);
    }
}
