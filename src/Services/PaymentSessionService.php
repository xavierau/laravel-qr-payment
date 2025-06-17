<?php

namespace XavierAu\LaravelQrPayment\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;
use XavierAu\LaravelQrPayment\Contracts\PaymentSessionServiceInterface;
use XavierAu\LaravelQrPayment\Models\PaymentSession;

class PaymentSessionService implements PaymentSessionServiceInterface
{
    public function createSession(string $customerId, array $options = []): PaymentSession
    {
        $expiryMinutes = config('qr-payment.qr_code.expiry_minutes', 5);
        $currency = $options['currency'] ?? config('qr-payment.transaction.currency', 'USD');
        
        $session = PaymentSession::create([
            'session_id' => $this->generateSecureSessionId(),
            'customer_id' => $customerId,
            'currency' => $currency,
            'status' => PaymentSession::STATUS_PENDING,
            'security_token' => $this->generateSecurityToken(),
            'expires_at' => Carbon::now()->addMinutes($expiryMinutes),
            'metadata' => $options['metadata'] ?? null,
        ]);

        return $session;
    }

    public function getSession(string $sessionId): ?PaymentSession
    {
        return PaymentSession::where('session_id', $sessionId)->first();
    }

    public function isSessionActive(string $sessionId): bool
    {
        $session = $this->getSession($sessionId);
        
        if (!$session) {
            return false;
        }
        
        return $session->isActive();
    }

    public function expireSession(string $sessionId): bool
    {
        $session = $this->getSession($sessionId);
        
        if (!$session) {
            return false;
        }
        
        return $session->markAsExpired();
    }

    public function updateWithMerchantScan(
        string $sessionId, 
        string $merchantId, 
        float $amount, 
        array $metadata = []
    ): PaymentSession {
        $session = $this->getSession($sessionId);
        
        if (!$session) {
            throw new \InvalidArgumentException("Session not found: {$sessionId}");
        }
        
        if (!$session->isActive()) {
            throw new \InvalidArgumentException("Session is not active: {$sessionId}");
        }
        
        $session->markAsScanned($merchantId, $amount, $metadata);
        
        return $session->fresh();
    }

    public function cleanupExpiredSessions(): int
    {
        $count = PaymentSession::where('expires_at', '<=', Carbon::now())
            ->whereIn('status', [PaymentSession::STATUS_PENDING, PaymentSession::STATUS_SCANNED])
            ->count();
            
        PaymentSession::where('expires_at', '<=', Carbon::now())
            ->whereIn('status', [PaymentSession::STATUS_PENDING, PaymentSession::STATUS_SCANNED])
            ->delete();
            
        return $count;
    }

    public function getCustomerActiveSessions(string $customerId): array
    {
        return PaymentSession::where('customer_id', $customerId)
            ->where('status', PaymentSession::STATUS_PENDING)
            ->where('expires_at', '>', Carbon::now())
            ->get()
            ->all();
    }

    public function validateSessionToken(string $sessionId, string $token): bool
    {
        $session = $this->getSession($sessionId);
        
        if (!$session) {
            return false;
        }
        
        return hash_equals($session->security_token, $token);
    }

    /**
     * Generate a cryptographically secure session ID
     */
    private function generateSecureSessionId(): string
    {
        return 'qr_' . Str::uuid()->toString();
    }

    /**
     * Generate a cryptographically secure token for session validation
     */
    private function generateSecurityToken(): string
    {
        return bin2hex(random_bytes(32)); // 64 character hex string
    }
}