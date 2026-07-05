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
        $signature = self::extractSignatureFrom($headers);
        if ($signature === null || $signature === '') {
            $this->logger->warn('webhook signature missing — returning 403');
            return self::forbiddenTriple();
        }

        $valid = WebhookValidator::validateWebhookSignature(
            $this->signingKey,
            $signature,
            $url,
            $rawBody,
        );

        if (!$valid) {
            $this->logger->warn('webhook signature invalid — returning 403');
            return self::forbiddenTriple();
        }

        return $next($method, $url, $headers, $rawBody);
    }

    /**
     * Framework-free decomposed validation core.
     *
     * This is the language-neutral shape the porting-sdk oracle requires
     * (mirrors dotnet's `WebhookValidationMiddleware.Validate` and the
     * Rack/PSGI `.call` decision core): given the raw request primitives it
     * returns `null` to signal "pass — call your downstream handler", or a
     * `[status, headers, body]` triple to signal "reject; send this response
     * and DO NOT call the handler". No framework object is produced or
     * consumed — the object-shaped `process()` wrapper above is the
     * PHP-idiom convenience layered on top of this.
     *
     * Behaviour matches `process()`:
     *   - Missing X-SignalWire-Signature / X-Twilio-Signature header -> 403 triple.
     *   - Invalid signature -> 403 triple.
     *   - Valid signature -> null (the caller proceeds to its handler).
     *   - Never logs / echoes the signing key, signature, or expected digest.
     *
     * @param string               $method     HTTP method (unused by the signature
     *                                          check; carried for shape parity so a
     *                                          caller can pass the full request tuple).
     * @param string               $url        Full reconstructed URL the platform POSTed to.
     * @param array<string,string> $headers    Request headers (mixed-case keys allowed).
     * @param string               $body       Raw request body bytes.
     * @param string               $signingKey Customer Signing Key. Empty raises
     *                                          InvalidArgumentException (programming error).
     *
     * @return array{int, array<string,string>, string}|null Null on pass; a
     *         [status, headers, body] triple on reject.
     *
     * @throws \InvalidArgumentException When $signingKey is empty.
     */
    public static function validate(
        string $method,
        string $url,
        array $headers,
        string $body,
        string $signingKey,
    ): ?array {
        if ($signingKey === '') {
            throw new \InvalidArgumentException('signingKey is required');
        }

        $signature = self::extractSignatureFrom($headers);
        if ($signature === null || $signature === '') {
            return self::forbiddenTriple();
        }

        $valid = WebhookValidator::validateWebhookSignature(
            $signingKey,
            $signature,
            $url,
            $body,
        );

        if (!$valid) {
            return self::forbiddenTriple();
        }

        return null;
    }

    /**
     * Header lookup shared by the instance `process()` and the static
     * decomposed `validate()`. Prefers X-SignalWire-Signature over the legacy
     * X-Twilio-Signature alias (cXML compat).
     *
     * @param array<string,mixed> $headers
     */
    private static function extractSignatureFrom(array $headers): ?string
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
     * Fixed 403 triple with no detail leaked. Shared by process()/validate().
     *
     * @return array{int, array<string,string>, string}
     */
    private static function forbiddenTriple(): array
    {
        return [
            403,
            ['Content-Type' => 'text/plain'],
            'Forbidden',
        ];
    }

}
