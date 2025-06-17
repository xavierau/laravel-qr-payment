<?php

namespace XavierAu\LaravelQrPayment\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use XavierAu\LaravelQrPayment\Contracts\NotificationServiceInterface;
use XavierAu\LaravelQrPayment\Events\PaymentCompleted;
use XavierAu\LaravelQrPayment\Events\PaymentConfirmationRequested;
use XavierAu\LaravelQrPayment\Events\TransactionStatusUpdated;

class NotificationService implements NotificationServiceInterface
{
    public function sendPaymentConfirmationRequest(
        string $customerId,
        string $transactionId,
        array $payload
    ): bool {
        $this->validatePaymentConfirmationPayload($payload);

        if (!$this->isBroadcastingEnabled()) {
            return true;
        }

        try {
            event(new PaymentConfirmationRequested(
                $customerId,
                $transactionId,
                $payload['merchant_id'],
                $payload['amount'],
                $payload['currency'] ?? config('qr-payment.transaction.currency', 'USD'),
                $payload['merchant_info'] ?? [],
                $payload['transaction_details'] ?? []
            ));

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send payment confirmation request', [
                'customer_id' => $customerId,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function sendTransactionStatusUpdate(
        string $transactionId,
        string $status,
        array $payload = []
    ): bool {
        if (!$this->isBroadcastingEnabled()) {
            return true;
        }

        try {
            event(new TransactionStatusUpdated(
                $transactionId,
                $payload['customer_id'] ?? '',
                $payload['merchant_id'] ?? '',
                $status,
                $payload['previous_status'] ?? '',
                $payload
            ));

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send transaction status update', [
                'transaction_id' => $transactionId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function sendPaymentCompletionNotification(
        string $customerId,
        string $merchantId,
        string $transactionId,
        array $payload
    ): bool {
        if (!$this->isBroadcastingEnabled()) {
            return true;
        }

        try {
            event(new PaymentCompleted(
                $transactionId,
                $customerId,
                $merchantId,
                $payload['amount'],
                $payload['currency'] ?? config('qr-payment.transaction.currency', 'USD'),
                $payload['receipt_data'] ?? []
            ));

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send payment completion notification', [
                'customer_id' => $customerId,
                'merchant_id' => $merchantId,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function sendMerchantWebhook(
        string $merchantId,
        string $event,
        array $payload
    ): bool {
        try {
            // In a real implementation, this would:
            // 1. Get merchant webhook URL from database
            // 2. Sign the payload with merchant secret
            // 3. Send HTTP POST request with retry logic
            // 4. Handle webhook delivery status
            
            Log::info('Merchant webhook sent', [
                'merchant_id' => $merchantId,
                'event' => $event,
                'payload' => $payload
            ]);

            // Mock successful webhook delivery
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send merchant webhook', [
                'merchant_id' => $merchantId,
                'event' => $event,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function sendSMSFallback(string $phoneNumber, string $message): bool
    {
        try {
            // In a real implementation, this would integrate with SMS providers
            // like Twilio, AWS SNS, or other SMS services
            
            Log::info('SMS fallback sent', [
                'phone_number' => $this->maskPhoneNumber($phoneNumber),
                'message_length' => strlen($message)
            ]);

            // Mock successful SMS delivery
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send SMS fallback', [
                'phone_number' => $this->maskPhoneNumber($phoneNumber),
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function sendEmailReceipt(
        string $email,
        string $transactionId,
        array $receiptData
    ): bool {
        try {
            // In a real implementation, this would:
            // 1. Generate PDF receipt
            // 2. Send email with receipt attachment
            // 3. Track email delivery status
            
            Log::info('Email receipt sent', [
                'email' => $this->maskEmail($email),
                'transaction_id' => $transactionId,
                'receipt_amount' => $receiptData['amount'] ?? 'unknown'
            ]);

            // Mock successful email delivery
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send email receipt', [
                'email' => $this->maskEmail($email),
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Check if broadcasting is enabled
     */
    private function isBroadcastingEnabled(): bool
    {
        return config('qr-payment.broadcasting.enabled', true);
    }

    /**
     * Validate payment confirmation payload
     */
    private function validatePaymentConfirmationPayload(array $payload): void
    {
        $requiredFields = ['merchant_id', 'amount'];

        foreach ($requiredFields as $field) {
            if (!isset($payload[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!is_numeric($payload['amount']) || $payload['amount'] <= 0) {
            throw new \InvalidArgumentException('Amount must be a positive number');
        }
    }

    /**
     * Mask phone number for logging
     */
    private function maskPhoneNumber(string $phoneNumber): string
    {
        if (strlen($phoneNumber) < 4) {
            return '***';
        }

        return substr($phoneNumber, 0, 3) . str_repeat('*', strlen($phoneNumber) - 6) . substr($phoneNumber, -3);
    }

    /**
     * Mask email address for logging
     */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***@***.***';
        }

        $username = $parts[0];
        $domain = $parts[1];

        $maskedUsername = strlen($username) > 2 
            ? substr($username, 0, 2) . str_repeat('*', strlen($username) - 2)
            : str_repeat('*', strlen($username));

        return $maskedUsername . '@' . $domain;
    }
}