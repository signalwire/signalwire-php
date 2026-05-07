<?php

declare(strict_types=1);

namespace SignalWire\Security;

use InvalidArgumentException;

/**
 * Webhook signature validation for SignalWire-signed HTTP requests.
 *
 * Implements both schemes from porting-sdk/webhooks.md:
 *
 *  - Scheme A (RELAY/SWML/JSON): hex(HMAC-SHA1(key, url + rawBody))
 *  - Scheme B (Compat/cXML form): base64(HMAC-SHA1(key, url + sortedFormParams))
 *    with optional bodySHA256 query-param fallback for JSON-on-compat-surface.
 *
 * Public API:
 *
 *     WebhookValidator::validateWebhookSignature($key, $sig, $url, $rawBody): bool
 *     WebhookValidator::validateRequest($key, $sig, $url, $paramsOrRawBody): bool
 *
 * All comparisons use hash_equals() (constant-time) so the secret is not
 * leaked across repeated requests.
 */
final class WebhookValidator
{
    /**
     * Validate a SignalWire webhook signature against both schemes.
     *
     * @param string $signingKey  Customer's Signing Key from the Dashboard. UTF-8 string,
     *                            secret. Empty string raises InvalidArgumentException —
     *                            that is a programming error, not a validation failure.
     * @param string $signature   The X-SignalWire-Signature header value (or
     *                            X-Twilio-Signature for cXML compat). Empty / missing
     *                            returns false without raising.
     * @param string $url         The full URL SignalWire POSTed to (scheme, host,
     *                            optional port, path, query). Must match what the
     *                            platform saw — see the URL-reconstruction section
     *                            of porting-sdk/webhooks.md.
     * @param string $rawBody     The raw request body bytes as a UTF-8 string, BEFORE
     *                            any JSON / form parsing. Type-hinted string so a
     *                            parsed array passed by mistake yields a TypeError.
     *
     * @return bool True if the signature matches either Scheme A (hex JSON) or
     *              Scheme B (base64 form, with port-normalisation variants and
     *              optional bodySHA256 fallback). False otherwise.
     *
     * @throws InvalidArgumentException When $signingKey is missing.
     */
    public static function validateWebhookSignature(
        string $signingKey,
        string $signature,
        string $url,
        string $rawBody,
    ): bool {
        if ($signingKey === '') {
            throw new InvalidArgumentException('signingKey is required');
        }
        if ($signature === '') {
            return false;
        }

        // ------------------------------------------------------------------
        // Scheme A — RELAY/SWML/JSON: hex(HMAC-SHA1(key, url + rawBody))
        // ------------------------------------------------------------------
        $expectedA = self::hexHmacSha1($signingKey, $url . $rawBody);
        if (self::safeEq($expectedA, $signature)) {
            return true;
        }

        // ------------------------------------------------------------------
        // Scheme B — Compat/cXML form
        //   base64(HMAC-SHA1(key, url + sortedConcatParams))
        // Try with parsed form params; fall back to empty params for
        // JSON-on-compat surfaces (where the body hash lives in bodySHA256).
        // Try both with-port and without-port URL variants.
        // ------------------------------------------------------------------
        $parsedParams = self::parseFormBody($rawBody);
        $paramShapes = [$parsedParams, []];

        foreach (self::candidateUrls($url) as $candidateUrl) {
            foreach ($paramShapes as $shape) {
                $concat = self::sortedConcatParams($shape);
                $expectedB = self::b64HmacSha1($signingKey, $candidateUrl . $concat);
                if (self::safeEq($expectedB, $signature)) {
                    if (self::checkBodySha256($candidateUrl, $rawBody)) {
                        return true;
                    }
                    // bodySHA256 mismatched — keep trying other shapes/URLs.
                }
            }
        }

        return false;
    }

    /**
     * Legacy @signalwire/compatibility-api drop-in entry point.
     *
     * If $paramsOrRawBody is a string, delegates to validateWebhookSignature
     * (Scheme A then Scheme B with parsed form). If it is an array, treats
     * it as pre-parsed form params and runs Scheme B directly (with URL
     * port normalisation).
     *
     * @param string             $signingKey       Customer's Signing Key.
     * @param string             $signature        Header value. Empty returns false.
     * @param string             $url              Full URL SignalWire POSTed to.
     * @param string|array<int|string,mixed> $paramsOrRawBody  Raw body string OR
     *                            pre-parsed form params (associative or list-of-
     *                            [key, value] pairs).
     *
     * @return bool True on match, false otherwise.
     *
     * @throws InvalidArgumentException When $signingKey is missing or the
     *                                  4th argument has an invalid type.
     */
    public static function validateRequest(
        string $signingKey,
        string $signature,
        string $url,
        mixed $paramsOrRawBody,
    ): bool {
        if ($signingKey === '') {
            throw new InvalidArgumentException('signingKey is required');
        }
        if ($signature === '') {
            return false;
        }

        if (is_string($paramsOrRawBody)) {
            return self::validateWebhookSignature($signingKey, $signature, $url, $paramsOrRawBody);
        }

        if ($paramsOrRawBody === null) {
            $paramsOrRawBody = [];
        }

        if (!is_array($paramsOrRawBody)) {
            throw new InvalidArgumentException(
                'paramsOrRawBody must be a string (raw body) or an array of form params'
            );
        }

        // Pre-parsed form params → Scheme B only.
        $concat = self::sortedConcatParams($paramsOrRawBody);
        foreach (self::candidateUrls($url) as $candidateUrl) {
            $expectedB = self::b64HmacSha1($signingKey, $candidateUrl . $concat);
            if (self::safeEq($expectedB, $signature)) {
                // No raw body to verify bodySHA256 against — skip that check.
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Scheme-A digest: lowercase hex of HMAC-SHA1.
     */
    private static function hexHmacSha1(string $key, string $message): string
    {
        return hash_hmac('sha1', $message, $key, false);
    }

    /**
     * Scheme-B digest: standard base64 of HMAC-SHA1.
     */
    private static function b64HmacSha1(string $key, string $message): string
    {
        return base64_encode(hash_hmac('sha1', $message, $key, true));
    }

    /**
     * Constant-time string compare. Returns false on any error so malformed
     * inputs never throw out of the validator.
     */
    private static function safeEq(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /**
     * Concatenate form params per Scheme B rules.
     *
     *  - Sort by key, ASCII ascending.
     *  - For repeated keys: keep original submission order, emit key+value
     *    once per occurrence.
     *  - Non-string values are stringified (matches the JS reference's
     *    Buffer.from(... + value) coercion).
     *
     * Accepts:
     *  - associative array  ['k' => 'v', ...]
     *  - list of pairs      [['k', 'v'], ...]
     *
     * @param array<int|string,mixed> $params
     */
    private static function sortedConcatParams(array $params): string
    {
        if (count($params) === 0) {
            return '';
        }

        // Normalise to a list of [key, value] tuples preserving original order.
        $items = [];
        $isListOfPairs = array_is_list($params)
            && count($params) > 0
            && is_array($params[0])
            && array_is_list($params[0])
            && count($params[0]) === 2;

        if ($isListOfPairs) {
            foreach ($params as $pair) {
                /** @var array{0: int|string, 1: mixed} $pair */
                $items[] = [(string) $pair[0], $pair[1]];
            }
        } else {
            foreach ($params as $k => $v) {
                if (is_array($v)) {
                    foreach ($v as $vi) {
                        $items[] = [(string) $k, $vi];
                    }
                } else {
                    $items[] = [(string) $k, $v];
                }
            }
        }

        // Stable sort by key — preserves original order within repeated keys.
        // PHP's usort is not stable; emulate stability via a decorated sort.
        $decorated = [];
        foreach ($items as $idx => $kv) {
            $decorated[] = [$kv[0], $idx, $kv[1]];
        }
        usort($decorated, static function (array $a, array $b): int {
            $cmp = strcmp($a[0], $b[0]);
            if ($cmp !== 0) {
                return $cmp;
            }
            return $a[1] <=> $b[1];
        });

        $out = '';
        foreach ($decorated as [$k, , $v]) {
            $out .= $k . self::stringify($v);
        }
        return $out;
    }

    /**
     * Stringify a value for the signing string. null becomes ''.
     */
    private static function stringify(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '';
        }
        if (is_scalar($v)) {
            return (string) $v;
        }
        if (is_object($v) && method_exists($v, '__toString')) {
            return (string) $v;
        }
        // Fall-back: best-effort coercion. This branch is unreachable from
        // legitimate form input, but the validator must not throw on weird
        // payloads.
        return '';
    }

    /**
     * Parse an x-www-form-urlencoded body into a list of [key, value] pairs,
     * preserving the on-wire submission order so repeated keys stay ordered.
     *
     * Returns an empty list if the body is empty or doesn't decode as
     * form data; the caller will then sign against url + ''.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function parseFormBody(string $rawBody): array
    {
        if ($rawBody === '') {
            return [];
        }

        $items = [];
        foreach (explode('&', $rawBody) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            if ($eq === false) {
                $key = self::urlDecode($pair);
                $val = '';
            } else {
                $key = self::urlDecode(substr($pair, 0, $eq));
                $val = self::urlDecode(substr($pair, $eq + 1));
            }
            $items[] = [$key, $val];
        }
        return $items;
    }

    /**
     * URL-decode a single token. Treats '+' as a space (form-encoding),
     * which matches what HTTP clients put on the wire for the cXML/Compat
     * scheme.
     */
    private static function urlDecode(string $s): string
    {
        return urldecode($s);
    }

    /**
     * Split URL into [scheme, host (no port), port-or-empty, path, query, fragment, queryParams].
     *
     * @return array{scheme: string, host: string, port: string, path: string, query: string, fragment: string, qparams: array<string,string>}
     */
    private static function splitUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return [
                'scheme' => '', 'host' => '', 'port' => '',
                'path' => '', 'query' => '', 'fragment' => '',
                'qparams' => [],
            ];
        }
        $qparams = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $qparams);
            // parse_str returns mixed; the validator only reads string scalars.
            foreach ($qparams as $k => $v) {
                if (!is_string($v)) {
                    unset($qparams[$k]);
                }
            }
        }
        return [
            'scheme'   => isset($parts['scheme']) ? (string) $parts['scheme'] : '',
            'host'     => isset($parts['host']) ? (string) $parts['host'] : '',
            'port'     => isset($parts['port']) ? (string) $parts['port'] : '',
            'path'     => isset($parts['path']) ? (string) $parts['path'] : '',
            'query'    => isset($parts['query']) ? (string) $parts['query'] : '',
            'fragment' => isset($parts['fragment']) ? (string) $parts['fragment'] : '',
            'qparams'  => $qparams,
        ];
    }

    /**
     * Reassemble a URL with explicit (or empty) port. IPv6 hosts are wrapped in [ ].
     */
    private static function buildUrl(
        string $scheme,
        string $host,
        string $port,
        string $path,
        string $query,
        string $fragment,
    ): string {
        if ($host === '') {
            return '';
        }
        $netlocHost = (str_contains($host, ':') && $host[0] !== '[')
            ? "[{$host}]"
            : $host;
        $netloc = $port !== '' ? "{$netlocHost}:{$port}" : $netlocHost;

        $url = '';
        if ($scheme !== '') {
            $url .= $scheme . '://';
        }
        $url .= $netloc;
        $url .= $path;
        if ($query !== '') {
            $url .= '?' . $query;
        }
        if ($fragment !== '') {
            $url .= '#' . $fragment;
        }
        return $url;
    }

    /**
     * Return the URL variants to try for Scheme B port normalisation.
     *
     *  - If the URL already has a non-standard port: just the input URL.
     *  - If https + no port: input URL AND url with ":443".
     *  - If http  + no port: input URL AND url with ":80".
     *  - If https + ":443" / http + ":80": input URL AND url with port stripped.
     *  - Otherwise: just the input URL.
     *
     * @return list<string>
     */
    private static function candidateUrls(string $url): array
    {
        $p = self::splitUrl($url);
        if ($p['host'] === '') {
            return [$url];
        }

        $standard = ['http' => '80', 'https' => '443'][strtolower($p['scheme'])] ?? null;
        $candidates = [$url];

        if ($p['port'] === '' && $standard !== null) {
            $withPort = self::buildUrl($p['scheme'], $p['host'], $standard, $p['path'], $p['query'], $p['fragment']);
            if ($withPort !== '' && $withPort !== $url) {
                $candidates[] = $withPort;
            }
        } elseif ($p['port'] !== '' && $standard === $p['port']) {
            $withoutPort = self::buildUrl($p['scheme'], $p['host'], '', $p['path'], $p['query'], $p['fragment']);
            if ($withoutPort !== '' && $withoutPort !== $url) {
                $candidates[] = $withoutPort;
            }
        }
        return $candidates;
    }

    /**
     * If URL has ?bodySHA256=<hex>, verify sha256_hex(rawBody) matches.
     *
     * Returns true if the param is absent (no constraint), or present and
     * matches. Returns false only when the param is present and mismatches.
     */
    private static function checkBodySha256(string $url, string $rawBody): bool
    {
        $p = self::splitUrl($url);
        $expected = $p['qparams']['bodySHA256'] ?? null;
        if ($expected === null) {
            return true;
        }
        $actual = hash('sha256', $rawBody);
        return self::safeEq($actual, (string) $expected);
    }
}
