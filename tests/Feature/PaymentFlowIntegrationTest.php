<?php

namespace XavierAu\LaravelQrPayment\Tests\Feature;

use Illuminate\Support\Facades\Event;
use XavierAu\LaravelQrPayment\Events\PaymentConfirmationRequested;
use XavierAu\LaravelQrPayment\Events\TransactionStatusUpdated;
use XavierAu\LaravelQrPayment\Models\Transaction;
use XavierAu\LaravelQrPayment\Services\NotificationService;
use XavierAu\LaravelQrPayment\Services\PaymentSessionService;
use XavierAu\LaravelQrPayment\Services\QrCodeService;
use XavierAu\LaravelQrPayment\Services\TransactionService;
use XavierAu\LaravelQrPayment\Tests\TestCase;

class PaymentFlowIntegrationTest extends TestCase
{
    private QrCodeService $qrCodeService;
    private PaymentSessionService $sessionService;
    private TransactionService $transactionService;
    private NotificationService $notificationService;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        
        $this->qrCodeService = new QrCodeService();
        $this->sessionService = new PaymentSessionService();
        $this->notificationService = new NotificationService();
        $this->transactionService = new TransactionService($this->notificationService);
    }

    /** @test */
    public function it_can_complete_full_payment_flow_with_real_time_notifications(): void
    {
        // Arrange
        $customerId = 'customer-integration-123';
        $merchantId = 'merchant-integration-456';
        $amount = 35.75;

        // Act & Assert - Step 1: Customer generates QR code
        $session = $this->sessionService->createSession($customerId);
        $qrCode = $this->qrCodeService->generatePaymentQrCode($session->session_id);
        
        $this->assertNotEmpty($qrCode);
        $this->assertTrue($this->sessionService->isSessionActive($session->session_id));

        // Step 2: Merchant scans QR and initiates payment
        $updatedSession = $this->sessionService->updateWithMerchantScan(
            $session->session_id,
            $merchantId,
            $amount,
            ['item' => 'Premium Coffee', 'location' => 'Downtown Store']
        );

        $this->assertEquals('scanned', $updatedSession->status);
        $this->assertEquals($merchantId, $updatedSession->merchant_id);
        $this->assertEquals($amount, $updatedSession->amount);

        // Step 3: System processes payment and sends confirmation request
        $transaction = $this->transactionService->processPayment(
            $session->session_id,
            $customerId,
            $merchantId,
            $amount,
            [
                'merchant_info' => [
                    'name' => 'Coffee Shop Premium',
                    'location' => 'Downtown Store',
                    'verification_status' => 'verified'
                ],
                'transaction_details' => [
                    'items' => ['Premium Coffee'],
                    'tip_option' => true
                ]
            ]
        );

        $this->assertEquals(Transaction::STATUS_PENDING, $transaction->status);
        $this->assertEquals($amount, $transaction->amount);

        // Verify payment confirmation request was sent
        Event::assertDispatched(PaymentConfirmationRequested::class, function ($event) use ($customerId, $merchantId, $amount) {
            return $event->customerId === $customerId &&
                   $event->merchantId === $merchantId &&
                   $event->amount === $amount &&
                   $event->merchantInfo['name'] === 'Coffee Shop Premium';
        });

        // Step 4: Customer confirms payment with biometric auth
        $confirmedTransaction = $this->transactionService->confirmTransaction(
            $transaction->transaction_id,
            Transaction::AUTH_BIOMETRIC,
            [
                'fingerprint_hash' => 'secure_hash_abc123',
                'device_id' => 'customer_device_456'
            ]
        );

        $this->assertEquals(Transaction::STATUS_CONFIRMED, $confirmedTransaction->status);
        $this->assertEquals(Transaction::AUTH_BIOMETRIC, $confirmedTransaction->auth_method);

        // Verify transaction status update was broadcast
        Event::assertDispatched(TransactionStatusUpdated::class, function ($event) use ($transaction) {
            return $event->transactionId === $transaction->transaction_id &&
                   $event->status === Transaction::STATUS_CONFIRMED &&
                   $event->customerId === $transaction->customer_id &&
                   $event->merchantId === $transaction->merchant_id;
        });

        // Step 5: Verify session and transaction states
        $finalSession = $this->sessionService->getSession($session->session_id);
        $this->assertEquals('scanned', $finalSession->status);

        $finalTransaction = $this->transactionService->getTransaction($transaction->transaction_id);
        $this->assertEquals(Transaction::STATUS_CONFIRMED, $finalTransaction->status);
        $this->assertNotNull($finalTransaction->confirmed_at);
    }

    /** @test */
    public function it_handles_transaction_timeout_with_notifications(): void
    {
        // Arrange
        $customerId = 'customer-timeout';
        $merchantId = 'merchant-timeout';
        $amount = 20.00;

        // Act
        $session = $this->sessionService->createSession($customerId);
        $transaction = $this->transactionService->processPayment(
            $session->session_id,
            $customerId,
            $merchantId,
            $amount
        );

        // Manually timeout the transaction
        $transaction->update(['timeout_at' => now()->subMinutes(5)]);

        // Assert - Transaction should be timed out
        $this->assertTrue($transaction->fresh()->isTimedOut());

        // Verify confirmation request was sent initially
        Event::assertDispatched(PaymentConfirmationRequested::class);
    }

    /** @test */
    public function it_handles_transaction_cancellation_flow(): void
    {
        // Arrange
        $customerId = 'customer-cancel';
        $merchantId = 'merchant-cancel';
        $amount = 15.50;

        // Act
        $session = $this->sessionService->createSession($customerId);
        $transaction = $this->transactionService->processPayment(
            $session->session_id,
            $customerId,
            $merchantId,
            $amount
        );

        // Customer or system cancels the transaction
        $cancelledTransaction = $this->transactionService->cancelTransaction(
            $transaction->transaction_id,
            'Customer cancelled payment'
        );

        // Assert
        $this->assertEquals(Transaction::STATUS_CANCELLED, $cancelledTransaction->status);
        $this->assertEquals('Customer cancelled payment', $cancelledTransaction->failure_reason);
        $this->assertNotNull($cancelledTransaction->cancelled_at);

        // Verify initial payment confirmation was sent
        Event::assertDispatched(PaymentConfirmationRequested::class);
    }

    /** @test */
    public function it_handles_multiple_concurrent_sessions(): void
    {
        // Arrange
        $customerId = 'customer-concurrent';
        $merchantId1 = 'merchant-1';
        $merchantId2 = 'merchant-2';

        // Act - Create multiple sessions for same customer
        $session1 = $this->sessionService->createSession($customerId);
        $session2 = $this->sessionService->createSession($customerId);

        $transaction1 = $this->transactionService->processPayment(
            $session1->session_id,
            $customerId,
            $merchantId1,
            25.00
        );

        $transaction2 = $this->transactionService->processPayment(
            $session2->session_id,
            $customerId,
            $merchantId2,
            40.00
        );

        // Assert
        $this->assertNotEquals($transaction1->transaction_id, $transaction2->transaction_id);
        $this->assertNotEquals($transaction1->session_id, $transaction2->session_id);

        // Verify both payment confirmations were sent
        Event::assertDispatchedTimes(PaymentConfirmationRequested::class, 2);
    }

    /** @test */
    public function it_measures_notification_latency(): void
    {
        // Arrange
        $customerId = 'customer-latency';
        $merchantId = 'merchant-latency';
        $amount = 30.00;

        // Act
        $session = $this->sessionService->createSession($customerId);
        
        $startTime = microtime(true);
        $transaction = $this->transactionService->processPayment(
            $session->session_id,
            $customerId,
            $merchantId,
            $amount
        );
        $endTime = microtime(true);

        // Assert - FR-BE-010: Push notification latency < 2 seconds
        $latency = $endTime - $startTime;
        $this->assertLessThan(2.0, $latency, 'Payment notification should be sent within 2 seconds');

        // Verify notification was dispatched
        Event::assertDispatched(PaymentConfirmationRequested::class, function ($event) use ($customerId) {
            return $event->customerId === $customerId;
        });
    }
}