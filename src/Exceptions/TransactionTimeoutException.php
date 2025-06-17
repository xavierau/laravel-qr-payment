<?php

namespace XavierAu\LaravelQrPayment\Exceptions;

class TransactionTimeoutException extends PaymentException
{
    public function __construct(string $transactionId)
    {
        parent::__construct(
            "Transaction {$transactionId} has timed out",
            1004,
            null,
            ['transaction_id' => $transactionId]
        );
    }
}