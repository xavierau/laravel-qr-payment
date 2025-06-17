<?php

namespace XavierAu\LaravelQrPayment\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmationRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $customerId;
    public string $transactionId;
    public string $merchantId;
    public float $amount;
    public string $currency;
    public array $merchantInfo;
    public array $transactionDetails;

    public function __construct(
        string $customerId,
        string $transactionId,
        string $merchantId,
        float $amount,
        string $currency,
        array $merchantInfo = [],
        array $transactionDetails = []
    ) {
        $this->customerId = $customerId;
        $this->transactionId = $transactionId;
        $this->merchantId = $merchantId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->merchantInfo = $merchantInfo;
        $this->transactionDetails = $transactionDetails;
    }

    /**
     * Get the channels the event should broadcast on
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("customer.{$this->customerId}"),
        ];
    }

    /**
     * Get the data to broadcast
     */
    public function broadcastWith(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'merchant_id' => $this->merchantId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'merchant_info' => $this->merchantInfo,
            'transaction_details' => $this->transactionDetails,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name
     */
    public function broadcastAs(): string
    {
        return 'payment.confirmation.requested';
    }
}