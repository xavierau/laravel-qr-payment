<?php

namespace XavierAu\LaravelQrPayment\Tests\Unit\Services;

use Carbon\Carbon;
use XavierAu\LaravelQrPayment\Models\PaymentSession;
use XavierAu\LaravelQrPayment\Services\PaymentSessionService;
use XavierAu\LaravelQrPayment\Tests\TestCase;

class PaymentSessionServiceTest extends TestCase
{
    private PaymentSessionService $sessionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionService = new PaymentSessionService();
    }

    /** @test */
    public function it_can_create_payment_session(): void
    {
        // Arrange
        $customerId = 'customer-123';
        
        // Act
        $session = $this->sessionService->createSession($customerId);
        
        // Assert
        $this->assertInstanceOf(PaymentSession::class, $session);
        $this->assertEquals($customerId, $session->customer_id);
        $this->assertEquals(PaymentSession::STATUS_PENDING, $session->status);
        $this->assertNotEmpty($session->session_id);
        $this->assertNotEmpty($session->security_token);
        $this->assertGreaterThan(Carbon::now(), $session->expires_at);
    }

    /** @test */
    public function it_generates_cryptographically_secure_session_token(): void
    {
        // Arrange
        $customerId = 'customer-456';
        
        // Act
        $session1 = $this->sessionService->createSession($customerId);
        $session2 = $this->sessionService->createSession($customerId);
        
        // Assert
        $this->assertNotEquals($session1->session_id, $session2->session_id);
        $this->assertNotEquals($session1->security_token, $session2->security_token);
        $this->assertGreaterThanOrEqual(32, strlen($session1->security_token)); // Minimum length
    }

    /** @test */
    public function it_sets_correct_expiry_time(): void
    {
        // Arrange
        $customerId = 'customer-789';
        $expectedExpiryMinutes = config('qr-payment.qr_code.expiry_minutes', 5);
        
        // Act
        $session = $this->sessionService->createSession($customerId);
        
        // Assert
        $expectedExpiry = Carbon::now()->addMinutes($expectedExpiryMinutes);
        $this->assertEquals($expectedExpiry->timestamp, $session->expires_at->timestamp, '', 10);
    }

    /** @test */
    public function it_can_retrieve_active_session(): void
    {
        // Arrange
        $customerId = 'customer-retrieve';
        $session = $this->sessionService->createSession($customerId);
        
        // Act
        $retrievedSession = $this->sessionService->getSession($session->session_id);
        
        // Assert
        $this->assertInstanceOf(PaymentSession::class, $retrievedSession);
        $this->assertEquals($session->session_id, $retrievedSession->session_id);
        $this->assertEquals($session->customer_id, $retrievedSession->customer_id);
    }

    /** @test */
    public function it_returns_null_for_non_existent_session(): void
    {
        // Arrange
        $nonExistentSessionId = 'non-existent-session';
        
        // Act
        $session = $this->sessionService->getSession($nonExistentSessionId);
        
        // Assert
        $this->assertNull($session);
    }

    /** @test */
    public function it_validates_active_sessions_correctly(): void
    {
        // Arrange
        $customerId = 'customer-active';
        $activeSession = $this->sessionService->createSession($customerId);
        
        // Act & Assert
        $this->assertTrue($this->sessionService->isSessionActive($activeSession->session_id));
        $this->assertFalse($this->sessionService->isSessionActive('non-existent'));
    }

    /** @test */
    public function it_can_expire_session_manually(): void
    {
        // Arrange
        $customerId = 'customer-expire';
        $session = $this->sessionService->createSession($customerId);
        
        // Act
        $result = $this->sessionService->expireSession($session->session_id);
        
        // Assert
        $this->assertTrue($result);
        $this->assertFalse($this->sessionService->isSessionActive($session->session_id));
        
        $expiredSession = $this->sessionService->getSession($session->session_id);
        $this->assertEquals(PaymentSession::STATUS_EXPIRED, $expiredSession->status);
    }

    /** @test */
    public function it_can_update_session_with_merchant_scan(): void
    {
        // Arrange
        $customerId = 'customer-scan';
        $merchantId = 'merchant-123';
        $amount = 25.50;
        $metadata = ['item' => 'coffee', 'location' => 'store-1'];
        
        $session = $this->sessionService->createSession($customerId);
        
        // Act
        $updatedSession = $this->sessionService->updateWithMerchantScan(
            $session->session_id,
            $merchantId,
            $amount,
            $metadata
        );
        
        // Assert
        $this->assertEquals(PaymentSession::STATUS_SCANNED, $updatedSession->status);
        $this->assertEquals($merchantId, $updatedSession->merchant_id);
        $this->assertEquals($amount, $updatedSession->amount);
        $this->assertEquals($metadata, $updatedSession->metadata);
        $this->assertNotNull($updatedSession->scanned_at);
    }

    /** @test */
    public function it_can_get_customer_active_sessions(): void
    {
        // Arrange
        $customerId = 'customer-multi';
        $session1 = $this->sessionService->createSession($customerId);
        $session2 = $this->sessionService->createSession($customerId);
        $session3 = $this->sessionService->createSession('other-customer');
        
        // Expire one session
        $this->sessionService->expireSession($session2->session_id);
        
        // Act
        $activeSessions = $this->sessionService->getCustomerActiveSessions($customerId);
        
        // Assert
        $this->assertCount(1, $activeSessions);
        $this->assertEquals($session1->session_id, $activeSessions[0]->session_id);
    }

    /** @test */
    public function it_validates_session_tokens_correctly(): void
    {
        // Arrange
        $customerId = 'customer-token';
        $session = $this->sessionService->createSession($customerId);
        
        // Act & Assert
        $this->assertTrue($this->sessionService->validateSessionToken(
            $session->session_id,
            $session->security_token
        ));
        
        $this->assertFalse($this->sessionService->validateSessionToken(
            $session->session_id,
            'invalid-token'
        ));
        
        $this->assertFalse($this->sessionService->validateSessionToken(
            'invalid-session',
            $session->security_token
        ));
    }

    /** @test */
    public function it_can_cleanup_expired_sessions(): void
    {
        // Arrange
        $customerId = 'customer-cleanup';
        
        // Create a session and manually set it as expired in the past
        $expiredSession = $this->sessionService->createSession($customerId);
        $expiredSession->update(['expires_at' => Carbon::now()->subMinutes(10)]);
        
        // Create an active session
        $activeSession = $this->sessionService->createSession($customerId);
        
        // Act
        $cleanedCount = $this->sessionService->cleanupExpiredSessions();
        
        // Assert
        $this->assertEquals(1, $cleanedCount);
        $this->assertNull($this->sessionService->getSession($expiredSession->session_id));
        $this->assertNotNull($this->sessionService->getSession($activeSession->session_id));
    }

    /** @test */
    public function it_creates_session_with_custom_options(): void
    {
        // Arrange
        $customerId = 'customer-options';
        $options = [
            'currency' => 'EUR',
            'metadata' => ['source' => 'mobile-app']
        ];
        
        // Act
        $session = $this->sessionService->createSession($customerId, $options);
        
        // Assert
        $this->assertEquals('EUR', $session->currency);
        $this->assertEquals(['source' => 'mobile-app'], $session->metadata);
    }
}