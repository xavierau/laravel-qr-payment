<?php

namespace XavierAu\LaravelQrPayment\Exceptions;

class InsufficientBalanceException extends PaymentException
{
    public function __construct(
        string $customerId,
        float $requiredAmount,
        float $availableBalance = 0
    ) {
        parent::__construct(
            "Insufficient balance for customer {$customerId}. Required: {$requiredAmount}, Available: {$availableBalance}",
            1001,
            null,
            [
                'customer_id' => $customerId,
                'required_amount' => $requiredAmount,
                'available_balance' => $availableBalance,
            ]
        );
    }
}