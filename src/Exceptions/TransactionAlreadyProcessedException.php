<?php

namespace XavierAu\LaravelQrPayment\Exceptions;

class TransactionAlreadyProcessedException extends PaymentException
{
    public function __construct(string $transactionId, string $currentStatus)
    {
        parent::__construct(
            "Transaction {$transactionId} is already processed with status: {$currentStatus}",
            1003,
            null,
            [
                'transaction_id' => $transactionId,
                'current_status' => $currentStatus,
            ]
        );
    }
}