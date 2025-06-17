<?php

namespace XavierAu\LaravelQrPayment\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'session_id',
        'customer_id',
        'merchant_id',
        'amount',
        'currency',
        'type',
        'status',
        'auth_method',
        'auth_data',
        'fees',
        'net_amount',
        'reference_id',
        'parent_transaction_id',
        'processed_at',
        'confirmed_at',
        'cancelled_at',
        'timeout_at',
        'metadata',
        'failure_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fees' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'auth_data' => 'array',
        'metadata' => 'array',
        'processed_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'timeout_at' => 'datetime',
    ];

    // Transaction Types
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_REFUND = 'refund';
    public const TYPE_SETTLEMENT = 'settlement';

    // Transaction Statuses
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    // Authentication Methods
    public const AUTH_PIN = 'pin';
    public const AUTH_BIOMETRIC = 'biometric';
    public const AUTH_PASSWORD = 'password';
    public const AUTH_PATTERN = 'pattern';

    public function getTable(): string
    {
        return config('qr-payment.database.table_prefix', 'qr_payment_') . 'transactions';
    }

    /**
     * Get the payment session associated with this transaction
     */
    public function paymentSession()
    {
        return $this->belongsTo(PaymentSession::class, 'session_id', 'session_id');
    }

    /**
     * Get the parent transaction (for refunds)
     */
    public function parentTransaction()
    {
        return $this->belongsTo(Transaction::class, 'parent_transaction_id', 'transaction_id');
    }

    /**
     * Get child transactions (refunds)
     */
    public function childTransactions()
    {
        return $this->hasMany(Transaction::class, 'parent_transaction_id', 'transaction_id');
    }

    /**
     * Check if transaction is pending customer confirmation
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if transaction is processing
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if transaction is completed successfully
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if transaction has failed
     */
    public function isFailed(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_CANCELLED]);
    }

    /**
     * Check if transaction has timed out
     */
    public function isTimedOut(): bool
    {
        return $this->timeout_at && Carbon::now()->greaterThan($this->timeout_at);
    }

    /**
     * Mark transaction as processing
     */
    public function markAsProcessing(): bool
    {
        return $this->update([
            'status' => self::STATUS_PROCESSING,
            'processed_at' => Carbon::now(),
        ]);
    }

    /**
     * Mark transaction as confirmed
     */
    public function markAsConfirmed(string $authMethod, array $authData = []): bool
    {
        return $this->update([
            'status' => self::STATUS_CONFIRMED,
            'auth_method' => $authMethod,
            'auth_data' => $authData,
            'confirmed_at' => Carbon::now(),
        ]);
    }

    /**
     * Mark transaction as completed
     */
    public function markAsCompleted(): bool
    {
        return $this->update([
            'status' => self::STATUS_COMPLETED,
        ]);
    }

    /**
     * Mark transaction as failed
     */
    public function markAsFailed(string $reason = ''): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Mark transaction as cancelled
     */
    public function markAsCancelled(string $reason = ''): bool
    {
        return $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => Carbon::now(),
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Calculate net amount after fees
     */
    public function calculateNetAmount(): float
    {
        return $this->amount - ($this->fees ?? 0);
    }

    /**
     * Get transaction timeout duration in minutes
     */
    public function getTimeoutDuration(): int
    {
        return config('qr-payment.session.timeout_minutes', 2);
    }
}