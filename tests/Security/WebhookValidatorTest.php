<?php

declare(strict_types=1);

namespace SignalWire\Tests\Security;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SignalWire\Security\WebhookValidator;
use TypeError;

/**
 * Tests for SignalWire\Security\WebhookValidator.
 *
 * Cross-language SDK contract: every port must implement Scheme A (hex
 * HMAC-SHA1 over url + rawBody for JSON/RELAY) and Scheme B (base64
 * HMAC-SHA1 over url + sortedFormParams for cXML/Compat) per
 * porting-sdk/webhooks.md.
 *
 * Vectors A, B, C below are the canonical vectors from the spec; if they
 * break, this port has a real bug — DO NOT relax them.
 */
class WebhookValidatorTest extends TestCase
{
    // -------- Canonical vectors from porting-sdk/webhooks.md --------

    private const VECTOR_A = [
        'signing_key' => 'PSKtest1234567890abcdef',
        'url'         => 'https://example.ngrok.io/webhook',
        'raw_body'    => '{"event":"call.state","params":{"call_id":"abc-123","state":"answered"}}',
        'expected'    => 'c3c08c1fefaf9ee198a100d5906765a6f394bf0f',
    ];

    private const VECTOR_B_PARAMS = [
        'CallSid' => 'CA1234567890ABCDE',
        'Caller'  => '+14158675309',
        'Digits'  => '1234',
        'From'    => '+14158675309',
        'To'      => '+18005551212',
    ];

    private const VECTOR_B = [
        'signing_key' => '12345',
        'url'         => 'https://mycompany.com/myapp.php?foo=1&bar=2',
        'expected'    => 'RSOYDt4T1cUTdK1PDd93/VVr8B8=',
    ];

    private const VECTOR_C = [
        'signing_key' => 'PSKtest1234567890abcdef',
        'raw_body'    => '{"event":"call.state"}',
        'url'         => 'https://example.ngrok.io/webhook?bodySHA256='
            . '69f3cbfc18e386ef8236cb7008cd5a54b7fed637a8cb3373b5a1591d7f0fd5f4',
        'expected'    => 'dfO9ek8mxyFtn2nMz24plPmPfIY=',
    ];

    /**
     * Build an x-www-form-urlencoded body that round-trips through the
     * validator's parser back to the same key/value pairs. Hand-encoded so
     * the test stays close to what HTTP middleware actually sees on the
     * wire ("+" -> "%2B").
     *
     * @param array<string,scalar|array<int,scalar>> $params
     */
    private static function formEncoded(array $params): string
    {
        $pairs = [];
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $vi) {
                    $pairs[] = urlencode((string) $k) . '=' . urlencode((string) $vi);
                }
            } else {
                $pairs[] = urlencode((string) $k) . '=' . urlencode((string) $v);
            }
        }
        return implode('&', $pairs);
    }

    private static function b64Sig(string $key, string $url, array $params = []): string
    {
        $concat = $url;
        $keys = array_keys($params);
        sort($keys);
        foreach ($keys as $k) {
            $concat .= $k . $params[$k];
        }
        return base64_encode(hash_hmac('sha1', $concat, $key, true));
    }

    // ==================================================================
    //  Scheme A — RELAY / JSON (hex)
    // ==================================================================

    public function testSchemeAPositiveCanonicalVector(): void
    {
        $this->assertTrue(
            WebhookValidator::validateWebhookSignature(
                self::VECTOR_A['signing_key'],
                self::VECTOR_A['expected'],
                self::VECTOR_A['url'],
                self::VECTOR_A['raw_body'],
            ),
            'Vector A: known JSON body + URL + key must produce the canonical hex digest.'
        );
    }

    public function testSchemeAExactDigestMatchesSpec(): void
    {
        // Read the source: the validator must produce exactly the canonical
        // hex digest from porting-sdk/webhooks.md for Vector A. Belt-and-
        // braces against any future refactor that mangles encoding.
        $expected = bin2hex(hash_hmac(
            'sha1',
            self::VECTOR_A['url'] . self::VECTOR_A['raw_body'],
            self::VECTOR_A['signing_key'],
            true,
        ));
        $this->assertSame(
            self::VECTOR_A['expected'],
            $expected,
            'Re-derived digest must equal canonical Vector A.'
        );
    }

    public function testSchemeANegativeTamperedBody(): void
    {
        $tampered = str_replace('answered', 'ringing', self::VECTOR_A['raw_body']);
        $this->assertFalse(
            WebhookValidator::validateWebhookSignature(
                self::VECTOR_A['signing_key'],
                self::VECTOR_A['expected'],
                self::VECTOR_A['url'],
                $tampered,
            )
        );
    }

    public function testSchemeANegativeWrongKey(): void
    {
        $this->assertFalse(
            WebhookValidator::validateWebhookSignature(
                'wrong-key',
                self::VECTOR_A['expected'],
                self::VECTOR_A['url'],
                self::VECTOR_A['raw_body'],
            )
        );
    }

    public function testSchemeANegativeWrongUrl(): void
    {
        $this->assertFalse(
            WebhookValidator::validateWebhookSignature(
                self::VECTOR_A['signing_key'],
                self::VECTOR_A['expected'],
                'https://example.ngrok.io/different',
                self::VECTOR_A['raw_body'],
            )
        );
    }

    // ==================================================================
    //  Scheme B — Compat / cXML (base64 form)
    // ==================================================================

    public function testSchemeBPositiveCanonicalFormVector(): void
    {
        $body = self::formEncoded(self::VECTOR_B_PARAMS);
        $this->assertTrue(
            WebhookValidator::validateWebhookSignature(
                self::VECTOR_B['signing_key'],
                self::VECTOR_B['expected'],
                self::VECTOR_B['url'],
                $body,
            ),
            'Vector B: form params via raw body must match canonical Twilio digest.'
        );
    }

    public function testSchemeBValidateRequestWithArrayDispatchesB(): void
    {
        $this->assertTrue(
            WebhookValidator::validateRequest(
                self::VECTOR_B['signing_key'],
                self::VECTOR_B['expected'],
                self::VECTOR_B['url'],
                self::VECTOR_B_PARAMS,
            )
        );
    }

    public function testSchemeBValidateRequestWithListOfPairs(): void
    {
        $pairs = [];
        foreach (self::VECTOR_B_PARAMS as $k => $v) {
            $pairs[] = [$k, $v];
        }
        $this->assertTrue(
            WebhookValidator::validateRequest(
                self::VECTOR_B['signing_key'],
                self::VECTOR_B['expected'],
                self::VECTOR_B['url'],
                $pairs,
            )
        );
    }

    public function testSchemeBBodySha256CanonicalVector(): void
    {
        $this->assertTrue(
            WebhookValidator::validateWebhookSignature(
                self::VECTOR_C['signing_key'],
                self::VECTOR_C['expected'],
                self::VECTOR_C['url'],
                self::VECTOR_C['raw_body'],
            ),
            'Vector C: JSON-on-compat with bodySHA256 query param must validate.'
        );
    }

    public function testSchemeBBodySha256MismatchRejected(): void
    {
        // HMAC over URL+'' would still match, but bodySHA256 digest of a
        // different body won't match the URL's bodySHA256 — must reject.
        $wrongBody = '{"event":"DIFFERENT"}';
        $this->assertFalse(
            WebhookValidator::validateWebhookSignature(
                self::VECTOR_C['signing_key'],
                self::VECTOR_C['expected'],
                self::VECTOR_C['url'],
                $wrongBody,
            )
        );
    }

    // ==================================================================
    //  URL port normalisation
    // ==================================================================

    public function testPortNormalisationSignedWithPortRequestWithoutPort(): void
    {
        $key = 'test-key';
        $sig = self::b64Sig($key, 'https://example.com:443/webhook');
        $this->assertTrue(
            WebhookValidator::validateWebhookSignature(
                $key,
                $sig,
                'https://example.com/webhook',
                '{}',
            )
        );
    }

    public function testPortNormalisationSignedWithoutPortRequestWithPort(): void
    {
        $key = 'test-key';
        $sig = self::b64Sig($key, 'https://example.com/webhook');
        $this->assertTrue(
            WebhookValidator::validateWebhookSignature(
                $key,
                $sig,
                'https://example.com:443/webhook',
                '{}',
            )
        );
    }

    public function testPortNormalisationHttpPort80(): void
    {
        $key = 'test-key';
        $sig = self::b64Sig($key, 'http://example.com:80/path');
        $this->assertTrue(
            WebhookValidator::validateWebhookSignature(
                $key,
                $sig,
                'http://example.com/path',
                '',
            )
        );
    }

    public function testNonStandardPortIsNotNormalised(): void
    {
        // :8080 is non-standard — validator should NOT try without-port variant.
        $key = 'test-key';
        $sig = self::b64Sig($key, 'http://example.com/path'); // sig for unported URL
        $this->assertFalse(
            WebhookValidator::validateWebhookSignature(
                $key,
                $sig,
                'http://example.com:8080/path',
                '',
            )
        );
    }

    // ==================================================================
    //  Repeated form keys
    // ==================================================================

    public function testRepeatedKeysConcatInSubmissionOrder(): void
    {
        $key = 'test-key';
        $url = 'https://example.com/hook';
        $body = 'To=a&To=b';
        // Expected concat: ToaTob — sorted by key only, original order kept.
        $expectedData = $url . 'ToaTob';
        $sig = base64_encode(hash_hmac('sha1', $expectedData, $key, true));
        $this->assertTrue(
            WebhookValidator::validateWebhookSignature($key, $sig, $url, $body)
        );
    }

    public function testRepeatedKeysSwappedOrderIsDifferentSignature(): void
    {
        $key = 'test-key';
        $url = 'https://example.com/hook';
        $bodyAB = 'To=a&To=b';
        $bodyBA = 'To=b&To=a';
        $sigForAB = base64_encode(hash_hmac('sha1', $url . 'ToaTob', $key, true));
        $this->assertTrue(
            WebhookValidator::validateWebhookSignature($key, $sigForAB, $url, $bodyAB)
        );
        $this->assertFalse(
            WebhookValidator::validateWebhookSignature($key, $sigForAB, $url, $bodyBA)
        );
    }

    // ==================================================================
    //  Error modes
    // ==================================================================

    public function testMissingSignatureReturnsFalse(): void
    {
        $this->assertFalse(
            WebhookValidator::validateWebhookSignature(
                self::VECTOR_A['signing_key'],
                '',
                self::VECTOR_A['url'],
                self::VECTOR_A['raw_body'],
            )
        );
    }

    public function testMissingSigningKeyRaisesInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WebhookValidator::validateWebhookSignature(
            '',
            'sig',
            self::VECTOR_A['url'],
            self::VECTOR_A['raw_body'],
        );
    }

    public function testNonStringRawBodyRaisesTypeError(): void
    {
        $this->expectException(TypeError::class);
        // PHP type-hint enforces this — a parsed array can't be passed.
        // @phpstan-ignore-next-line intentional bad call for type-error test.
        WebhookValidator::validateWebhookSignature(
            self::VECTOR_A['signing_key'],
            'sig',
            self::VECTOR_A['url'],
            ['event' => 'call.state'],
        );
    }

    public function testMalformedSignatureReturnsFalseWithoutThrowing(): void
    {
        foreach (['xyz', '!!!!', str_repeat('a', 100), '%%notbase64%%'] as $garbage) {
            $this->assertFalse(
                WebhookValidator::validateWebhookSignature(
                    self::VECTOR_A['signing_key'],
                    $garbage,
                    self::VECTOR_A['url'],
                    self::VECTOR_A['raw_body'],
                ),
                "Garbage signature '{$garbage}' must not validate"
            );
        }
    }

    // ==================================================================
    //  validateRequest legacy alias dispatch
    // ==================================================================

    public function testValidateRequestStringArgDelegatesToCombined(): void
    {
        $this->assertTrue(
            WebhookValidator::validateRequest(
                self::VECTOR_A['signing_key'],
                self::VECTOR_A['expected'],
                self::VECTOR_A['url'],
                self::VECTOR_A['raw_body'],
            )
        );
    }

    public function testValidateRequestArrayArgRunsSchemeBDirectly(): void
    {
        $this->assertTrue(
            WebhookValidator::validateRequest(
                self::VECTOR_B['signing_key'],
                self::VECTOR_B['expected'],
                self::VECTOR_B['url'],
                self::VECTOR_B_PARAMS,
            )
        );
    }

    public function testValidateRequestInvalidArgTypeRaises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WebhookValidator::validateRequest(
            self::VECTOR_A['signing_key'],
            'sig',
            self::VECTOR_A['url'],
            42,
        );
    }

    public function testValidateRequestMissingSigningKeyRaises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WebhookValidator::validateRequest(
            '',
            'sig',
            self::VECTOR_A['url'],
            self::VECTOR_A['raw_body'],
        );
    }

    public function testValidateRequestEmptySignatureReturnsFalse(): void
    {
        $this->assertFalse(
            WebhookValidator::validateRequest(
                self::VECTOR_A['signing_key'],
                '',
                self::VECTOR_A['url'],
                self::VECTOR_A['raw_body'],
            )
        );
    }

    // ==================================================================
    //  Constant-time compare — read the source
    // ==================================================================

    public function testValidatorSourceUsesHashEquals(): void
    {
        $src = file_get_contents(
            __DIR__ . '/../../src/SignalWire/Security/WebhookValidator.php'
        );
        $this->assertNotFalse($src);
        $this->assertStringContainsString(
            'hash_equals',
            $src,
            'WebhookValidator must use hash_equals for signature compare.'
        );
        // Must NOT use plain === on full digests.
        $this->assertStringNotContainsString(
            '$expectedA === $signature',
            $src,
            'plain === on full digest leaks timing info — use hash_equals.'
        );
        $this->assertStringNotContainsString(
            '$expectedB === $signature',
            $src,
        );
    }

    public function testValidatorDoesNotLogSecrets(): void
    {
        // Light defensive check — no echo / var_dump / error_log calls in
        // the validator. Uses regex over the source.
        $src = (string) file_get_contents(
            __DIR__ . '/../../src/SignalWire/Security/WebhookValidator.php'
        );
        foreach (['echo ', 'var_dump', 'print_r', 'error_log', 'syslog'] as $bad) {
            $this->assertStringNotContainsString(
                $bad,
                $src,
                "WebhookValidator must not call {$bad}() — would leak secrets."
            );
        }
    }
}
