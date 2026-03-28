<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Security\SessionManager;

class SessionManagerTest extends TestCase
{
    private SessionManager $manager;

    protected function setUp(): void
    {
        $this->manager = new SessionManager();
    }

    // ---------------------------------------------------------------
    // 1. Construction — default expiry 3600
    // ---------------------------------------------------------------

    public function testConstructorSetsDefaultExpiry(): void
    {
        $manager = new SessionManager();
        $this->assertSame(3600, $manager->getTokenExpirySecs());
    }

    public function testConstructorAcceptsCustomExpiry(): void
    {
        $manager = new SessionManager(600);
        $this->assertSame(600, $manager->getTokenExpirySecs());
    }

    // ---------------------------------------------------------------
    // 2. createSession — auto-generates UUID when null, returns provided callId
    // ---------------------------------------------------------------

    public function testCreateSessionReturnsProvidedCallId(): void
    {
        $callId = 'my-existing-call-id';
        $this->assertSame($callId, $this->manager->createSession($callId));
    }

    public function testCreateSessionGeneratesUuidWhenNull(): void
    {
        $callId = $this->manager->createSession(null);
        // UUID v4 format: 8-4-4-4-12 hex characters
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $callId
        );
    }

    public function testCreateSessionGeneratesUuidWhenCalledWithoutArgs(): void
    {
        $callId = $this->manager->createSession();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $callId
        );
    }

    public function testCreateSessionGeneratesUniqueIds(): void
    {
        $a = $this->manager->createSession();
        $b = $this->manager->createSession();
        $this->assertNotSame($a, $b);
    }

    // ---------------------------------------------------------------
    // 3. Token round-trip — generateToken + validateToken returns true
    // ---------------------------------------------------------------

    public function testTokenRoundTrip(): void
    {
        $callId = $this->manager->createSession();
        $functionName = 'get_weather';

        $token = $this->manager->generateToken($functionName, $callId);
        $this->assertTrue($this->manager->validateToken($callId, $functionName, $token));
    }

    public function testGenerateTokenReturnsNonEmptyString(): void
    {
        $token = $this->manager->generateToken('func', 'call-123');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function testGenerateTokenProducesDifferentTokensEachCall(): void
    {
        $a = $this->manager->generateToken('func', 'call-123');
        $b = $this->manager->generateToken('func', 'call-123');
        // Different nonces should yield different tokens.
        $this->assertNotSame($a, $b);
    }

    // ---------------------------------------------------------------
    // 4. createToolToken alias works same as generateToken
    // ---------------------------------------------------------------

    public function testCreateToolTokenProducesValidToken(): void
    {
        $callId = $this->manager->createSession();
        $functionName = 'lookup_user';

        $token = $this->manager->createToolToken($functionName, $callId);
        $this->assertTrue($this->manager->validateToken($callId, $functionName, $token));
    }

    // ---------------------------------------------------------------
    // 5. validateToolToken with reordered params works
    // ---------------------------------------------------------------

    public function testValidateToolTokenWithReorderedParams(): void
    {
        $callId = $this->manager->createSession();
        $functionName = 'send_email';

        $token = $this->manager->generateToken($functionName, $callId);

        // validateToolToken signature: (functionName, token, callId)
        $this->assertTrue($this->manager->validateToolToken($functionName, $token, $callId));
    }

    public function testCreateToolTokenValidatedByValidateToolToken(): void
    {
        $callId = $this->manager->createSession();
        $functionName = 'process_order';

        $token = $this->manager->createToolToken($functionName, $callId);
        $this->assertTrue($this->manager->validateToolToken($functionName, $token, $callId));
    }

    // ---------------------------------------------------------------
    // 6. Wrong function name — validation fails
    // ---------------------------------------------------------------

    public function testWrongFunctionNameFailsValidation(): void
    {
        $callId = $this->manager->createSession();
        $token = $this->manager->generateToken('get_weather', $callId);

        $this->assertFalse($this->manager->validateToken($callId, 'delete_account', $token));
    }

    // ---------------------------------------------------------------
    // 7. Wrong callId — validation fails
    // ---------------------------------------------------------------

    public function testWrongCallIdFailsValidation(): void
    {
        $callId = $this->manager->createSession();
        $token = $this->manager->generateToken('get_weather', $callId);

        $this->assertFalse($this->manager->validateToken('wrong-call-id', 'get_weather', $token));
    }

    // ---------------------------------------------------------------
    // 8. Expired token — create with expiry=0, sleep(1), validation fails
    // ---------------------------------------------------------------

    public function testExpiredTokenFailsValidation(): void
    {
        // Create a manager whose tokens expire immediately (0 seconds from now).
        $manager = new SessionManager(0);
        $callId = $manager->createSession();
        $functionName = 'get_weather';

        $token = $manager->generateToken($functionName, $callId);

        // The token's expiry is time() + 0, i.e. the moment of creation.
        // By the time we validate, time() has advanced at least to that same
        // second, and the check is (int)$tokenExpiry < time(), so it will
        // fail once the clock ticks forward.
        sleep(1);

        $this->assertFalse($manager->validateToken($callId, $functionName, $token));
    }

    // ---------------------------------------------------------------
    // 9. Tampered token — modify token string, validation fails
    // ---------------------------------------------------------------

    public function testTamperedTokenFailsValidation(): void
    {
        $callId = $this->manager->createSession();
        $functionName = 'get_weather';
        $token = $this->manager->generateToken($functionName, $callId);

        // Flip a character in the middle of the token.
        $middle = (int) (strlen($token) / 2);
        $char = $token[$middle];
        $replacement = $char === 'A' ? 'B' : 'A';
        $tampered = substr_replace($token, $replacement, $middle, 1);

        $this->assertFalse($this->manager->validateToken($callId, $functionName, $tampered));
    }

    public function testTruncatedTokenFailsValidation(): void
    {
        $callId = $this->manager->createSession();
        $functionName = 'get_weather';
        $token = $this->manager->generateToken($functionName, $callId);

        $truncated = substr($token, 0, (int) (strlen($token) / 2));
        $this->assertFalse($this->manager->validateToken($callId, $functionName, $truncated));
    }

    // ---------------------------------------------------------------
    // 10. Empty/garbage token — validation fails
    // ---------------------------------------------------------------

    public function testEmptyTokenFailsValidation(): void
    {
        $this->assertFalse($this->manager->validateToken('call-1', 'func', ''));
    }

    public function testGarbageTokenFailsValidation(): void
    {
        $this->assertFalse($this->manager->validateToken('call-1', 'func', '!!!not-a-token!!!'));
    }

    public function testRandomBase64TokenFailsValidation(): void
    {
        $garbage = base64_encode(random_bytes(64));
        $this->assertFalse($this->manager->validateToken('call-1', 'func', $garbage));
    }

    // ---------------------------------------------------------------
    // 11. Different secret keys — token from one manager rejected by another
    // ---------------------------------------------------------------

    public function testTokenFromDifferentManagerFailsValidation(): void
    {
        $managerA = new SessionManager();
        $managerB = new SessionManager();

        $callId = 'shared-call-id';
        $functionName = 'get_weather';

        $token = $managerA->generateToken($functionName, $callId);

        // managerB has a different random secret, so it must reject the token.
        $this->assertFalse($managerB->validateToken($callId, $functionName, $token));
    }

    // ---------------------------------------------------------------
    // 12. getTokenExpirySecs returns correct value
    // ---------------------------------------------------------------

    public function testGetTokenExpirySecsReturnsDefaultValue(): void
    {
        $this->assertSame(3600, $this->manager->getTokenExpirySecs());
    }

    public function testGetTokenExpirySecsReturnsCustomValue(): void
    {
        $manager = new SessionManager(7200);
        $this->assertSame(7200, $manager->getTokenExpirySecs());
    }

    public function testGetTokenExpirySecsReturnsZero(): void
    {
        $manager = new SessionManager(0);
        $this->assertSame(0, $manager->getTokenExpirySecs());
    }
}
