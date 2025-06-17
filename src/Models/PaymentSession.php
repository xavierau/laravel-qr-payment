<?php

namespace XavierAu\LaravelQrPayment\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'customer_id',
        'merchant_id',
        'amount',
        'currency',
        'status',
        'security_token',
        'expires_at',
        'scanned_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'scanned_at' => 'datetime',
        'metadata' => 'array',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SCANNED = 'scanned';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public function getTable(): string
    {
        return config('qr-payment.database.table_prefix', 'qr_payment_') . 'sessions';
    }

    /**
     * Check if the session is still active (not expired)
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_PENDING && 
               $this->expires_at > Carbon::now();
    }

    /**
     * Check if the session has been scanned by merchant
     */
    public function isScanned(): bool
    {
        return $this->status === self::STATUS_SCANNED;
    }

    /**
     * Check if the session is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at <= Carbon::now() || 
               $this->status === self::STATUS_EXPIRED;
    }

    /**
     * Mark session as expired
     */
    public function markAsExpired(): bool
    {
        return $this->update(['status' => self::STATUS_EXPIRED]);
    }

    /**
     * Mark session as scanned by merchant
     */
    public function markAsScanned(string $merchantId, float $amount, array $metadata = []): bool
    {
        return $this->update([
            'status' => self::STATUS_SCANNED,
            'merchant_id' => $merchantId,
            'amount' => $amount,
            'scanned_at' => Carbon::now(),
            'metadata' => array_merge($this->metadata ?? [], $metadata),
        ]);
    }

    /**
     * Mark session as confirmed
     */
    public function markAsConfirmed(): bool
    {
        return $this->update(['status' => self::STATUS_CONFIRMED]);
    }

    /**
     * Get the timeout duration in minutes
     */
    public function getTimeoutDuration(): int
    {
        return config('qr-payment.session.timeout_minutes', 2);
    }
}