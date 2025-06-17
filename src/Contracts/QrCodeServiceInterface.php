<?php

namespace XavierAu\LaravelQrPayment\Contracts;

interface QrCodeServiceInterface
{
    /**
     * Generate a QR code for payment
     * 
     * @param string $sessionId Unique payment session identifier
     * @param array $options Additional options for QR generation
     * @return string Base64 encoded QR code image
     */
    public function generatePaymentQrCode(string $sessionId, array $options = []): string;

    /**
     * Validate if a QR code is still valid (not expired)
     * 
     * @param string $sessionId Payment session identifier
     * @return bool True if valid, false if expired
     */
    public function isQrCodeValid(string $sessionId): bool;

    /**
     * Get QR code expiry time
     * 
     * @param string $sessionId Payment session identifier
     * @return \DateTime|null Expiry time or null if not found
     */
    public function getQrCodeExpiry(string $sessionId): ?\DateTime;

    /**
     * Regenerate QR code with new expiry
     * 
     * @param string $sessionId Payment session identifier
     * @param array $options Additional options for QR generation
     * @return string Base64 encoded QR code image
     */
    public function regenerateQrCode(string $sessionId, array $options = []): string;
}