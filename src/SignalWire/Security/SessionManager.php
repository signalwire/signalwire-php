<?php

declare(strict_types=1);

namespace SignalWire\Security;

use RuntimeException;

class SessionManager
{
    private string $secret;
    private int $tokenExpirySecs;
    private bool $debugMode = false;

    /**
     * @param int $tokenExpirySecs Token lifetime in seconds (default 3600).
     *
     * @throws RuntimeException If secure random bytes cannot be generated.
     */
    public function __construct(int $tokenExpirySecs = 3600)
    {
        $this->tokenExpirySecs = $tokenExpirySecs;

        try {
            $this->secret = random_bytes(32);
        } catch (\Exception $e) {
            throw new RuntimeException(
                'Failed to generate cryptographically secure secret: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Create or confirm a session, returning the call ID.
     *
     * @param string|null $callId An existing call ID, or null to generate one.
     *
     * @return string The call ID for this session.
     */
    public function createSession(?string $callId = null): string
    {
        if ($callId !== null) {
            return $callId;
        }

        return $this->generateUuid();
    }

    /**
     * Generate an HMAC-SHA256 signed token for a given function and call.
     *
     * @param string $functionName The function name to bind into the token.
     * @param string $callId       The call ID to bind into the token.
     *
     * @return string A base64url-encoded token.
     */
    public function generateToken(string $functionName, string $callId): string
    {
        $expiry = time() + $this->tokenExpirySecs;
        $nonce = bin2hex(random_bytes(8));

        $message = "{$callId}:{$functionName}:{$expiry}:{$nonce}";
        $signature = hash_hmac('sha256', $message, $this->secret);

        $payload = "{$callId}.{$functionName}.{$expiry}.{$nonce}.{$signature}";

        return $this->base64urlEncode($payload);
    }

    /**
     * Alias for generateToken().
     *
     * @param string $functionName The function name to bind into the token.
     * @param string $callId       The call ID to bind into the token.
     *
     * @return string A base64url-encoded token.
     */
    public function createToolToken(string $functionName, string $callId): string
    {
        return $this->generateToken($functionName, $callId);
    }

    /**
     * Validate a token against the expected call ID and function name.
     *
     * All comparisons use timing-safe equality checks to prevent side-channel attacks.
     *
     * @param string $callId       The expected call ID.
     * @param string $functionName The expected function name.
     * @param string $token        The base64url-encoded token to validate.
     *
     * @return bool True if the token is valid and not expired.
     */
    public function validateToken(string $callId, string $functionName, string $token): bool
    {
        $decoded = $this->base64urlDecode($token);
        if ($decoded === false) {
            return false;
        }

        $parts = explode('.', $decoded);
        if (count($parts) !== 5) {
            return false;
        }

        [$tokenCallId, $tokenFunction, $tokenExpiry, $tokenNonce, $tokenSignature] = $parts;

        // Timing-safe comparison of the function name.
        if (!hash_equals($functionName, $tokenFunction)) {
            return false;
        }

        // Check token has not expired.
        if ((int) $tokenExpiry < time()) {
            return false;
        }

        // Recreate the signature with the extracted nonce and compare.
        $message = "{$tokenCallId}:{$tokenFunction}:{$tokenExpiry}:{$tokenNonce}";
        $expectedSignature = hash_hmac('sha256', $message, $this->secret);

        if (!hash_equals($expectedSignature, $tokenSignature)) {
            return false;
        }

        // Timing-safe comparison of the call ID.
        if (!hash_equals($callId, $tokenCallId)) {
            return false;
        }

        return true;
    }

    /**
     * Alias for validateToken() with reordered parameters.
     *
     * @param string $functionName The expected function name.
     * @param string $token        The base64url-encoded token to validate.
     * @param string $callId       The expected call ID.
     *
     * @return bool True if the token is valid and not expired.
     */
    public function validateToolToken(string $functionName, string $token, string $callId): bool
    {
        return $this->validateToken($callId, $functionName, $token);
    }

    /**
     * Get the configured token expiry duration in seconds.
     *
     * @return int Token lifetime in seconds.
     */
    public function getTokenExpirySecs(): int
    {
        return $this->tokenExpirySecs;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Legacy session-lifecycle API (stateless — API-compatibility only)
    //
    //  The SessionManager is fully stateless: it does not track sessions in
    //  memory (all state lives in the signed token). These methods mirror
    //  Python's ``signalwire.core.security.session_manager.SessionManager``
    //  legacy surface so callers written against the reference port 1:1.
    // ──────────────────────────────────────────────────────────────────

    /**
     * Legacy no-op: activate a session. Always returns true.
     */
    public function activateSession(string $callId): bool
    {
        return true;
    }

    /**
     * Legacy no-op: end a session. Always returns true.
     */
    public function endSession(string $callId): bool
    {
        return true;
    }

    /**
     * Legacy no-op: get session metadata. Always returns an empty array.
     *
     * @return array<string, mixed>|null
     */
    public function getSessionMetadata(string $callId): ?array
    {
        return [];
    }

    /**
     * Legacy no-op: set session metadata. Always returns true.
     */
    public function setSessionMetadata(string $callId, string $key, mixed $value): bool
    {
        return true;
    }

    /**
     * Decode a token's components for inspection WITHOUT validating it.
     *
     * Requires debug mode to be enabled (mirrors Python's ``_debug_mode`` gate);
     * otherwise returns ``['error' => 'debug mode not enabled']``.
     *
     * @return array<string, mixed> Token components and status, or an error map.
     */
    public function debugToken(string $token): array
    {
        if (!$this->debugMode) {
            return ['error' => 'debug mode not enabled'];
        }

        $decoded = $this->base64urlDecode($token);
        if ($decoded === false) {
            return [
                'valid_format' => false,
                'error' => 'malformed token',
                'token_length' => strlen($token),
            ];
        }

        $parts = explode('.', $decoded);
        if (count($parts) !== 5) {
            return [
                'valid_format' => false,
                'parts_count' => count($parts),
                'token_length' => strlen($token),
            ];
        }

        [$tokenCallId, $tokenFunction, $tokenExpiry, $tokenNonce, $tokenSignature] = $parts;

        $currentTime = time();
        if (preg_match('/^-?\d+$/', $tokenExpiry) === 1) {
            $expiry = (int) $tokenExpiry;
            $isExpired = $expiry < $currentTime;
            $expiresIn = $isExpired ? 0 : $expiry - $currentTime;
            $expiryDate = date('c', $expiry);
        } else {
            $isExpired = null;
            $expiresIn = null;
            $expiryDate = null;
        }

        return [
            'valid_format' => true,
            'components' => [
                'call_id' => strlen($tokenCallId) > 8 ? substr($tokenCallId, 0, 8) . '...' : $tokenCallId,
                'function' => $tokenFunction,
                'expiry' => $tokenExpiry,
                'expiry_date' => $expiryDate,
                'nonce' => $tokenNonce,
                'signature' => substr($tokenSignature, 0, 8) . '...',
            ],
            'status' => [
                'current_time' => $currentTime,
                'is_expired' => $isExpired,
                'expires_in_seconds' => $expiresIn,
            ],
        ];
    }

    /**
     * Enable or disable token-debug mode (gates {@see debugToken}).
     */
    public function setDebugMode(bool $enabled): void
    {
        $this->debugMode = $enabled;
    }

    /**
     * Generate a version-4 UUID.
     */
    private function generateUuid(): string
    {
        $bytes = random_bytes(16);

        // Set version to 0100 (UUID v4).
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        // Set variant to 10xx.
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Base64url-encode a string (RFC 4648 without padding).
     */
    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/=', '-_ '), ' ');
    }

    /**
     * Base64url-decode a string (RFC 4648 without padding).
     *
     * @return string|false The decoded string, or false on failure.
     */
    private function base64urlDecode(string $data): string|false
    {
        $base64 = strtr($data, '-_', '+/');

        $mod4 = strlen($base64) % 4;
        if ($mod4 !== 0) {
            $base64 .= str_repeat('=', 4 - $mod4);
        }

        return base64_decode($base64, true);
    }
}
