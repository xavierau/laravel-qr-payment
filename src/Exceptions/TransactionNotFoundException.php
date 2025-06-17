<?php

namespace XavierAu\LaravelQrPayment\Exceptions;

class TransactionNotFoundException extends PaymentException
{
    public function __construct(string $transactionId)
    {
        parent::__construct(
            "Transaction not found: {$transactionId}",
            1002,
            null,
            ['transaction_id' => $transactionId]
        );
    }
}