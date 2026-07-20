<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\Relay\Client as RelayClient;

/**
 * SECRET-SCRUB (enterprise CRIT / PSDK-5, F3.1/F3.2): the RELAY client must
 * never emit live credentials (project/token/jwt_token) or the server's
 * ``authorization_state`` re-auth blob verbatim in its debug logs. Both raw
 * frame log sites (``>>`` send, ``<<`` recv) route their payload through
 * ``Client::scrubFrame`` which masks those key VALUES to ``"***"`` while
 * preserving the rest of the frame for diagnostics.
 *
 * These tests exercise the scrub transform directly (bidirectional: leaked
 * inputs come out masked; already-safe / unrelated content is untouched),
 * mirroring the python reference ``_scrub_frame`` contract. The end-to-end
 * behavioral proof (drive a real connect + re-auth at debug and assert no
 * sentinel appears in captured output) is the cross-port SECRET-SCRUB-LIVE
 * corpus, exercised by ``bin/secret-scrub-dump``.
 */
class SecretScrubTest extends TestCase
{
    /**
     * Invoke the private static ``Client::scrubFrame`` via reflection.
     */
    private static function scrub(string $raw): string
    {
        $m = new \ReflectionMethod(RelayClient::class, 'scrubFrame');
        $m->setAccessible(true);
        /** @var string */
        return $m->invoke(null, $raw);
    }

    #[Test]
    public function masksConnectFrameCredentials(): void
    {
        // The exact outbound signalwire.connect authentication shape.
        $frame = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 'abc',
            'method'  => 'signalwire.connect',
            'params'  => [
                'authentication' => [
                    'project' => 'PJ-TESTLEAK',
                    'token'   => 'PT-TESTLEAK',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $scrubbed = self::scrub($frame);

        // Credential VALUES are gone; the masked marker + structure remain.
        $this->assertStringNotContainsString('PJ-TESTLEAK', $scrubbed);
        $this->assertStringNotContainsString('PT-TESTLEAK', $scrubbed);
        $this->assertStringContainsString('"project":"***"', $scrubbed);
        $this->assertStringContainsString('"token":"***"', $scrubbed);
        // Non-credential structure is preserved (still diagnostic).
        $this->assertStringContainsString('signalwire.connect', $scrubbed);
        $this->assertStringContainsString('"id":"abc"', $scrubbed);
    }

    #[Test]
    public function masksInboundAuthorizationState(): void
    {
        $frame = json_encode([
            'jsonrpc' => '2.0',
            'method'  => 'signalwire.event',
            'params'  => [
                'event_type' => 'signalwire.authorization.state',
                'params'     => ['authorization_state' => 'AENC-TESTLEAK'],
            ],
        ], JSON_THROW_ON_ERROR);

        $scrubbed = self::scrub($frame);

        $this->assertStringNotContainsString('AENC-TESTLEAK', $scrubbed);
        $this->assertStringContainsString('"authorization_state":"***"', $scrubbed);
        // Event routing metadata is preserved.
        $this->assertStringContainsString('signalwire.authorization.state', $scrubbed);
    }

    #[Test]
    public function masksJwtToken(): void
    {
        $frame = '{"params":{"authentication":{"jwt_token":"eyJ-SECRET-JWT"}}}';
        $scrubbed = self::scrub($frame);

        $this->assertStringNotContainsString('eyJ-SECRET-JWT', $scrubbed);
        $this->assertStringContainsString('"jwt_token":"***"', $scrubbed);
    }

    #[Test]
    public function preservesFramesWithoutCredentials(): void
    {
        // A frame with no scrub keys is returned byte-for-byte.
        $frame = '{"jsonrpc":"2.0","method":"signalwire.ping","params":{"state":"answered"}}';
        $this->assertSame($frame, self::scrub($frame));
    }

    #[Test]
    public function masksEmbeddedEscapedQuotesInValue(): void
    {
        // An escaped quote inside the credential value must not terminate the
        // match early (regex value alternation consumes \" ).
        $frame = '{"token":"pt-\"quoted\"-secret","keep":"visible"}';
        $scrubbed = self::scrub($frame);

        $this->assertStringNotContainsString('quoted', $scrubbed);
        $this->assertStringContainsString('"token":"***"', $scrubbed);
        // A non-credential value is untouched.
        $this->assertStringContainsString('"keep":"visible"', $scrubbed);
    }

    #[Test]
    public function leavesNonStringCredentialShapesUntouched(): void
    {
        // A key named like a credential but carrying a non-string (structural)
        // value is not a credential leak shape — left as-is.
        $frame = '{"project":{"nested":1},"token":42}';
        $this->assertSame($frame, self::scrub($frame));
    }
}
