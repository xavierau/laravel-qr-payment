<?php

namespace XavierAu\LaravelQrPayment\Exceptions;

use Exception;

class PaymentException extends Exception
{
    protected array $context = [];

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Exception $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}