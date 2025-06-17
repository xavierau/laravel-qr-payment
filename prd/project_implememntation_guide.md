# QR Payment System - Technical Implementation Guide
## Laravel 12 + Vue 3 + MySQL + Redis

**Version:** 1.0  
**Last Updated:** May 31, 2025

---

## Table of Contents
1. [Architecture Overview](#1-architecture-overview)
2. [Project Setup](#2-project-setup)
3. [Database Design](#3-database-design)
4. [Backend Implementation (Laravel)](#4-backend-implementation-laravel)
5. [Frontend Implementation (Vue 3)](#5-frontend-implementation-vue-3)
6. [Real-time Features](#6-real-time-features)
7. [Security Implementation](#7-security-implementation)
8. [Exception Handling](#8-exception-handling)
9. [Testing Strategy](#9-testing-strategy)
10. [Deployment Guide](#10-deployment-guide)

---

## 1. Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                        Frontend Apps                         │
├──────────────────────────┬──────────────────────────────────┤
│     Customer App         │         Merchant App             │
│     (Vue 3 + PWA)       │      (Vue 3 + Capacitor)        │
└──────────────────────────┴──────────────────────────────────┘
                           │
                           │ HTTPS + JWT
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                    API Gateway (Nginx)                       │
└─────────────────────────────────────────────────────────────┘
                           │
┌─────────────────────────────────────────────────────────────┐
│                  Laravel 12 Backend                          │
├─────────────────┬────────────────┬──────────────────────────┤
│  Auth Service   │ Payment Service│   Notification Service   │
├─────────────────┴────────────────┴──────────────────────────┤
│                    Queue (Redis)                             │
├─────────────────┬────────────────┬──────────────────────────┤
│     MySQL       │     Redis       │    WebSocket (Pusher)   │
└─────────────────┴────────────────┴──────────────────────────┘
```

---

## 2. Project Setup

### 2.1 Laravel Backend Setup

```bash
# Create Laravel project
composer create-project laravel/laravel payment-backend
cd payment-backend

# Install required packages
composer require laravel/sanctum
composer require laravel/horizon
composer require predis/predis
composer require pusher/pusher-php-server
composer require bacon/bacon-qr-code
composer require spatie/laravel-permission
composer require spatie/laravel-query-builder
composer require spatie/laravel-data
composer require laravel/telescope --dev

# Install development packages
composer require --dev phpunit/phpunit
composer require --dev laravel/pint
composer require --dev nunomaduro/larastan
```

### 2.2 Vue 3 Frontend Setup

```bash
# Customer App
npm create vue@latest customer-app
cd customer-app
npm install axios pinia @vueuse/core qrcode.vue3
npm install @vitejs/plugin-pwa vite-plugin-pwa
npm install primevue primeicons
npm install laravel-echo pusher-js

# Merchant App
npm create vue@latest merchant-app
cd merchant-app
npm install @capacitor/core @capacitor/ios @capacitor/android
npm install qr-scanner axios pinia
npm install primevue primeicons
```

### 2.3 Environment Configuration

```env
# .env file for Laravel
APP_NAME="QR Payment System"
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://api.payment.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=payment_system
DB_USERNAME=payment_user
DB_PASSWORD=secure_password

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

BROADCAST_DRIVER=pusher
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

PUSHER_APP_ID=your_app_id
PUSHER_APP_KEY=your_app_key
PUSHER_APP_SECRET=your_app_secret
PUSHER_APP_CLUSTER=mt1

# Payment specific
PAYMENT_SESSION_TTL=300 # 5 minutes
PAYMENT_CONFIRMATION_TTL=120 # 2 minutes
PAYMENT_OFFLINE_LIMIT=50.00
PAYMENT_DAILY_LIMIT=10000.00
```

---

## 3. Database Design

### 3.1 MySQL Schema

```sql
-- Users table
CREATE TABLE users (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    wallet_id VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(20) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    pin VARCHAR(255) NULL,
    biometric_enabled BOOLEAN DEFAULT FALSE,
    device_id VARCHAR(255) NULL,
    fcm_token VARCHAR(255) NULL,
    balance DECIMAL(15,2) DEFAULT 0.00,
    daily_limit DECIMAL(15,2) DEFAULT 10000.00,
    status ENUM('active', 'suspended', 'blocked') DEFAULT 'active',
    kyc_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wallet_id (wallet_id),
    INDEX idx_phone (phone),
    INDEX idx_device_id (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Merchants table
CREATE TABLE merchants (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    merchant_id VARCHAR(255) UNIQUE NOT NULL,
    business_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(20) NOT NULL,
    category VARCHAR(100) NOT NULL,
    address TEXT,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    verified BOOLEAN DEFAULT FALSE,
    commission_rate DECIMAL(5,2) DEFAULT 2.00,
    settlement_account VARCHAR(255) NOT NULL,
    status ENUM('active', 'suspended', 'blocked') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_merchant_id (merchant_id),
    INDEX idx_category (category),
    INDEX idx_location (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment sessions table
CREATE TABLE payment_sessions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    session_id VARCHAR(255) UNIQUE NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(255) NOT NULL,
    qr_data TEXT NOT NULL,
    status ENUM('active', 'used', 'expired') DEFAULT 'active',
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_session_id (session_id),
    INDEX idx_user_device (user_id, device_id),
    INDEX idx_expires_at (expires_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transactions table
CREATE TABLE transactions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(255) UNIQUE NOT NULL,
    session_id VARCHAR(255) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    merchant_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency CHAR(3) DEFAULT 'USD',
    status ENUM('pending', 'processing', 'completed', 'failed', 'reversed', 'timeout') DEFAULT 'pending',
    payment_method ENUM('balance', 'card', 'bank') DEFAULT 'balance',
    merchant_reference VARCHAR(255) NULL,
    description TEXT NULL,
    metadata JSON NULL,
    initiated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    failure_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (merchant_id) REFERENCES merchants(id),
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_session_id (session_id),
    INDEX idx_user_merchant (user_id, merchant_id),
    INDEX idx_status_created (status, created_at),
    INDEX idx_merchant_status (merchant_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transaction items table (optional details)
CREATE TABLE transaction_items (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    transaction_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    price DECIMAL(15,2) NOT NULL,
    total DECIMAL(15,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    INDEX idx_transaction_id (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Balance ledger for audit trail
CREATE TABLE balance_ledger (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    transaction_id VARCHAR(255) NOT NULL,
    type ENUM('debit', 'credit') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    balance_before DECIMAL(15,2) NOT NULL,
    balance_after DECIMAL(15,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_transaction_id (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications table
CREATE TABLE notifications (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    data JSON NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, read_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed jobs table (Laravel default)
CREATE TABLE failed_jobs (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(255) UNIQUE NOT NULL,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload LONGTEXT NOT NULL,
    exception LONGTEXT NOT NULL,
    failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.2 Redis Structure

```php
// Redis key patterns
payment:session:{session_id} - Payment session data (TTL: 5 minutes)
payment:transaction:{transaction_id} - Transaction state (TTL: 10 minutes)
payment:pending:{user_id} - Pending payment requests (TTL: 2 minutes)
payment:rate_limit:{user_id} - Rate limiting counter (TTL: 1 minute)
payment:device:{device_id} - Device session binding (TTL: 1 hour)
payment:merchant:cache:{merchant_id} - Merchant data cache (TTL: 1 hour)
payment:stats:daily:{date} - Daily statistics (TTL: 7 days)
```

---

## 4. Backend Implementation (Laravel)

### 4.1 Models

```php
// app/Models/User.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'pin',
        'wallet_id', 'device_id', 'fcm_token', 'balance',
        'daily_limit', 'status', 'kyc_verified', 'biometric_enabled'
    ];

    protected $hidden = [
        'password', 'pin', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'balance' => 'decimal:2',
        'daily_limit' => 'decimal:2',
        'kyc_verified' => 'boolean',
        'biometric_enabled' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            $user->wallet_id = self::generateWalletId();
        });
    }

    public static function generateWalletId(): string
    {
        do {
            $walletId = 'W' . strtoupper(Str::random(12));
        } while (self::where('wallet_id', $walletId)->exists());

        return $walletId;
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PaymentSession::class);
    }

    public function balanceLedger(): HasMany
    {
        return $this->hasMany(BalanceLedger::class);
    }

    public function hasEnoughBalance(float $amount): bool
    {
        return $this->balance >= $amount;
    }

    public function getTodaySpent(): float
    {
        return $this->transactions()
            ->whereDate('created_at', today())
            ->where('status', 'completed')
            ->sum('amount');
    }

    public function canSpend(float $amount): bool
    {
        $todaySpent = $this->getTodaySpent();
        return ($todaySpent + $amount) <= $this->daily_limit;
    }
}
```

```php
// app/Models/Transaction.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Transaction extends Model
{
    protected $fillable = [
        'transaction_id', 'session_id', 'user_id', 'merchant_id',
        'amount', 'currency', 'status', 'payment_method',
        'merchant_reference', 'description', 'metadata',
        'initiated_at', 'confirmed_at', 'completed_at',
        'failed_at', 'failure_reason'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'initiated_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            $transaction->transaction_id = self::generateTransactionId();
            $transaction->initiated_at = now();
        });
    }

    public static function generateTransactionId(): string
    {
        return 'TXN' . date('Ymd') . strtoupper(Str::random(10));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function canBeConfirmed(): bool
    {
        return $this->status === 'pending' && 
               $this->initiated_at->addMinutes(2) > now();
    }

    public function isExpired(): bool
    {
        return $this->status === 'pending' && 
               $this->initiated_at->addMinutes(2) <= now();
    }
}
```

### 4.2 Controllers

```php
// app/Http/Controllers/Api/PaymentSessionController.php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentSessionService;
use App\Http\Requests\CreateSessionRequest;
use App\Http\Resources\PaymentSessionResource;
use Illuminate\Http\JsonResponse;

class PaymentSessionController extends Controller
{
    public function __construct(
        private PaymentSessionService $sessionService
    ) {}

    public function create(CreateSessionRequest $request): JsonResponse
    {
        try {
            $session = $this->sessionService->createSession(
                $request->user(),
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'data' => new PaymentSessionResource($session)
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment session',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function show(string $sessionId): JsonResponse
    {
        $session = $this->sessionService->getSession($sessionId);

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found or expired'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new PaymentSessionResource($session)
        ]);
    }
}
```

```php
// app/Http/Controllers/Api/TransactionController.php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TransactionService;
use App\Http\Requests\InitiatePaymentRequest;
use App\Http\Requests\ConfirmPaymentRequest;
use App\Http\Resources\TransactionResource;
use App\Jobs\ProcessPaymentNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionService $transactionService
    ) {}

    public function initiate(InitiatePaymentRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Validate merchant
            $merchant = $request->user(); // Assuming merchant is authenticated
            
            // Create transaction
            $transaction = $this->transactionService->createTransaction(
                $request->session_id,
                $merchant->id,
                $request->amount,
                $request->items ?? []
            );

            // Send notification to customer
            ProcessPaymentNotification::dispatch($transaction)
                ->onQueue('high-priority');

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => new TransactionResource($transaction),
                'message' => 'Payment request sent to customer'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment initiation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate payment',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function confirm(ConfirmPaymentRequest $request): JsonResponse
    {
        try {
            $result = $this->transactionService->confirmTransaction(
                $request->transaction_id,
                $request->user(),
                $request->auth_method,
                $request->auth_token
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => new TransactionResource($result['transaction']),
                'message' => 'Payment confirmed successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Payment confirmation failed', [
                'transaction_id' => $request->transaction_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm payment'
            ], 500);
        }
    }

    public function status(string $transactionId): JsonResponse
    {
        $transaction = $this->transactionService->getTransaction($transactionId);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new TransactionResource($transaction)
        ]);
    }
}
```

### 4.3 Services

```php
// app/Services/PaymentSessionService.php
<?php

namespace App\Services;

use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class PaymentSessionService
{
    private const SESSION_TTL = 300; // 5 minutes

    public function createSession(User $user, array $data): PaymentSession
    {
        // Check for existing active session
        $existingSession = $this->getActiveSession($user->id);
        if ($existingSession) {
            return $existingSession;
        }

        // Create new session
        $session = PaymentSession::create([
            'session_id' => $this->generateSessionId(),
            'user_id' => $user->id,
            'device_id' => $data['device_id'],
            'status' => 'active',
            'expires_at' => now()->addSeconds(self::SESSION_TTL)
        ]);

        // Generate QR data
        $qrData = $this->generateQRData($session, $user);
        $session->update(['qr_data' => $qrData]);

        // Cache session
        $this->cacheSession($session);

        return $session;
    }

    public function getSession(string $sessionId): ?PaymentSession
    {
        // Try cache first
        $cached = Cache::get("payment:session:{$sessionId}");
        if ($cached) {
            return $cached;
        }

        // Fallback to database
        $session = PaymentSession::where('session_id', $sessionId)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();

        if ($session) {
            $this->cacheSession($session);
        }

        return $session;
    }

    public function validateSession(string $sessionId): array
    {
        $session = $this->getSession($sessionId);

        if (!$session) {
            return ['valid' => false, 'error' => 'Session not found'];
        }

        if ($session->status !== 'active') {
            return ['valid' => false, 'error' => 'Session already used'];
        }

        if ($session->expires_at < now()) {
            $session->update(['status' => 'expired']);
            return ['valid' => false, 'error' => 'Session expired'];
        }

        return ['valid' => true, 'session' => $session];
    }

    private function generateSessionId(): string
    {
        return 'PS' . date('YmdHis') . Str::random(10);
    }

    private function generateQRData(PaymentSession $session, User $user): string
    {
        $data = [
            'v' => 1, // Version
            'sid' => $session->session_id,
            'wid' => encrypt($user->wallet_id),
            'exp' => $session->expires_at->timestamp,
            'sig' => $this->generateSignature($session)
        ];

        return base64_encode(json_encode($data));
    }

    private function generateSignature(PaymentSession $session): string
    {
        $data = $session->session_id . $session->user_id . $session->expires_at;
        return hash_hmac('sha256', $data, config('app.key'));
    }

    private function cacheSession(PaymentSession $session): void
    {
        Cache::put(
            "payment:session:{$session->session_id}",
            $session,
            $session->expires_at
        );
    }

    private function getActiveSession(int $userId): ?PaymentSession
    {
        return PaymentSession::where('user_id', $userId)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
    }

    public function generateQRCode(string $data): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(400),
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);
        return $writer->writeString($data);
    }
}
```

```php
// app/Services/TransactionService.php
<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Merchant;
use App\Events\PaymentCompleted;
use App\Events\PaymentFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TransactionService
{
    public function __construct(
        private PaymentSessionService $sessionService,
        private BalanceService $balanceService,
        private NotificationService $notificationService
    ) {}

    public function createTransaction(
        string $sessionId, 
        int $merchantId, 
        float $amount,
        array $items = []
    ): Transaction {
        // Validate session
        $validation = $this->sessionService->validateSession($sessionId);
        if (!$validation['valid']) {
            throw new \Exception($validation['error']);
        }

        $session = $validation['session'];

        // Check for duplicate transaction
        $existing = Transaction::where('session_id', $sessionId)
            ->where('merchant_id', $merchantId)
            ->where('status', '!=', 'failed')
            ->first();

        if ($existing) {
            throw new \Exception('Duplicate transaction');
        }

        // Create transaction
        $transaction = Transaction::create([
            'session_id' => $sessionId,
            'user_id' => $session->user_id,
            'merchant_id' => $merchantId,
            'amount' => $amount,
            'status' => 'pending',
            'metadata' => [
                'device_id' => $session->device_id,
                'items' => $items
            ]
        ]);

        // Create transaction items if provided
        foreach ($items as $item) {
            $transaction->items()->create([
                'name' => $item['name'],
                'quantity' => $item['quantity'] ?? 1,
                'price' => $item['price'],
                'total' => $item['total'] ?? $item['price'] * ($item['quantity'] ?? 1)
            ]);
        }

        // Cache transaction state
        Cache::put(
            "payment:transaction:{$transaction->transaction_id}",
            $transaction,
            now()->addMinutes(10)
        );

        return $transaction;
    }

    public function confirmTransaction(
        string $transactionId,
        User $user,
        string $authMethod,
        string $authToken
    ): array {
        $transaction = $this->getTransaction($transactionId);

        if (!$transaction) {
            return ['success' => false, 'message' => 'Transaction not found'];
        }

        if ($transaction->user_id !== $user->id) {
            return ['success' => false, 'message' => 'Unauthorized'];
        }

        if (!$transaction->canBeConfirmed()) {
            return ['success' => false, 'message' => 'Transaction expired or already processed'];
        }

        // Verify authentication
        if (!$this->verifyAuthentication($user, $authMethod, $authToken)) {
            return ['success' => false, 'message' => 'Authentication failed'];
        }

        // Process payment
        try {
            DB::beginTransaction();

            // Check balance
            if (!$user->hasEnoughBalance($transaction->amount)) {
                throw new \Exception('Insufficient balance');
            }

            // Check daily limit
            if (!$user->canSpend($transaction->amount)) {
                throw new \Exception('Daily limit exceeded');
            }

            // Update transaction status
            $transaction->update([
                'status' => 'processing',
                'confirmed_at' => now()
            ]);

            // Process balance transfer
            $this->balanceService->processPayment(
                $user,
                $transaction->merchant,
                $transaction->amount,
                $transaction->transaction_id
            );

            // Update transaction to completed
            $transaction->update([
                'status' => 'completed',
                'completed_at' => now()
            ]);

            // Update session status
            $transaction->session()->update(['status' => 'used']);

            DB::commit();

            // Fire events
            event(new PaymentCompleted($transaction));

            // Send notifications
            $this->notificationService->sendPaymentSuccess($transaction);

            return [
                'success' => true,
                'transaction' => $transaction->fresh()
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            $transaction->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => $e->getMessage()
            ]);

            event(new PaymentFailed($transaction));

            Log::error('Payment processing failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function getTransaction(string $transactionId): ?Transaction
    {
        // Try cache first
        $cached = Cache::get("payment:transaction:{$transactionId}");
        if ($cached) {
            return $cached;
        }

        return Transaction::where('transaction_id', $transactionId)->first();
    }

    private function verifyAuthentication(User $user, string $method, string $token): bool
    {
        switch ($method) {
            case 'pin':
                return hash_equals($user->pin, hash('sha256', $token));
            
            case 'biometric':
                // Verify biometric token with device
                return $this->verifyBiometricToken($user, $token);
            
            case 'password':
                return password_verify($token, $user->password);
            
            default:
                return false;
        }
    }

    private function verifyBiometricToken(User $user, string $token): bool
    {
        // Implement biometric verification logic
        // This would typically involve verifying a JWT token from the device
        return true; // Placeholder
    }
}
```

### 4.4 Jobs

```php
// app/Jobs/ProcessPaymentNotification.php
<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPaymentNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 30;

    public function __construct(
        public Transaction $transaction
    ) {}

    public function handle(NotificationService $notificationService): void
    {
        try {
            // Send push notification
            $sent = $notificationService->sendPaymentRequest($this->transaction);

            if (!$sent) {
                // Fallback to SMS
                $notificationService->sendSMSFallback($this->transaction);
            }

            // Store for polling
            Cache::put(
                "payment:pending:{$this->transaction->user_id}",
                $this->transaction,
                now()->addMinutes(2)
            );

        } catch (\Exception $e) {
            Log::error('Failed to send payment notification', [
                'transaction_id' => $this->transaction->transaction_id,
                'error' => $e->getMessage()
            ]);

            if ($this->attempts() >= $this->tries) {
                // Mark transaction as failed
                $this->transaction->update([
                    'status' => 'failed',
                    'failure_reason' => 'Notification delivery failed'
                ]);
            }

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Payment notification job failed', [
            'transaction_id' => $this->transaction->transaction_id,
            'error' => $exception->getMessage()
        ]);
    }
}
```

```php
// app/Jobs/ProcessTransactionTimeout.php
<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessTransactionTimeout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Find expired pending transactions
        $expiredTransactions = Transaction::where('status', 'pending')
            ->where('initiated_at', '<', now()->subMinutes(2))
            ->get();

        foreach ($expiredTransactions as $transaction) {
            $transaction->update([
                'status' => 'timeout',
                'failed_at' => now(),
                'failure_reason' => 'Transaction timeout'
            ]);

            // Notify both parties
            app(NotificationService::class)->sendTimeoutNotification($transaction);
        }
    }
}
```

### 4.5 Middleware

```php
// app/Http/Middleware/CheckDeviceBinding.php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CheckDeviceBinding
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $deviceId = $request->header('X-Device-ID');

        if (!$deviceId) {
            return response()->json([
                'success' => false,
                'message' => 'Device ID required'
            ], 400);
        }

        // Check device binding
        $boundDevice = Cache::get("payment:device:{$user->id}");
        
        if ($boundDevice && $boundDevice !== $deviceId) {
            return response()->json([
                'success' => false,
                'message' => 'Device mismatch. Please login again.'
            ], 403);
        }

        // Update device binding
        Cache::put("payment:device:{$user->id}", $deviceId, now()->addHour());

        return $next($request);
    }
}
```

```php
// app/Http/Middleware/RateLimitPayment.php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class RateLimitPayment
{
    public function handle(Request $request, Closure $next)
    {
        $key = 'payment:' . $request->user()->id;
        
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many payment attempts. Please try again later.'
            ], 429);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
```

---

## 5. Frontend Implementation (Vue 3)

### 5.1 Customer App Structure

```
customer-app/
├── src/
│   ├── components/
│   │   ├── QRCode/
│   │   │   ├── QRDisplay.vue
│   │   │   └── QRTimer.vue
│   │   ├── Payment/
│   │   │   ├── PaymentConfirmation.vue
│   │   │   ├── PaymentSuccess.vue
│   │   │   └── PaymentError.vue
│   │   └── Auth/
│   │       ├── PinInput.vue
│   │       └── BiometricAuth.vue
│   ├── composables/
│   │   ├── usePayment.js
│   │   ├── useAuth.js
│   │   └── useNotifications.js
│   ├── stores/
│   │   ├── auth.js
│   │   ├── payment.js
│   │   └── notification.js
│   ├── services/
│   │   ├── api.js
│   │   ├── websocket.js
│   │   └── push.js
│   └── views/
│       ├── Home.vue
│       ├── Payment.vue
│       └── History.vue
```

### 5.2 Core Components

```vue
<!-- src/components/QRCode/QRDisplay.vue -->
<template>
  <div class="qr-display">
    <div class="qr-header">
      <h2>Scan to Pay</h2>
      <QRTimer :expires-at="session.expires_at" @expired="onExpired" />
    </div>
    
    <div class="qr-container" :class="{ expired: isExpired }">
      <div v-if="!isExpired" class="qr-code">
        <qrcode-vue 
          :value="qrData" 
          :size="300"
          level="H"
          :margin="2"
        />
        <div class="qr-overlay" v-if="loading">
          <Spinner />
        </div>
      </div>
      
      <div v-else class="expired-message">
        <Icon name="clock" size="48" />
        <p>QR Code Expired</p>
        <Button @click="regenerate" variant="primary">
          Generate New QR
        </Button>
      </div>
    </div>
    
    <div class="qr-info">
      <div class="info-item">
        <Icon name="shield" />
        <span>Secure Payment</span>
      </div>
      <div class="info-item">
        <Icon name="clock" />
        <span>Valid for 5 minutes</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import QrcodeVue from 'qrcode.vue'
import { usePayment } from '@/composables/usePayment'
import { useRouter } from 'vue-router'
import QRTimer from './QRTimer.vue'

const props = defineProps({
  session: {
    type: Object,
    required: true
  }
})

const emit = defineEmits(['regenerate'])

const { regenerateSession } = usePayment()
const router = useRouter()

const loading = ref(false)
const isExpired = ref(false)

const qrData = computed(() => props.session.qr_data)

const onExpired = () => {
  isExpired.value = true
}

const regenerate = async () => {
  loading.value = true
  try {
    await regenerateSession()
    isExpired.value = false
  } catch (error) {
    console.error('Failed to regenerate QR:', error)
  } finally {
    loading.value = false
  }
}

// Auto-brightness for QR scanning
onMounted(() => {
  if ('screen' in window && 'orientation' in window.screen) {
    window.screen.orientation.lock('portrait').catch(() => {})
  }
  
  // Increase screen brightness
  if (navigator.getBattery) {
    navigator.getBattery().then(battery => {
      // Store original brightness
      sessionStorage.setItem('originalBrightness', window.screen.brightness)
      // Set to maximum brightness
      if (window.screen.brightness) {
        window.screen.brightness = 1.0
      }
    })
  }
})

onUnmounted(() => {
  // Restore original brightness
  const originalBrightness = sessionStorage.getItem('originalBrightness')
  if (originalBrightness && window.screen.brightness) {
    window.screen.brightness = parseFloat(originalBrightness)
  }
})
</script>

<style scoped>
.qr-display {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 20px;
  background: white;
  border-radius: 20px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
}

.qr-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
  margin-bottom: 20px;
}

.qr-container {
  position: relative;
  padding: 20px;
  background: white;
  border-radius: 16px;
  transition: all 0.3s ease;
}

.qr-container.expired {
  opacity: 0.5;
}

.qr-code {
  position: relative;
}

.qr-overlay {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.9);
  border-radius: 8px;
}

.expired-message {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  padding: 40px;
  text-align: center;
}

.qr-info {
  display: flex;
  gap: 24px;
  margin-top: 24px;
}

.info-item {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #666;
  font-size: 14px;
}
</style>
```

```vue
<!-- src/components/Payment/PaymentConfirmation.vue -->
<template>
  <div class="payment-confirmation">
    <div class="merchant-info">
      <img 
        :src="transaction.merchant.logo" 
        :alt="transaction.merchant.name"
        class="merchant-logo"
      >
      <h3>{{ transaction.merchant.name }}</h3>
      <div class="verified-badge" v-if="transaction.merchant.verified">
        <Icon name="check-circle" />
        Verified Merchant
      </div>
    </div>

    <div class="amount-display">
      <span class="currency">$</span>
      <span class="amount">{{ formattedAmount }}</span>
    </div>

    <div class="transaction-details" v-if="transaction.items?.length">
      <h4>Items</h4>
      <div class="item" v-for="item in transaction.items" :key="item.id">
        <span>{{ item.name }} x{{ item.quantity }}</span>
        <span>${{ item.total }}</span>
      </div>
    </div>

    <div class="action-buttons">
      <Button 
        @click="confirmPayment" 
        variant="primary" 
        size="large"
        :loading="confirming"
        :disabled="timeRemaining <= 0"
      >
        <Icon name="lock" />
        Confirm Payment
      </Button>
      
      <Button 
        @click="rejectPayment" 
        variant="secondary" 
        size="large"
        :disabled="confirming"
      >
        Reject
      </Button>
    </div>

    <div class="timer-warning" v-if="timeRemaining < 30">
      <Icon name="clock" />
      <span>{{ timeRemaining }} seconds remaining</span>
    </div>

    <!-- Auth Modal -->
    <Modal v-model="showAuthModal" :close-on-backdrop="false">
      <template #header>
        <h3>Authenticate Payment</h3>
      </template>
      
      <div class="auth-methods">
        <button 
          v-if="biometricAvailable" 
          @click="authenticateWithBiometric"
          class="auth-method"
        >
          <Icon name="fingerprint" size="32" />
          <span>Use Biometric</span>
        </button>
        
        <div class="pin-input" v-else>
          <h4>Enter PIN</h4>
          <PinInput 
            v-model="pin" 
            :length="6"
            @complete="authenticateWithPin"
          />
        </div>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { usePayment } from '@/composables/usePayment'
import { useAuth } from '@/composables/useAuth'
import { useRouter } from 'vue-router'
import PinInput from '@/components/Auth/PinInput.vue'

const props = defineProps({
  transaction: {
    type: Object,
    required: true
  }
})

const { confirmTransaction, rejectTransaction } = usePayment()
const { checkBiometric, authenticateBiometric } = useAuth()
const router = useRouter()

const confirming = ref(false)
const showAuthModal = ref(false)
const pin = ref('')
const timeRemaining = ref(120)
const biometricAvailable = ref(false)

const formattedAmount = computed(() => {
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(props.transaction.amount)
})

let timer = null

const startTimer = () => {
  timer = setInterval(() => {
    timeRemaining.value--
    if (timeRemaining.value <= 0) {
      clearInterval(timer)
      handleTimeout()
    }
  }, 1000)
}

const handleTimeout = () => {
  router.push({
    name: 'payment-error',
    params: { 
      error: 'Payment request expired'
    }
  })
}

const confirmPayment = () => {
  showAuthModal.value = true
}

const authenticateWithBiometric = async () => {
  try {
    const token = await authenticateBiometric()
    await processPayment('biometric', token)
  } catch (error) {
    console.error('Biometric auth failed:', error)
    // Fallback to PIN
    biometricAvailable.value = false
  }
}

const authenticateWithPin = async () => {
  await processPayment('pin', pin.value)
}

const processPayment = async (method, token) => {
  confirming.value = true
  showAuthModal.value = false
  
  try {
    const result = await confirmTransaction({
      transaction_id: props.transaction.transaction_id,
      auth_method: method,
      auth_token: token
    })
    
    router.push({
      name: 'payment-success',
      params: { 
        transactionId: result.transaction_id 
      }
    })
  } catch (error) {
    router.push({
      name: 'payment-error',
      params: { 
        error: error.message || 'Payment failed'
      }
    })
  } finally {
    confirming.value = false
  }
}

const rejectPayment = async () => {
  try {
    await rejectTransaction(props.transaction.transaction_id)
    router.push({ name: 'home' })
  } catch (error) {
    console.error('Failed to reject payment:', error)
  }
}

onMounted(async () => {
  startTimer()
  biometricAvailable.value = await checkBiometric()
})

onUnmounted(() => {
  if (timer) {
    clearInterval(timer)
  }
})
</script>

<style scoped>
.payment-confirmation {
  display: flex;
  flex-direction: column;
  gap: 24px;
  padding: 20px;
  max-width: 400px;
  margin: 0 auto;
}

.merchant-info {
  text-align: center;
  padding: 24px;
  background: #f8f9fa;
  border-radius: 16px;
}

.merchant-logo {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  margin-bottom: 16px;
}

.verified-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #4CAF50;
  font-size: 14px;
  margin-top: 8px;
}

.amount-display {
  text-align: center;
  padding: 32px 0;
}

.currency {
  font-size: 32px;
  color: #666;
  vertical-align: top;
}

.amount {
  font-size: 64px;
  font-weight: 700;
  color: #333;
}

.transaction-details {
  background: #f8f9fa;
  border-radius: 12px;
  padding: 16px;
}

.transaction-details h4 {
  margin: 0 0 12px 0;
  color: #666;
  font-size: 14px;
  text-transform: uppercase;
}

.item {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid #e0e0e0;
}

.item:last-child {
  border-bottom: none;
}

.action-buttons {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.timer-warning {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px;
  background: #FFF3CD;
  color: #856404;
  border-radius: 8px;
  font-weight: 500;
}

.auth-methods {
  padding: 24px;
}

.auth-method {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 24px;
  background: #f8f9fa;
  border: 2px solid #e0e0e0;
  border-radius: 12px;
  cursor: pointer;
  transition: all 0.3s ease;
}

.auth-method:hover {
  border-color: var(--primary-color);
  background: #f0f0f0;
}

.pin-input {
  text-align: center;
}

.pin-input h4 {
  margin-bottom: 20px;
  color: #333;
}
</style>
```

### 5.3 Composables

```javascript
// src/composables/usePayment.js
import { ref, computed } from 'vue'
import { usePaymentStore } from '@/stores/payment'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

export function usePayment() {
  const paymentStore = usePaymentStore()
  const authStore = useAuthStore()
  
  const loading = ref(false)
  const error = ref(null)

  const createSession = async () => {
    loading.value = true
    error.value = null
    
    try {
      const response = await api.post('/payment/session', {
        device_id: authStore.deviceId
      })
      
      paymentStore.setCurrentSession(response.data.data)
      return response.data.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create session'
      throw err
    } finally {
      loading.value = false
    }
  }

  const confirmTransaction = async ({ transaction_id, auth_method, auth_token }) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await api.post('/payment/confirm', {
        transaction_id,
        auth_method,
        auth_token
      })
      
      paymentStore.clearCurrentTransaction()
      return response.data.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Payment failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  const getTransactionStatus = async (transactionId) => {
    try {
      const response = await api.get(`/payment/transaction/${transactionId}`)
      return response.data.data
    } catch (err) {
      console.error('Failed to get transaction status:', err)
      return null
    }
  }

  const regenerateSession = async () => {
    paymentStore.clearCurrentSession()
    return createSession()
  }

  return {
    loading: computed(() => loading.value),
    error: computed(() => error.value),
    currentSession: computed(() => paymentStore.currentSession),
    currentTransaction: computed(() => paymentStore.currentTransaction),
    createSession,
    confirmTransaction,
    getTransactionStatus,
    regenerateSession
  }
}
```

```javascript
// src/composables/useNotifications.js
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { usePaymentStore } from '@/stores/payment'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

export function useNotifications() {
  const router = useRouter()
  const paymentStore = usePaymentStore()
  
  let echo = null
  let channel = null

  const initializeWebSocket = (userId) => {
    window.Pusher = Pusher

    echo = new Echo({
      broadcaster: 'pusher',
      key: import.meta.env.VITE_PUSHER_APP_KEY,
      cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
      forceTLS: true,
      auth: {
        headers: {
          Authorization: `Bearer ${localStorage.getItem('token')}`
        }
      }
    })

    // Subscribe to private channel
    channel = echo.private(`payment.${userId}`)
    
    // Listen for payment requests
    channel.listen('.payment.request', (data) => {
      handlePaymentRequest(data)
    })
    
    // Listen for payment status updates
    channel.listen('.payment.status', (data) => {
      handlePaymentStatus(data)
    })
  }

  const handlePaymentRequest = (data) => {
    paymentStore.setCurrentTransaction(data.transaction)
    
    // Show notification
    if ('Notification' in window && Notification.permission === 'granted') {
      new Notification('Payment Request', {
        body: `${data.merchant.name} - $${data.amount}`,
        icon: '/icon-192x192.png',
        tag: 'payment-request',
        requireInteraction: true
      })
    }
    
    // Navigate to confirmation screen
    router.push({
      name: 'payment-confirmation',
      params: { transactionId: data.transaction.transaction_id }
    })
  }

  const handlePaymentStatus = (data) => {
    if (data.status === 'completed') {
      router.push({
        name: 'payment-success',
        params: { transactionId: data.transaction_id }
      })
    } else if (data.status === 'failed') {
      router.push({
        name: 'payment-error',
        params: { error: data.reason }
      })
    }
  }

  const disconnect = () => {
    if (channel) {
      echo.leave(`payment.${userId}`)
      channel = null
    }
    if (echo) {
      echo.disconnect()
      echo = null
    }
  }

  onMounted(() => {
    // Request notification permission
    if ('Notification' in window && Notification.permission === 'default') {
      Notification.requestPermission()
    }
  })

  onUnmounted(() => {
    disconnect()
  })

  return {
    initializeWebSocket,
    disconnect
  }
}
```

### 5.4 Merchant App Scanner

```vue
<!-- merchant-app/src/components/Scanner/QRScanner.vue -->
<template>
  <div class="qr-scanner">
    <div class="scanner-header">
      <h2>Scan Customer QR</h2>
      <button @click="$emit('close')" class="close-btn">
        <Icon name="x" />
      </button>
    </div>

    <div class="scanner-container">
      <video ref="videoEl" class="scanner-video"></video>
      <div class="scanner-overlay">
        <div class="scanner-frame"></div>
      </div>
    </div>

    <div class="scanner-actions">
      <button @click="toggleFlash" class="action-btn" v-if="hasFlash">
        <Icon :name="flashOn ? 'flash' : 'flash-off'" />
      </button>
      <button @click="switchCamera" class="action-btn" v-if="hasMultipleCameras">
        <Icon name="camera-switch" />
      </button>
    </div>

    <!-- Amount Input Modal -->
    <Modal v-model="showAmountModal" :close-on-backdrop="false">
      <template #header>
        <h3>Enter Amount</h3>
      </template>
      
      <div class="amount-input">
        <div class="customer-info" v-if="customerInfo">
          <img :src="customerInfo.avatar" :alt="customerInfo.name">
          <div>
            <h4>{{ customerInfo.name }}</h4>
            <span class="member-badge" v-if="customerInfo.memberLevel">
              {{ customerInfo.memberLevel }} Member
            </span>
          </div>
        </div>

        <div class="amount-display">
          <span class="currency">$</span>
          <input 
            v-model="amount" 
            type="number" 
            step="0.01"
            placeholder="0.00"
            @keyup.enter="submitPayment"
            class="amount-input-field"
            ref="amountInput"
          >
        </div>

        <div class="preset-amounts">
          <button 
            v-for="preset in presetAmounts" 
            :key="preset"
            @click="amount = preset"
            class="preset-btn"
          >
            ${{ preset }}
          </button>
        </div>

        <div class="action-buttons">
          <Button 
            @click="submitPayment" 
            variant="primary" 
            size="large"
            :disabled="!amount || amount <= 0"
            :loading="submitting"
          >
            Request Payment
          </Button>
          <Button 
            @click="cancelPayment" 
            variant="secondary" 
            size="large"
          >
            Cancel
          </Button>
        </div>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import QrScanner from 'qr-scanner'
import { useMerchantPayment } from '@/composables/useMerchantPayment'
import { useRouter } from 'vue-router'

const emit = defineEmits(['close', 'scanned'])

const { validateQRCode, initiatePayment } = useMerchantPayment()
const router = useRouter()

const videoEl = ref(null)
const scanner = ref(null)
const flashOn = ref(false)
const hasFlash = ref(false)
const hasMultipleCameras = ref(false)
const showAmountModal = ref(false)
const amount = ref('')
const amountInput = ref(null)
const submitting = ref(false)
const currentSession = ref(null)
const customerInfo = ref(null)

const presetAmounts = [10, 20, 50, 100]

const initScanner = async () => {
  try {
    // Check camera permissions
    const hasCamera = await QrScanner.hasCamera()
    if (!hasCamera) {
      throw new Error('No camera found')
    }

    scanner.value = new QrScanner(
      videoEl.value,
      result => handleScan(result),
      {
        highlightScanRegion: true,
        highlightCodeOutline: true,
      }
    )

    await scanner.value.start()

    // Check for flash support
    hasFlash.value = await scanner.value.hasFlash()
    
    // Check for multiple cameras
    const cameras = await QrScanner.listCameras()
    hasMultipleCameras.value = cameras.length > 1

  } catch (error) {
    console.error('Scanner initialization failed:', error)
    alert('Failed to access camera. Please check permissions.')
  }
}

const handleScan = async (result) => {
  // Stop scanning temporarily
  scanner.value.pause()
  
  try {
    // Decode and validate QR data
    const qrData = JSON.parse(atob(result.data))
    
    const validation = await validateQRCode(qrData)
    
    if (validation.valid) {
      currentSession.value = validation.session
      customerInfo.value = validation.customerInfo
      showAmountModal.value = true
      
      // Auto-focus amount input
      await nextTick()
      amountInput.value?.focus()
    } else {
      alert(validation.error || 'Invalid QR code')
      scanner.value.start()
    }
  } catch (error) {
    console.error('QR validation failed:', error)
    alert('Invalid QR code format')
    scanner.value.start()
  }
}

const submitPayment = async () => {
  if (!amount.value || amount.value <= 0) return
  
  submitting.value = true
  
  try {
    const result = await initiatePayment({
      session_id: currentSession.value.session_id,
      amount: parseFloat(amount.value),
      items: [] // Add items if needed
    })
    
    // Navigate to waiting screen
    router.push({
      name: 'payment-waiting',
      params: { 
        transactionId: result.transaction_id 
      }
    })
    
  } catch (error) {
    alert(error.message || 'Failed to initiate payment')
  } finally {
    submitting.value = false
  }
}

const cancelPayment = () => {
  showAmountModal.value = false
  amount.value = ''
  currentSession.value = null
  customerInfo.value = null
  scanner.value.start()
}

const toggleFlash = async () => {
  if (scanner.value && hasFlash.value) {
    flashOn.value = !flashOn.value
    await scanner.value.setFlash(flashOn.value)
  }
}

const switchCamera = async () => {
  if (scanner.value && hasMultipleCameras.value) {
    await scanner.value.setCamera('environment')
  }
}

onMounted(() => {
  initScanner()
})

onUnmounted(() => {
  if (scanner.value) {
    scanner.value.destroy()
  }
})
</script>

<style scoped>
.qr-scanner {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: #000;
  z-index: 1000;
}

.scanner-header {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px;
  background: linear-gradient(to bottom, rgba(0,0,0,0.8), transparent);
  z-index: 10;
}

.scanner-header h2 {
  color: white;
  margin: 0;
}

.close-btn {
  background: rgba(255,255,255,0.2);
  border: none;
  color: white;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

.scanner-container {
  position: relative;
  width: 100%;
  height: 100%;
}

.scanner-video {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.scanner-overlay {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.scanner-frame {
  width: 280px;
  height: 280px;
  border: 3px solid #4CAF50;
  border-radius: 20px;
  box-shadow: 0 0 0 999px rgba(0,0,0,0.5);
}

.scanner-actions {
  position: absolute;
  bottom: 40px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  gap: 20px;
  z-index: 10;
}

.action-btn {
  background: rgba(255,255,255,0.2);
  border: none;
  color: white;
  width: 60px;
  height: 60px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  backdrop-filter: blur(10px);
}

.amount-input {
  padding: 20px;
}

.customer-info {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 16px;
  background: #f8f9fa;
  border-radius: 12px;
  margin-bottom: 24px;
}

.customer-info img {
  width: 48px;
  height: 48px;
  border-radius: 50%;
}

.member-badge {
  display: inline-block;
  padding: 4px 8px;
  background: #4CAF50;
  color: white;
  border-radius: 4px;
  font-size: 12px;
  margin-top: 4px;
}

.amount-display {
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 32px 0;
}

.currency {
  font-size: 32px;
  color: #666;
  margin-right: 8px;
}

.amount-input-field {
  font-size: 48px;
  font-weight: 700;
  border: none;
  outline: none;
  text-align: center;
  width: 200px;
}

.preset-amounts {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 32px;
}

.preset-btn {
  padding: 12px;
  border: 2px solid #e0e0e0;
  background: white;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
}

.preset-btn:hover {
  border-color: #4CAF50;
  color: #4CAF50;
}
</style>
```

---

## 6. Real-time Features

### 6.1 Laravel Broadcasting

```php
// config/broadcasting.php
'connections' => [
    'pusher' => [
        'driver' => 'pusher',
        'key' => env('PUSHER_APP_KEY'),
        'secret' => env('PUSHER_APP_SECRET'),
        'app_id' => env('PUSHER_APP_ID'),
        'options' => [
            'cluster' => env('PUSHER_APP_CLUSTER'),
            'useTLS' => true,
            'encrypted' => true,
        ],
    ],
],
```

```php
// app/Events/PaymentRequested.php
<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentRequested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Transaction $transaction
    ) {}

    public function broadcastOn()
    {
        return new PrivateChannel('payment.' . $this->transaction->user_id);
    }

    public function broadcastAs()
    {
        return 'payment.request';
    }

    public function broadcastWith()
    {
        return [
            'transaction' => [
                'transaction_id' => $this->transaction->transaction_id,
                'amount' => $this->transaction->amount,
                'currency' => $this->transaction->currency,
                'items' => $this->transaction->items,
                'expires_at' => $this->transaction->initiated_at->addMinutes(2)
            ],
            'merchant' => [
                'id' => $this->transaction->merchant->id,
                'name' => $this->transaction->merchant->business_name,
                'logo' => $this->transaction->merchant->logo_url,
                'verified' => $this->transaction->merchant->verified
            ]
        ];
    }
}
```

### 6.2 WebSocket Connection Management

```javascript
// src/services/websocket.js
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

class WebSocketService {
  constructor() {
    this.echo = null
    this.channels = new Map()
  }

  connect(token) {
    if (this.echo) {
      return this.echo
    }

    window.Pusher = Pusher

    this.echo = new Echo({
      broadcaster: 'pusher',
      key: import.meta.env.VITE_PUSHER_APP_KEY,
      cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
      forceTLS: true,
      auth: {
        headers: {
          Authorization: `Bearer ${token}`
        }
      }
    })

    return this.echo
  }

  subscribeToPaymentChannel(userId, callbacks) {
    const channelName = `payment.${userId}`
    
    if (this.channels.has(channelName)) {
      return this.channels.get(channelName)
    }

    const channel = this.echo.private(channelName)
    
    // Payment request listener
    if (callbacks.onPaymentRequest) {
      channel.listen('.payment.request', callbacks.onPaymentRequest)
    }
    
    // Payment status listener
    if (callbacks.onPaymentStatus) {
      channel.listen('.payment.status', callbacks.onPaymentStatus)
    }
    
    // Connection status listeners
    channel.listen('.pusher:subscription_succeeded', () => {
      console.log('Connected to payment channel')
      callbacks.onConnected?.()
    })
    
    channel.listen('.pusher:subscription_error', (error) => {
      console.error('Payment channel subscription error:', error)
      callbacks.onError?.(error)
    })

    this.channels.set(channelName, channel)
    return channel
  }

  unsubscribe(channelName) {
    if (this.channels.has(channelName)) {
      this.echo.leave(channelName)
      this.channels.delete(channelName)
    }
  }

  disconnect() {
    if (this.echo) {
      this.channels.forEach((channel, name) => {
        this.echo.leave(name)
      })
      this.channels.clear()
      this.echo.disconnect()
      this.echo = null
    }
  }
}

export default new WebSocketService()
```

---

## 7. Security Implementation

### 7.1 API Authentication

```php
// app/Http/Kernel.php
protected $middlewareGroups = [
    'api' => [
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        'throttle:api',
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \App\Http\Middleware\CheckDeviceBinding::class,
    ],
];

protected $routeMiddleware = [
    'auth:sanctum' => \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    'device.check' => \App\Http\Middleware\CheckDeviceBinding::class,
    'payment.limit' => \App\Http\Middleware\RateLimitPayment::class,
];
```

### 7.2 Encryption Service

```php
// app/Services/EncryptionService.php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;

class EncryptionService
{
    private $key;

    public function __construct()
    {
        $this->key = config('app.encryption_key');
    }

    public function encryptSensitiveData(array $data): string
    {
        return Crypt::encryptString(json_encode($data));
    }

    public function decryptSensitiveData(string $encrypted): array
    {
        return json_decode(Crypt::decryptString($encrypted), true);
    }

    public function hashPin(string $pin): string
    {
        return hash_hmac('sha256', $pin, $this->key);
    }

    public function verifyPin(string $pin, string $hash): bool
    {
        return hash_equals($hash, $this->hashPin($pin));
    }

    public function generateSecureToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }
}
```

### 7.3 Request Validation

```php
// app/Http/Requests/CreateSessionRequest.php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateSessionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'device_id' => 'required|string|max:255',
            'location' => 'sometimes|array',
            'location.latitude' => 'required_with:location|numeric|between:-90,90',
            'location.longitude' => 'required_with:location|numeric|between:-180,180',
        ];
    }

    public function messages()
    {
        return [
            'device_id.required' => 'Device ID is required for security',
            'location.latitude.between' => 'Invalid latitude coordinates',
            'location.longitude.between' => 'Invalid longitude coordinates',
        ];
    }
}
```

---

## 8. Exception Handling

### 8.1 Global Exception Handler

```php
// app/Exceptions/Handler.php
<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontReport = [
        ValidationException::class,
    ];

    public function register()
    {
        $this->reportable(function (Throwable $e) {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        });

        $this->renderable(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found',
                ], 404);
            }
        });

        $this->renderable(function (ModelNotFoundException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Record not found',
                ], 404);
            }
        });

        $this->renderable(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }
        });
    }

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        return redirect()->guest(route('login'));
    }
}
```

### 8.2 Service Exception Handling

```php
// app/Services/PaymentExceptionHandler.php
<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\TransactionExpiredException;
use Illuminate\Support\Facades\Log;

trait PaymentExceptionHandler
{
    protected function handlePaymentException(\Exception $e, string $context)
    {
        Log::error("Payment error in {$context}", [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'user_id' => auth()->id(),
        ]);

        if ($e instanceof InsufficientBalanceException) {
            throw new PaymentException('Insufficient balance', 400);
        }

        if ($e instanceof TransactionExpiredException) {
            throw new PaymentException('Transaction expired', 410);
        }

        if ($e instanceof \Illuminate\Database\QueryException) {
            throw new PaymentException('Database error occurred', 500);
        }

        throw new PaymentException('Payment processing failed', 500);
    }

    protected function withRetry(callable $operation, int $maxAttempts = 3)
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            try {
                return $operation();
            } catch (\Exception $e) {
                $lastException = $e;
                $attempts++;
                
                if ($attempts < $maxAttempts) {
                    sleep(pow(2, $attempts)); // Exponential backoff
                }
            }
        }

        throw $lastException;
    }
}
```

---

## 9. Testing Strategy

### 9.1 Unit Tests

```php
// tests/Unit/Services/PaymentSessionServiceTest.php
<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\PaymentSessionService;
use App\Models\User;
use App\Models\PaymentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PaymentSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentSessionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PaymentSessionService::class);
    }

    public function test_creates_payment_session()
    {
        $user = User::factory()->create();
        $data = ['device_id' => 'test-device-123'];

        $session = $this->service->createSession($user, $data);

        $this->assertInstanceOf(PaymentSession::class, $session);
        $this->assertEquals($user->id, $session->user_id);
        $this->assertEquals('active', $session->status);
        $this->assertNotNull($session->qr_data);
    }

    public function test_prevents_duplicate_active_sessions()
    {
        $user = User::factory()->create();
        $data = ['device_id' => 'test-device-123'];

        $session1 = $this->service->createSession($user, $data);
        $session2 = $this->service->createSession($user, $data);

        $this->assertEquals($session1->id, $session2->id);
    }

    public function test_validates_session_correctly()
    {
        $session = PaymentSession::factory()->create([
            'status' => 'active',
            'expires_at' => now()->addMinutes(5)
        ]);

        $validation = $this->service->validateSession($session->session_id);

        $this->assertTrue($validation['valid']);
        $this->assertEquals($session->id, $validation['session']['id']);
    }

    public function test_rejects_expired_session()
    {
        $session = PaymentSession::factory()->create([
            'status' => 'active',
            'expires_at' => now()->subMinute()
        ]);

        $validation = $this->service->validateSession($session->session_id);

        $this->assertFalse($validation['valid']);
        $this->assertEquals('Session expired', $validation['error']);
    }
}
```

### 9.2 Feature Tests

```php
// tests/Feature/PaymentFlowTest.php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Merchant;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_payment_flow()
    {
        // Setup
        $user = User::factory()->create([
            'balance' => 1000,
            'pin' => hash('sha256', '123456')
        ]);
        
        $merchant = Merchant::factory()->create();
        
        Sanctum::actingAs($user);

        // Step 1: Create session
        $response = $this->postJson('/api/payment/session', [
            'device_id' => 'test-device'
        ]);

        $response->assertStatus(201);
        $session = $response->json('data');

        // Step 2: Merchant validates QR
        Sanctum::actingAs($merchant);
        
        $response = $this->postJson('/api/merchant/validate-qr', [
            'qr_data' => json_decode(base64_decode($session['qr_data']), true),
            'merchant_id' => $merchant->id
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('valid'));

        // Step 3: Merchant initiates payment
        $response = $this->postJson('/api/merchant/payment/initiate', [
            'session_id' => $session['session_id'],
            'amount' => 50.00
        ]);

        $response->assertStatus(201);
        $transaction = $response->json('data');

        // Step 4: User confirms payment
        Sanctum::actingAs($user);
        
        $response = $this->postJson('/api/payment/confirm', [
            'transaction_id' => $transaction['transaction_id'],
            'auth_method' => 'pin',
            'auth_token' => '123456'
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));

        // Verify balances
        $user->refresh();
        $this->assertEquals(950, $user->balance);
    }

    public function test_handles_insufficient_balance()
    {
        $user = User::factory()->create([
            'balance' => 10,
            'pin' => hash('sha256', '123456')
        ]);

        // ... Create session and transaction ...

        Sanctum::actingAs($user);
        
        $response = $this->postJson('/api/payment/confirm', [
            'transaction_id' => 'test-transaction',
            'auth_method' => 'pin',
            'auth_token' => '123456'
        ]);

        $response->assertStatus(400);
        $this->assertEquals('Insufficient balance', $response->json('message'));
    }
}
```

### 9.3 Frontend Tests

```javascript
// tests/unit/composables/usePayment.spec.js
import { describe, it, expect, vi } from 'vitest'
import { usePayment } from '@/composables/usePayment'
import api from '@/services/api'

vi.mock('@/services/api')

describe('usePayment', () => {
  it('creates payment session', async () => {
    const mockSession = {
      session_id: 'test-session',
      qr_data: 'mock-qr-data',
      expires_at: '2025-05-31T12:00:00Z'
    }

    api.post.mockResolvedValue({
      data: { data: mockSession }
    })

    const { createSession } = usePayment()
    const result = await createSession()

    expect(api.post).toHaveBeenCalledWith('/payment/session', {
      device_id: expect.any(String)
    })
    expect(result).toEqual(mockSession)
  })

  it('handles payment confirmation', async () => {
    const mockResponse = {
      transaction_id: 'test-transaction',
      status: 'completed'
    }

    api.post.mockResolvedValue({
      data: { data: mockResponse }
    })

    const { confirmTransaction } = usePayment()
    const result = await confirmTransaction({
      transaction_id: 'test-transaction',
      auth_method: 'pin',
      auth_token: '123456'
    })

    expect(api.post).toHaveBeenCalledWith('/payment/confirm', {
      transaction_id: 'test-transaction',
      auth_method: 'pin',
      auth_token: '123456'
    })
    expect(result).toEqual(mockResponse)
  })
})
```

---

## 10. Deployment Guide

### 10.1 Environment Setup

```bash
# Production environment variables
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.payment.com

# Database
DB_CONNECTION=mysql
DB_HOST=rds.amazonaws.com
DB_DATABASE=payment_prod
DB_USERNAME=payment_user
DB_PASSWORD=${DB_PASSWORD}

# Redis Cluster
REDIS_HOST=redis-cluster.aws.com
REDIS_PASSWORD=${REDIS_PASSWORD}
REDIS_PORT=6379

# Queue Configuration
QUEUE_CONNECTION=redis
HORIZON_PREFIX=horizon:
HORIZON_MEMORY_LIMIT=256

# Security
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=app.payment.com
```

### 10.2 Docker Configuration

```dockerfile
# Dockerfile for Laravel
FROM php:8.2-fpm

# Install dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    supervisor

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy application
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Generate optimized autoload files
RUN composer dump-autoload --optimize

# Cache config and routes
RUN php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache

# Set permissions
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# Supervisor configuration
COPY docker/supervisor/laravel-worker.conf /etc/supervisor/conf.d/

CMD ["supervisord", "-n"]
```

### 10.3 CI/CD Pipeline

```yaml
# .github/workflows/deploy.yml
name: Deploy to Production

on:
  push:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          
      - name: Install dependencies
        run: composer install
        
      - name: Run tests
        run: php artisan test
        
  deploy:
    needs: test
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Deploy to server
        uses: appleboy/ssh-action@master
        with:
          host: ${{ secrets.HOST }}
          username: ${{ secrets.USERNAME }}
          key: ${{ secrets.SSH_KEY }}
          script: |
            cd /var/www/payment-system
            git pull origin main
            composer install --no-dev
            php artisan migrate --force
            php artisan config:cache
            php artisan queue:restart
            supervisorctl restart all
```

### 10.4 Monitoring Setup

```php
// config/logging.php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'slack'],
        'ignore_exceptions' => false,
    ],
    
    'slack' => [
        'driver' => 'slack',
        'url' => env('LOG_SLACK_WEBHOOK_URL'),
        'username' => 'Payment System',
        'emoji' => ':boom:',
        'level' => 'error',
    ],
],
```

### 10.5 Performance Optimization

```php
// config/cache.php
'stores' => [
    '
