<?php

namespace XavierAu\LaravelQrPayment\Contracts;

use XavierAu\LaravelQrPayment\Models\PaymentSession;

interface PaymentSessionServiceInterface
{
    /**
     * Create a new payment session with cryptographically secure token
     * 
     * @param string $customerId Customer identifier
     * @param array $options Additional session options
     * @return PaymentSession
     */
    public function createSession(string $customerId, array $options = []): PaymentSession;

    /**
     * Get an active payment session by ID
     * 
     * @param string $sessionId Session identifier
     * @return PaymentSession|null
     */
    public function getSession(string $sessionId): ?PaymentSession;

    /**
     * Validate if a session is still active and not expired
     * 
     * @param string $sessionId Session identifier
     * @return bool
     */
    public function isSessionActive(string $sessionId): bool;

    /**
     * Expire a session manually
     * 
     * @param string $sessionId Session identifier
     * @return bool Success status
     */
    public function expireSession(string $sessionId): bool;

    /**
     * Update session with merchant scan data
     * 
     * @param string $sessionId Session identifier
     * @param string $merchantId Merchant identifier
     * @param float $amount Transaction amount
     * @param array $metadata Additional metadata
     * @return PaymentSession
     */
    public function updateWithMerchantScan(
        string $sessionId, 
        string $merchantId, 
        float $amount, 
        array $metadata = []
    ): PaymentSession;

    /**
     * Clean up expired sessions
     * 
     * @return int Number of sessions cleaned up
     */
    public function cleanupExpiredSessions(): int;

    /**
     * Get all active sessions for a customer
     * 
     * @param string $customerId Customer identifier
     * @return array
     */
    public function getCustomerActiveSessions(string $customerId): array;

    /**
     * Prevent session replay attacks by validating token uniqueness
     * 
     * @param string $sessionId Session identifier
     * @param string $token Security token
     * @return bool
     */
    public function validateSessionToken(string $sessionId, string $token): bool;
}