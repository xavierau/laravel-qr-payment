<?php

namespace XavierAu\LaravelQrPayment\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use XavierAu\LaravelQrPayment\Contracts\NotificationServiceInterface;
use XavierAu\LaravelQrPayment\Contracts\TransactionServiceInterface;
use XavierAu\LaravelQrPayment\Exceptions\InsufficientBalanceException;
use XavierAu\LaravelQrPayment\Exceptions\TransactionAlreadyProcessedException;
use XavierAu\LaravelQrPayment\Exceptions\TransactionNotFoundException;
use XavierAu\LaravelQrPayment\Exceptions\TransactionTimeoutException;
use XavierAu\LaravelQrPayment\Models\Transaction;

class TransactionService implements TransactionServiceInterface
{
    private const IDEMPOTENCY_CACHE_PREFIX = 'transaction_idempotency:';
    private const IDEMPOTENCY_TTL = 3600; // 1 hour

    private NotificationServiceInterface $notificationService;

    public function __construct(NotificationServiceInterface $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function processPayment(
        string $sessionId,
        string $customerId,
        string $merchantId,
        float $amount,
        array $options = []
    ): Transaction {
        // Validate customer balance
        if (!$this->validateCustomerBalance($customerId, $amount)) {
            throw new InsufficientBalanceException($customerId, $amount);
        }

        $currency = $options['currency'] ?? config('qr-payment.transaction.currency', 'USD');
        $fees = $this->calculateTransactionFees($amount, $options);
        $netAmount = $amount - $fees;
        $timeoutMinutes = config('qr-payment.session.timeout_minutes', 2);

        $transaction = Transaction::create([
            'transaction_id' => $this->generateTransactionId(),
            'session_id' => $sessionId,
            'customer_id' => $customerId,
            'merchant_id' => $merchantId,
            'amount' => $amount,
            'currency' => $currency,
            'type' => Transaction::TYPE_PAYMENT,
            'status' => Transaction::STATUS_PENDING,
            'fees' => $fees,
            'net_amount' => $netAmount,
            'timeout_at' => Carbon::now()->addMinutes($timeoutMinutes),
            'metadata' => $options['metadata'] ?? null,
        ]);

        // Send payment confirmation request to customer
        $this->notificationService->sendPaymentConfirmationRequest(
            $customerId,
            $transaction->transaction_id,
            [
                'merchant_id' => $merchantId,
                'amount' => $amount,
                'currency' => $currency,
                'merchant_info' => $options['merchant_info'] ?? [],
                'transaction_details' => $options['transaction_details'] ?? []
            ]
        );

        return $transaction;
    }

    public function confirmTransaction(
        string $transactionId,
        string $authMethod,
        array $authData = []
    ): Transaction {
        $transaction = $this->getTransaction($transactionId);

        if (!$transaction) {
            throw new TransactionNotFoundException($transactionId);
        }

        if ($transaction->isTimedOut()) {
            throw new TransactionTimeoutException($transactionId);
        }

        if (!$transaction->isPending()) {
            throw new TransactionAlreadyProcessedException($transactionId, $transaction->status);
        }

        $previousStatus = $transaction->status;
        $transaction->markAsConfirmed($authMethod, $authData);

        // Send transaction status update
        $this->notificationService->sendTransactionStatusUpdate(
            $transactionId,
            Transaction::STATUS_CONFIRMED,
            [
                'customer_id' => $transaction->customer_id,
                'merchant_id' => $transaction->merchant_id,
                'previous_status' => $previousStatus,
                'auth_method' => $authMethod
            ]
        );

        return $transaction->fresh();
    }

    public function cancelTransaction(string $transactionId, string $reason = ''): Transaction
    {
        $transaction = $this->getTransaction($transactionId);

        if (!$transaction) {
            throw new TransactionNotFoundException($transactionId);
        }

        if (!$transaction->isPending() && !$transaction->isProcessing()) {
            throw new TransactionAlreadyProcessedException($transactionId, $transaction->status);
        }

        $transaction->markAsCancelled($reason);

        return $transaction->fresh();
    }

    public function getTransaction(string $transactionId): ?Transaction
    {
        return Transaction::where('transaction_id', $transactionId)->first();
    }

    public function validateCustomerBalance(string $customerId, float $amount): bool
    {
        // Mock implementation - in real system, this would check actual balance
        // For demo purposes, assume insufficient balance for very large amounts
        if ($amount > 100000) {
            return false;
        }

        // Simulate balance check (in real implementation, this would query balance service)
        return true;
    }

    public function refundTransaction(
        string $transactionId,
        float $amount,
        string $reason = ''
    ): Transaction {
        $originalTransaction = $this->getTransaction($transactionId);

        if (!$originalTransaction) {
            throw new TransactionNotFoundException($transactionId);
        }

        if (!$originalTransaction->isCompleted()) {
            throw new TransactionAlreadyProcessedException(
                $transactionId,
                'Transaction must be completed before refund'
            );
        }

        $refundTransaction = Transaction::create([
            'transaction_id' => $this->generateTransactionId(),
            'session_id' => $originalTransaction->session_id,
            'customer_id' => $originalTransaction->customer_id,
            'merchant_id' => $originalTransaction->merchant_id,
            'amount' => $amount,
            'currency' => $originalTransaction->currency,
            'type' => Transaction::TYPE_REFUND,
            'status' => Transaction::STATUS_COMPLETED,
            'parent_transaction_id' => $originalTransaction->transaction_id,
            'fees' => 0, // Typically no fees on refunds
            'net_amount' => $amount,
            'failure_reason' => $reason,
            'processed_at' => Carbon::now(),
            'confirmed_at' => Carbon::now(),
        ]);

        return $refundTransaction;
    }

    public function getCustomerTransactions(
        string $customerId,
        array $filters = [],
        int $limit = 50
    ): array {
        $query = Transaction::where('customer_id', $customerId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return $query->orderBy('created_at', 'desc')
                    ->limit($limit)
                    ->get()
                    ->all();
    }

    public function getMerchantTransactions(
        string $merchantId,
        array $filters = [],
        int $limit = 50
    ): array {
        $query = Transaction::where('merchant_id', $merchantId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return $query->orderBy('created_at', 'desc')
                    ->limit($limit)
                    ->get()
                    ->all();
    }

    public function executeIdempotentOperation(string $idempotencyKey, callable $operation)
    {
        $cacheKey = self::IDEMPOTENCY_CACHE_PREFIX . $idempotencyKey;

        // Check if operation result is already cached
        $cachedResult = Cache::get($cacheKey);
        if ($cachedResult !== null) {
            return $cachedResult;
        }

        // Execute operation and cache result
        $result = $operation();
        Cache::put($cacheKey, $result, self::IDEMPOTENCY_TTL);

        return $result;
    }

    /**
     * Generate a unique transaction ID
     */
    private function generateTransactionId(): string
    {
        return 'txn_' . Str::uuid()->toString();
    }

    /**
     * Calculate transaction fees based on amount and options
     */
    private function calculateTransactionFees(float $amount, array $options = []): float
    {
        if (!isset($options['calculate_fees']) || !$options['calculate_fees']) {
            return 0.0;
        }

        // Simple fee calculation: 2.9% + $0.30
        $percentageFee = $amount * 0.029;
        $fixedFee = 0.30;

        return round($percentageFee + $fixedFee, 2);
    }
}