<?php

namespace XavierAu\LaravelQrPayment\Contracts;

use XavierAu\LaravelQrPayment\Models\Transaction;

interface TransactionServiceInterface
{
    /**
     * Process a payment transaction
     * 
     * @param string $sessionId Payment session identifier
     * @param string $customerId Customer identifier
     * @param string $merchantId Merchant identifier
     * @param float $amount Transaction amount
     * @param array $options Additional transaction options
     * @return Transaction
     */
    public function processPayment(
        string $sessionId,
        string $customerId,
        string $merchantId,
        float $amount,
        array $options = []
    ): Transaction;

    /**
     * Confirm a pending transaction (customer approval)
     * 
     * @param string $transactionId Transaction identifier
     * @param string $authMethod Authentication method used
     * @param array $authData Authentication data
     * @return Transaction
     */
    public function confirmTransaction(
        string $transactionId,
        string $authMethod,
        array $authData = []
    ): Transaction;

    /**
     * Cancel a pending transaction
     * 
     * @param string $transactionId Transaction identifier
     * @param string $reason Cancellation reason
     * @return Transaction
     */
    public function cancelTransaction(string $transactionId, string $reason = ''): Transaction;

    /**
     * Get transaction by ID
     * 
     * @param string $transactionId Transaction identifier
     * @return Transaction|null
     */
    public function getTransaction(string $transactionId): ?Transaction;

    /**
     * Validate customer balance for transaction
     * 
     * @param string $customerId Customer identifier
     * @param float $amount Transaction amount
     * @return bool
     */
    public function validateCustomerBalance(string $customerId, float $amount): bool;

    /**
     * Refund a completed transaction
     * 
     * @param string $transactionId Original transaction identifier
     * @param float $amount Refund amount (partial or full)
     * @param string $reason Refund reason
     * @return Transaction
     */
    public function refundTransaction(
        string $transactionId,
        float $amount,
        string $reason = ''
    ): Transaction;

    /**
     * Get transaction history for customer
     * 
     * @param string $customerId Customer identifier
     * @param array $filters Optional filters
     * @param int $limit Number of transactions to return
     * @return array
     */
    public function getCustomerTransactions(
        string $customerId,
        array $filters = [],
        int $limit = 50
    ): array;

    /**
     * Get transaction history for merchant
     * 
     * @param string $merchantId Merchant identifier
     * @param array $filters Optional filters
     * @param int $limit Number of transactions to return
     * @return array
     */
    public function getMerchantTransactions(
        string $merchantId,
        array $filters = [],
        int $limit = 50
    ): array;

    /**
     * Ensure idempotency for transaction operations
     * 
     * @param string $idempotencyKey Unique operation key
     * @param callable $operation Operation to execute
     * @return mixed
     */
    public function executeIdempotentOperation(string $idempotencyKey, callable $operation);
}