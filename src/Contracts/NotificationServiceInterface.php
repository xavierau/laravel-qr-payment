<?php

namespace XavierAu\LaravelQrPayment\Contracts;

interface NotificationServiceInterface
{
    /**
     * Send payment confirmation request to customer
     * 
     * @param string $customerId Customer identifier
     * @param string $transactionId Transaction identifier
     * @param array $payload Notification payload
     * @return bool Success status
     */
    public function sendPaymentConfirmationRequest(
        string $customerId,
        string $transactionId,
        array $payload
    ): bool;

    /**
     * Send transaction status update
     * 
     * @param string $transactionId Transaction identifier
     * @param string $status New transaction status
     * @param array $payload Additional payload data
     * @return bool Success status
     */
    public function sendTransactionStatusUpdate(
        string $transactionId,
        string $status,
        array $payload = []
    ): bool;

    /**
     * Send payment completion notification
     * 
     * @param string $customerId Customer identifier
     * @param string $merchantId Merchant identifier
     * @param string $transactionId Transaction identifier
     * @param array $payload Transaction details
     * @return bool Success status
     */
    public function sendPaymentCompletionNotification(
        string $customerId,
        string $merchantId,
        string $transactionId,
        array $payload
    ): bool;

    /**
     * Send merchant webhook notification
     * 
     * @param string $merchantId Merchant identifier
     * @param string $event Event type
     * @param array $payload Event payload
     * @return bool Success status
     */
    public function sendMerchantWebhook(
        string $merchantId,
        string $event,
        array $payload
    ): bool;

    /**
     * Send SMS fallback notification
     * 
     * @param string $phoneNumber Customer phone number
     * @param string $message SMS message
     * @return bool Success status
     */
    public function sendSMSFallback(string $phoneNumber, string $message): bool;

    /**
     * Send email receipt
     * 
     * @param string $email Customer email
     * @param string $transactionId Transaction identifier
     * @param array $receiptData Receipt data
     * @return bool Success status
     */
    public function sendEmailReceipt(
        string $email,
        string $transactionId,
        array $receiptData
    ): bool;
}