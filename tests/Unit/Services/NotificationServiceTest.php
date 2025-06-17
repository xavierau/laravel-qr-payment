<?php

namespace XavierAu\LaravelQrPayment\Tests\Unit\Services;

use Illuminate\Support\Facades\Event;
use XavierAu\LaravelQrPayment\Events\PaymentConfirmationRequested;
use XavierAu\LaravelQrPayment\Events\PaymentCompleted;
use XavierAu\LaravelQrPayment\Events\TransactionStatusUpdated;
use XavierAu\LaravelQrPayment\Services\NotificationService;
use XavierAu\LaravelQrPayment\Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    private NotificationService $notificationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationService = new NotificationService();
        Event::fake();
    }

    /** @test */
    public function it_can_send_payment_confirmation_request(): void
    {
        // Arrange
        $customerId = 'customer-123';
        $transactionId = 'txn-456';
        $payload = [
            'merchant_id' => 'merchant-789',
            'amount' => 25.50,
            'currency' => 'USD',
            'merchant_info' => [
                'name' => 'Coffee Shop',
                'location' => 'Downtown'
            ]
        ];

        // Act
        $result = $this->notificationService->sendPaymentConfirmationRequest(
            $customerId,
            $transactionId,
            $payload
        );

        // Assert
        $this->assertTrue($result);
        Event::assertDispatched(PaymentConfirmationRequested::class, function ($event) use ($customerId, $transactionId, $payload) {
            return $event->customerId === $customerId &&
                   $event->transactionId === $transactionId &&
                   $event->merchantId === $payload['merchant_id'] &&
                   $event->amount === $payload['amount'];
        });
    }

    /** @test */
    public function it_dispatches_payment_confirmation_within_time_limit(): void
    {
        // Arrange
        $customerId = 'customer-speed';
        $transactionId = 'txn-speed';
        $payload = ['merchant_id' => 'merchant-1', 'amount' => 10.00];
        
        $startTime = microtime(true);

        // Act
        $result = $this->notificationService->sendPaymentConfirmationRequest(
            $customerId,
            $transactionId,
            $payload
        );

        $endTime = microtime(true);

        // Assert
        $dispatchTime = $endTime - $startTime;
        $this->assertLessThan(2.0, $dispatchTime, 'Payment confirmation should be dispatched within 2 seconds');
        $this->assertTrue($result);
    }

    /** @test */
    public function it_can_send_transaction_status_update(): void
    {
        // Arrange
        $transactionId = 'txn-status-123';
        $status = 'confirmed';
        $payload = [
            'customer_id' => 'customer-456',
            'merchant_id' => 'merchant-789',
            'auth_method' => 'biometric'
        ];

        // Act
        $result = $this->notificationService->sendTransactionStatusUpdate(
            $transactionId,
            $status,
            $payload
        );

        // Assert
        $this->assertTrue($result);
        Event::assertDispatched(TransactionStatusUpdated::class, function ($event) use ($transactionId, $status) {
            return $event->transactionId === $transactionId &&
                   $event->status === $status;
        });
    }

    /** @test */
    public function it_can_send_payment_completion_notification(): void
    {
        // Arrange
        $customerId = 'customer-complete';
        $merchantId = 'merchant-complete';
        $transactionId = 'txn-complete';
        $payload = [
            'amount' => 75.00,
            'currency' => 'USD',
            'receipt_data' => [
                'items' => ['Coffee', 'Muffin'],
                'total' => 75.00
            ]
        ];

        // Act
        $result = $this->notificationService->sendPaymentCompletionNotification(
            $customerId,
            $merchantId,
            $transactionId,
            $payload
        );

        // Assert
        $this->assertTrue($result);
        Event::assertDispatched(PaymentCompleted::class, function ($event) use ($transactionId, $customerId, $merchantId) {
            return $event->transactionId === $transactionId &&
                   $event->customerId === $customerId &&
                   $event->merchantId === $merchantId;
        });
    }

    /** @test */
    public function it_can_send_merchant_webhook(): void
    {
        // Arrange
        $merchantId = 'merchant-webhook';
        $event = 'payment.completed';
        $payload = [
            'transaction_id' => 'txn-webhook',
            'amount' => 50.00,
            'status' => 'completed'
        ];

        // Act
        $result = $this->notificationService->sendMerchantWebhook(
            $merchantId,
            $event,
            $payload
        );

        // Assert
        $this->assertTrue($result);
        // Webhook implementation would be tested separately
    }

    /** @test */
    public function it_can_send_sms_fallback(): void
    {
        // Arrange
        $phoneNumber = '+1234567890';
        $message = 'Payment confirmation required for $25.50 at Coffee Shop. Reply YES to confirm.';

        // Act
        $result = $this->notificationService->sendSMSFallback($phoneNumber, $message);

        // Assert
        $this->assertTrue($result);
        // SMS implementation would be tested separately
    }

    /** @test */
    public function it_can_send_email_receipt(): void
    {
        // Arrange
        $email = 'customer@example.com';
        $transactionId = 'txn-receipt';
        $receiptData = [
            'amount' => 30.00,
            'merchant' => 'Coffee Shop',
            'items' => ['Latte', 'Cookie'],
            'timestamp' => now()->toISOString()
        ];

        // Act
        $result = $this->notificationService->sendEmailReceipt(
            $email,
            $transactionId,
            $receiptData
        );

        // Assert
        $this->assertTrue($result);
        // Email implementation would be tested separately
    }

    /** @test */
    public function it_handles_broadcasting_configuration(): void
    {
        // Arrange
        $this->app['config']->set('qr-payment.broadcasting.enabled', true);
        $this->app['config']->set('qr-payment.broadcasting.connection', 'pusher');

        // Act
        $result = $this->notificationService->sendPaymentConfirmationRequest(
            'customer-config',
            'txn-config',
            ['merchant_id' => 'merchant-1', 'amount' => 15.00]
        );

        // Assert
        $this->assertTrue($result);
        Event::assertDispatched(PaymentConfirmationRequested::class);
    }

    /** @test */
    public function it_handles_disabled_broadcasting(): void
    {
        // Arrange
        $this->app['config']->set('qr-payment.broadcasting.enabled', false);

        // Act
        $result = $this->notificationService->sendPaymentConfirmationRequest(
            'customer-disabled',
            'txn-disabled',
            ['merchant_id' => 'merchant-1', 'amount' => 20.00]
        );

        // Assert - Should still return true but not dispatch events
        $this->assertTrue($result);
    }

    /** @test */
    public function it_sends_multiple_notifications_for_transaction_flow(): void
    {
        // Arrange
        $customerId = 'customer-flow';
        $merchantId = 'merchant-flow';
        $transactionId = 'txn-flow';

        // Act - Simulate full transaction flow
        // 1. Payment confirmation request
        $this->notificationService->sendPaymentConfirmationRequest(
            $customerId,
            $transactionId,
            ['merchant_id' => $merchantId, 'amount' => 45.00]
        );

        // 2. Status updates
        $this->notificationService->sendTransactionStatusUpdate(
            $transactionId,
            'confirmed',
            ['customer_id' => $customerId, 'merchant_id' => $merchantId]
        );

        // 3. Payment completion
        $this->notificationService->sendPaymentCompletionNotification(
            $customerId,
            $merchantId,
            $transactionId,
            ['amount' => 45.00, 'currency' => 'USD']
        );

        // Assert
        Event::assertDispatched(PaymentConfirmationRequested::class);
        Event::assertDispatched(TransactionStatusUpdated::class);
        Event::assertDispatched(PaymentCompleted::class);
    }

    /** @test */
    public function it_validates_required_payload_fields(): void
    {
        // Arrange
        $customerId = 'customer-validate';
        $transactionId = 'txn-validate';
        $incompletePayload = [
            'amount' => 25.00
            // Missing merchant_id
        ];

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->notificationService->sendPaymentConfirmationRequest(
            $customerId,
            $transactionId,
            $incompletePayload
        );
    }
}