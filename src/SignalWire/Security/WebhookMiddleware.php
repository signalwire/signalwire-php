<?php

declare(strict_types=1);

namespace SignalWire\Security;

use SignalWire\Logging\Logger;

/**
 * Middleware that validates SignalWire webhook signatures before forwarding
 * the request to the next handler.
 *
 * The signalwire-php SDK does not use PSR-15 — it has its own internal
 * (method, path, headers, body) -> [status, headers, body] handler shape.
 * This middleware mirrors that shape and is the canonical way to add
 * signature validation to any handleRequest pipeline.
 *
 * Usage:
 *
 *     $mw = new WebhookMiddleware($signingKey);
 *     [$status, $headers, $body] = $mw->process(
 *         $method, $url, $headers, $rawBody,
 *         function ($method, $url, $headers, $rawBody) use ($svc) {
 *             return $svc->handleRequest($method, parse_url($url, PHP_URL_PATH), $headers, $rawBody);
 *         },
 *     );
 *
 * Behaviour:
 *   - Reads X-SignalWire-Signature (or X-Twilio-Signature alias).
 *   - On valid signature: forwards to $next, returns its result unchanged.
 *   - On invalid signature: returns 403 Forbidden, never calls $next.
 *   - On missing header: returns 403 Forbidden, never calls $next.
 *   - Never logs / echoes the signing key, signature, or expected digest.
 */
final class WebhookMiddleware
{
    private string $signingKey;
    private Logger $logger;

    public function __construct(string $signingKey)
    {
        if ($signingKey === '') {
            throw new \InvalidArgumentException('signingKey is required');
        }
        $this->signingKey = $signingKey;
        $this->logger = Logger::getLogger('webhook_middleware');
    }

    /**
     * Run the middleware. On valid signature delegates to $next; on
     * invalid / missing signature returns 403 directly.
     *
     * @param string                 $method   HTTP method.
     * @param string                 $url      Full reconstructed URL the platform POSTed to
     *                                         (scheme + host + optional port + path + query).
     *                                         Caller is responsible for honouring proxy headers
     *                                         / SWML_PROXY_URL_BASE before passing it in.
     * @param array<string,string>   $headers  Request headers (mixed case keys allowed).
     * @param string                 $rawBody  Raw request body bytes.
     * @param callable               $next     Downstream handler. Signature:
     *                                         function(string $method, string $url,
     *                                                  array $headers, string $rawBody): array
     *
     * @return array{int, array<string,string>, string} [status, headers, body].
     */
    public function process(
        string $method,
        string $url,
        array $headers,
        string $rawBody,
        callable $next,
    ): array {
        $signature = $this->extractSignature($headers);
        if ($signature === null || $signature === '') {
            $this->logger->warn('webhook signature missing — returning 403');
            return $this->forbidden();
        }

        $valid = WebhookValidator::validateWebhookSignature(
            $this->signingKey,
            $signature,
            $url,
            $rawBody,
        );

        if (!$valid) {
            $this->logger->warn('webhook signature invalid — returning 403');
            return $this->forbidden();
        }

        return $next($method, $url, $headers, $rawBody);
    }

    /**
     * Extract the signature header value, preferring X-SignalWire-Signature
     * over the legacy X-Twilio-Signature alias (cXML compat).
     */
    private function extractSignature(array $headers): ?string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-SignalWire-Signature') === 0) {
                return is_string($v) ? $v : null;
            }
        }
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-Twilio-Signature') === 0) {
                return is_string($v) ? $v : null;
            }
        }
        return null;
    }

    /**
     * Fixed 403 response with no detail leaked back to the caller.
     *
     * @return array{int, array<string,string>, string}
     */
    private function forbidden(): array
    {
        return [
            403,
            ['Content-Type' => 'text/plain'],
            'Forbidden',
        ];
    }
}
