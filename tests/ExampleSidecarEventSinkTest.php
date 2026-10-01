<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\SWML\Service;

/**
 * Pins the behaviour of the ai-sidecar example's `/events` sink.
 *
 * Why: examples/SwmlServiceAiSidecar.php documented
 *
 *     POST /sales-sidecar/events  → Optional sidecar lifecycle/transcription sink
 *
 * and implemented it with `registerRoutingCallback(fn (...) => ['ok' => true])`,
 * declaring `?array $body` and an `array` return.
 *
 * Both halves were wrong. A ROUTING callback is `(array $body, array $headers)
 * -> ?string`: the SDK honours a non-empty STRING (307 redirect to that route)
 * and treats everything else as "declined to redirect", falling through to
 * serving this service's SWML document (Service.php:1161-1181). Returning
 * `['ok' => true]` never acknowledged anything — a sidecar POSTing an event got
 * back the entire SWML document with a 200, which it would read as a valid
 * document rather than an ack.
 *
 * The SDK has no routing-callback return value that produces a custom JSON ack,
 * so the example now logs the event and returns null, and its docblock says so.
 * These tests pin that real behaviour on both routes.
 */
class ExampleSidecarEventSinkTest extends TestCase
{
    private static function loadExample(): Service
    {
        require_once dirname(__DIR__) . '/examples/SwmlServiceAiSidecar.php';
        self::assertTrue(function_exists('buildAiSidecarService'));

        return \buildAiSidecarService();
    }

    /** The service generates a random basic-auth password per process. */
    private static function authHeader(Service $svc): string
    {
        [$user, $pass] = $svc->getBasicAuthCredentials();

        return 'Basic ' . base64_encode("{$user}:{$pass}");
    }

    /**
     * @return array{0: int, 1: array<string,string>, 2: string}
     */
    private static function postEvent(Service $svc, string $json): array
    {
        /** @var array{0: int, 1: array<string,string>, 2: string} $response */
        $response = $svc->handleRequest(
            'POST',
            '/sales-sidecar/events',
            ['Content-Type' => 'application/json', 'Authorization' => self::authHeader($svc)],
            $json,
        );

        return $response;
    }

    public function testEventObserverAcceptsThePostAndDoesNotRedirect(): void
    {
        $svc = self::loadExample();

        [$status, $headers, $body] = self::postEvent(
            $svc,
            (string) json_encode(['type' => 'transcription', 'text' => 'hello']),
        );

        // null from the callback means "handled here": 200, never a 307.
        self::assertSame(200, $status);
        self::assertArrayNotHasKey('Location', $headers);

        // And what it actually serves is the SWML document. Asserting this
        // explicitly is the point: the example previously CLAIMED to return
        // {"ok": true} here, which the SDK has no way to produce.
        self::assertStringContainsString('ai_sidecar', $body);
    }

    public function testEventSinkStillServesTheSwmlDocumentOnTheMainRoute(): void
    {
        $svc = self::loadExample();

        /** @var array{0: int, 1: array<string,string>, 2: string} $response */
        $response = $svc->handleRequest(
            'GET',
            '/sales-sidecar',
            ['Authorization' => self::authHeader($svc)],
            null,
        );
        [$status, , $body] = $response;

        self::assertSame(200, $status);
        // The main route is unaffected by how /events is mounted.
        self::assertStringContainsString('ai_sidecar', $body);
    }
}
