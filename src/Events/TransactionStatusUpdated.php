<?php

namespace XavierAu\LaravelQrPayment\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $transactionId;
    public string $customerId;
    public string $merchantId;
    public string $status;
    public string $previousStatus;
    public array $payload;

    public function __construct(
        string $transactionId,
        string $customerId,
        string $merchantId,
        string $status,
        string $previousStatus = '',
        array $payload = []
    ) {
        $this->transactionId = $transactionId;
        $this->customerId = $customerId;
        $this->merchantId = $merchantId;
        $this->status = $status;
        $this->previousStatus = $previousStatus;
        $this->payload = $payload;
    }

    /**
     * Get the channels the event should broadcast on
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("customer.{$this->customerId}"),
            new PrivateChannel("merchant.{$this->merchantId}"),
            new PrivateChannel("transaction.{$this->transactionId}"),
        ];
    }

    /**
     * Get the data to broadcast
     */
    public function broadcastWith(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'status' => $this->status,
            'previous_status' => $this->previousStatus,
            'payload' => $this->payload,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name
     */
    public function broadcastAs(): string
    {
        return 'transaction.status.updated';
    }
}