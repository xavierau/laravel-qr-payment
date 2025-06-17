<?php

namespace XavierAu\LaravelQrPayment\Tests\Feature;

use Illuminate\Support\Facades\Event;
use XavierAu\LaravelQrPayment\Events\PaymentConfirmationRequested;
use XavierAu\LaravelQrPayment\Models\Transaction;
use XavierAu\LaravelQrPayment\Services\NotificationService;
use XavierAu\LaravelQrPayment\Services\PaymentSessionService;
use XavierAu\LaravelQrPayment\Services\TransactionService;
use XavierAu\LaravelQrPayment\Tests\TestCase;

class MerchantControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    /** @test */
    public function it_can_scan_qr_code(): void
    {
        // Arrange
        $sessionService = new PaymentSessionService();
        $session = $sessionService->createSession('customer-123');

        $qrData = json_encode([
            'session_id' => $session->session_id,
            'timestamp' => time(),
            'type' => 'payment_request',
            'version' => '1.0'
        ]);

        $payload = [
            'qr_data' => $qrData,
            'merchant_id' => 'merchant-456'
        ];

        // Act
        $response = $this->postJson('/qr-payment/merchant/qr-code/scan', $payload);

        // Assert
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        'session_id',
                        'customer_id',
                        'session_status',
                        'expires_at',
                        'customer_info'
                    ]
                ]);

        $data = $response->json('data');
        $this->assertEquals($session->session_id, $data['session_id']);
        $this->assertEquals('customer-123', $data['customer_id']);
        $this->assertEquals('pending', $data['session_status']);
    }

    /** @test */
    public function it_validates_scan_qr_code_request(): void
    {
        // Act
        $response = $this->postJson('/qr-payment/merchant/qr-code/scan', []);

        // Assert
        $response->assertStatus(422)
                ->assertJsonValidationErrors(['qr_data', 'merchant_id']);
    }

    /** @test */
    public function it_rejects_invalid_qr_code_format(): void
    {
        // Arrange
        $payload = [
            'qr_data' => 'invalid-qr-data',
            'merchant_id' => 'merchant-456'
        ];

        // Act
        $response = $this->postJson('/qr-payment/merchant/qr-code/scan', $payload);

        // Assert
        $response->assertStatus(400)
                ->assertJson([
                    'success' => false,
                    'message' => 'Invalid QR code format'
                ]);
    }

    /** @test */
    public function it_rejects_expired_session(): void
    {
        // Arrange
        $qrData = json_encode([
            'session_id' => 'non-existent-session',
            'timestamp' => time(),
            'type' => 'payment_request',
            'version' => '1.0'
        ]);

        $payload = [
            'qr_data' => $qrData,
            'merchant_id' => 'merchant-456'
        ];

        // Act
        $response = $this->postJson('/qr-payment/merchant/qr-code/scan', $payload);

        // Assert
        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Payment session not found'
                ]);
    }

    /** @test */
    public function it_can_process_payment(): void
    {
        // Arrange
        $sessionService = new PaymentSessionService();
        $session = $sessionService->createSession('customer-789');

        $payload = [
            'session_id' => $session->session_id,
            'merchant_id' => 'merchant-123',
            'customer_id' => 'customer-789',
            'amount' => 45.75,
            'currency' => 'USD',
            'merchant_name' => 'Coffee Shop Premium',
            'location' => 'Downtown Store',
            'description' => 'Premium coffee and pastry',
            'items' => ['Premium Latte', 'Croissant'],
            'tip_amount' => 5.00
        ];

        // Act
        $response = $this->postJson('/qr-payment/merchant/payment/process', $payload);

        // Assert
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        'transaction_id',
                        'session_id',
                        'amount',
                        'currency',
                        'fees',
                        'net_amount',
                        'status',
                        'timeout_at',
                        'customer_id'
                    ]
                ]);

        $data = $response->json('data');
        $this->assertEquals($session->session_id, $data['session_id']);
        $this->assertEquals(45.75, $data['amount']);
        $this->assertEquals(Transaction::STATUS_PENDING, $data['status']);
        $this->assertStringStartsWith('txn_', $data['transaction_id']);

        // Verify payment confirmation request was dispatched
        Event::assertDispatched(PaymentConfirmationRequested::class);
    }

    /** @test */
    public function it_validates_process_payment_request(): void
    {
        // Act
        $response = $this->postJson('/qr-payment/merchant/payment/process', []);

        // Assert
        $response->assertStatus(422)
                ->assertJsonValidationErrors([
                    'session_id',
                    'merchant_id',
                    'customer_id',
                    'amount'
                ]);
    }

    /** @test */
    public function it_rejects_payment_for_invalid_session(): void
    {
        // Arrange
        $payload = [
            'session_id' => 'invalid-session',
            'merchant_id' => 'merchant-123',
            'customer_id' => 'customer-789',
            'amount' => 25.00
        ];

        // Act
        $response = $this->postJson('/qr-payment/merchant/payment/process', $payload);

        // Assert
        $response->assertStatus(400)
                ->assertJson([
                    'success' => false,
                    'message' => 'Invalid or expired payment session'
                ]);
    }

    /** @test */
    public function it_can_get_payment_status(): void
    {
        // Arrange
        $sessionService = new PaymentSessionService();
        $notificationService = new NotificationService();
        $transactionService = new TransactionService($notificationService);

        $session = $sessionService->createSession('customer-status');
        $transaction = $transactionService->processPayment(
            $session->session_id,
            'customer-status',
            'merchant-status',
            30.00
        );

        // Act
        $response = $this->getJson("/qr-payment/merchant/payment/{$transaction->transaction_id}/status");

        // Assert
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'transaction_id',
                        'status',
                        'amount',
                        'currency',
                        'customer_id',
                        'is_timed_out',
                        'time_remaining_seconds',
                        'processed_at'
                    ]
                ]);

        $data = $response->json('data');
        $this->assertEquals($transaction->transaction_id, $data['transaction_id']);
        $this->assertEquals(Transaction::STATUS_PENDING, $data['status']);
        $this->assertFalse($data['is_timed_out']);
        $this->assertGreaterThan(0, $data['time_remaining_seconds']);
    }

    /** @test */
    public function it_can_get_merchant_transaction_history(): void
    {
        // Arrange
        $merchantId = 'merchant-history';
        $sessionService = new PaymentSessionService();
        $notificationService = new NotificationService();
        $transactionService = new TransactionService($notificationService);

        // Create multiple transactions for merchant
        $session1 = $sessionService->createSession('customer-1');
        $session2 = $sessionService->createSession('customer-2');

        $transaction1 = $transactionService->processPayment(
            $session1->session_id,
            'customer-1',
            $merchantId,
            25.00
        );

        $transaction2 = $transactionService->processPayment(
            $session2->session_id,
            'customer-2',
            $merchantId,
            35.00
        );

        $payload = [
            'merchant_id' => $merchantId,
            'limit' => 10
        ];

        // Act
        $response = $this->getJson('/qr-payment/merchant/transactions?' . http_build_query($payload));

        // Assert
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'transactions' => [
                            '*' => [
                                'transaction_id',
                                'customer_id',
                                'amount',
                                'currency',
                                'fees',
                                'net_amount',
                                'status',
                                'type',
                                'created_at'
                            ]
                        ],
                        'summary' => [
                            'total_count',
                            'total_amount',
                            'currency'
                        ]
                    ]
                ]);

        $data = $response->json('data');
        $this->assertEquals(2, $data['summary']['total_count']);
    }

    /** @test */
    public function it_can_process_refund(): void
    {
        // Arrange
        $sessionService = new PaymentSessionService();
        $notificationService = new NotificationService();
        $transactionService = new TransactionService($notificationService);

        $session = $sessionService->createSession('customer-refund');
        $transaction = $transactionService->processPayment(
            $session->session_id,
            'customer-refund',
            'merchant-refund',
            50.00
        );

        // Mark transaction as completed for refund
        $transaction->markAsCompleted();

        $payload = [
            'amount' => 25.00,
            'reason' => 'Customer not satisfied',
            'manager_approval_code' => 'MGR123456'
        ];

        // Act
        $response = $this->postJson("/qr-payment/merchant/transaction/{$transaction->transaction_id}/refund", $payload);

        // Assert
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        'refund_transaction_id',
                        'original_transaction_id',
                        'refund_amount',
                        'currency',
                        'status',
                        'reason',
                        'processed_at'
                    ]
                ]);

        $data = $response->json('data');
        $this->assertEquals($transaction->transaction_id, $data['original_transaction_id']);
        $this->assertEquals(25.00, $data['refund_amount']);
        $this->assertEquals('Customer not satisfied', $data['reason']);
    }

    /** @test */
    public function it_validates_refund_request(): void
    {
        // Arrange
        $sessionService = new PaymentSessionService();
        $notificationService = new NotificationService();
        $transactionService = new TransactionService($notificationService);

        $session = $sessionService->createSession('customer-refund-validate');
        $transaction = $transactionService->processPayment(
            $session->session_id,
            'customer-refund-validate',
            'merchant-refund-validate',
            50.00
        );

        // Act - Missing required fields
        $response = $this->postJson("/qr-payment/merchant/transaction/{$transaction->transaction_id}/refund", []);

        // Assert
        $response->assertStatus(422)
                ->assertJsonValidationErrors(['amount', 'manager_approval_code']);
    }

    /** @test */
    public function it_can_get_transaction_receipt(): void
    {
        // Arrange
        $sessionService = new PaymentSessionService();
        $notificationService = new NotificationService();
        $transactionService = new TransactionService($notificationService);

        $session = $sessionService->createSession('customer-receipt');
        $transaction = $transactionService->processPayment(
            $session->session_id,
            'customer-receipt',
            'merchant-receipt',
            40.00
        );

        // Mark as completed for receipt generation
        $transaction->markAsCompleted();

        // Act
        $response = $this->getJson("/qr-payment/merchant/transaction/{$transaction->transaction_id}/receipt");

        // Assert
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'receipt' => [
                            'transaction_id',
                            'merchant_id',
                            'customer_id',
                            'amount',
                            'currency',
                            'fees',
                            'net_amount',
                            'payment_method',
                            'transaction_date',
                            'confirmed_date'
                        ]
                    ]
                ]);

        $data = $response->json('data.receipt');
        $this->assertEquals($transaction->transaction_id, $data['transaction_id']);
        $this->assertEquals(40.00, $data['amount']);
        $this->assertEquals('QR Payment', $data['payment_method']);
    }

    /** @test */
    public function it_rejects_receipt_for_incomplete_transaction(): void
    {
        // Arrange
        $sessionService = new PaymentSessionService();
        $notificationService = new NotificationService();
        $transactionService = new TransactionService($notificationService);

        $session = $sessionService->createSession('customer-incomplete');
        $transaction = $transactionService->processPayment(
            $session->session_id,
            'customer-incomplete',
            'merchant-incomplete',
            25.00
        );

        // Act - Transaction is still pending
        $response = $this->getJson("/qr-payment/merchant/transaction/{$transaction->transaction_id}/receipt");

        // Assert
        $response->assertStatus(400)
                ->assertJson([
                    'success' => false,
                    'message' => 'Receipt only available for completed transactions'
                ]);
    }

    /** @test */
    public function it_handles_qr_scan_validation_time_requirement(): void
    {
        // Arrange - FR-MA-002: Validate QR within 2 seconds
        $sessionService = new PaymentSessionService();
        $session = $sessionService->createSession('customer-validation-time');

        $qrData = json_encode([
            'session_id' => $session->session_id,
            'timestamp' => time(),
            'type' => 'payment_request',
            'version' => '1.0'
        ]);

        $payload = [
            'qr_data' => $qrData,
            'merchant_id' => 'merchant-validation'
        ];

        $startTime = microtime(true);

        // Act
        $response = $this->postJson('/qr-payment/merchant/qr-code/scan', $payload);

        $endTime = microtime(true);

        // Assert
        $validationTime = $endTime - $startTime;
        $this->assertLessThan(2.0, $validationTime, 'QR validation should complete within 2 seconds');
        $response->assertStatus(200);
    }
}