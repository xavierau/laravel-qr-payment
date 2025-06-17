<?php

namespace XavierAu\LaravelQrPayment\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentCompleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $transactionId;
    public string $customerId;
    public string $merchantId;
    public float $amount;
    public string $currency;
    public array $receiptData;

    public function __construct(
        string $transactionId,
        string $customerId,
        string $merchantId,
        float $amount,
        string $currency,
        array $receiptData = []
    ) {
        $this->transactionId = $transactionId;
        $this->customerId = $customerId;
        $this->merchantId = $merchantId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->receiptData = $receiptData;
    }

    /**
     * Get the channels the event should broadcast on
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("customer.{$this->customerId}"),
            new PrivateChannel("merchant.{$this->merchantId}"),
        ];
    }

    /**
     * Get the data to broadcast
     */
    public function broadcastWith(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'receipt_data' => $this->receiptData,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name
     */
    public function broadcastAs(): string
    {
        return 'payment.completed';
    }
}