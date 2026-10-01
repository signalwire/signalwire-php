<?php

declare(strict_types=1);

/*
 * Copyright (c) 2026 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

namespace SignalWire\AIChat;

/**
 * Request/response plumbing shared by the routes {@see ChatGateway::router()}
 * and {@see HandoffRouter::router()} return.
 *
 * Not part of the public surface.
 *
 * @internal
 */
final class GatewayHttp
{
    private function __construct()
    {
    }

    /**
     * A request header's value, matched case-insensitively, or null if absent.
     *
     * @param array<string, string> $headers
     */
    public static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    /**
     * The route path with any query string and trailing slash removed, so the
     * mount prefix itself (`''`) and `'/'` both name the root route.
     */
    public static function routePath(string $path): string
    {
        $q = strpos($path, '?');
        if ($q !== false) {
            $path = substr($path, 0, $q);
        }
        return rtrim($path, '/');
    }

    /**
     * Parse a request's JSON body, refusing one over `$limit` bytes.
     *
     * A declared `Content-Length` over the limit is refused, and so is a body
     * that is over it, before either is parsed.
     *
     * @param array<string, string> $headers
     *
     * @throws GatewayRejection 413 if the body is over `$limit`.
     * @throws \JsonException   If the body isn't valid JSON.
     */
    public static function readJsonBody(?string $body, array $headers, int $limit = ChatGateway::MAX_REQUEST_BODY_BYTES): mixed
    {
        $declared = self::header($headers, 'Content-Length') ?? '';
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $limit) {
            throw new GatewayRejection(413, 'request too large');
        }
        $body ??= '';
        if (strlen($body) > $limit) {
            throw new GatewayRejection(413, 'request too large');
        }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * True when `$body` is a JSON object rather than any other JSON value.
     */
    public static function isJsonObject(string $body): bool
    {
        return str_starts_with(ltrim($body, " \t\n\r"), '{');
    }

    /**
     * A JSON response in the mount contract's `[status, headers, body]` shape.
     *
     * @param array<string, string> $headers
     *
     * @return array{int, array<string, string>, string}
     */
    public static function json(int $status, mixed $payload, array $headers = []): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return [$status, $headers + ['Content-Type' => 'application/json'], $encoded === false ? '{}' : $encoded];
    }

    /**
     * Normalize a decoded JSON object to a string-keyed map.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    public static function stringKeyed(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }
}
