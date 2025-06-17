<?php

namespace XavierAu\LaravelQrPayment\Tests\Feature;

use Illuminate\Support\Facades\Event;
use XavierAu\LaravelQrPayment\Events\PaymentConfirmationRequested;
use XavierAu\LaravelQrPayment\Models\Transaction;
use XavierAu\LaravelQrPayment\Services\NotificationService;
use XavierAu\LaravelQrPayment\Services\PaymentSessionService;
use XavierAu\LaravelQrPayment\Services\TransactionService;
use XavierAu\LaravelQrPayment\Tests\TestCase;

class CustomerControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    /** @test */
    public function it_can_generate_qr_code(): void
    {
        // Arrange
        $payload = [
            'customer_id' => 'customer-123',
            'currency' => 'USD',
            'size' => 300,
            'format' => 'svg'
        ];

        // Act
        $response = $this->postJson('/qr-payment/customer/qr-code', $payload);

        // Assert
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        'session_id',
                        'qr_code',
                        'expires_at',
                        'timeout_minutes'
                    ]
                ]);

        $data = $response->json('data');
        $this->assertStringStartsWith('qr_', $data['session_id']);
        $this->assertStringStartsWith('data:image/', $data['qr_code']);
        $this->assertNotNull($data['expires_at']);
        $this->assertEquals(5, $data['timeout_minutes']);
    }

    /** @test */
    public function it_validates_generate_qr_code_request(): void
    {
        // Act
        $response = $this->postJson('/qr-payment/customer/qr-code', []);

        // Assert
        $response->assertStatus(422)
                ->assertJsonValidationErrors(['customer_id']);
    }

    /** @test */
    public function it_can_regenerate_qr_code(): void
    {
        // Arrange
        $sessionService = new PaymentSessionService();
        $session = $sessionService->createSession('customer-123');

        // Act
        $response = $this->putJson("/qr-payment/customer/qr-code/{$session->session_id}/regenerate", [
            'size' => 400,
            'format' => 'svg'
        ]);

        // Assert
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        'session_id',
                        'qr_code',
                        'expires_at'
                    ]
                ]);

        $data = $response->json('data');
        $this->assertEquals($session->session_id, $data['session_id']);
    }

    /** @test */
    public function it_returns_404_for_non_existent_session(): void
    {
        // Act
        $response = $this->putJson('/qr-payment/customer/qr-code/non-existent-session/regenerate');

        // Assert
        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Session not found'
                ]);
    }

    /** @test */
    public function it_can_get_session_status(): void
    {
        // Arrange
        $sessionService = new PaymentSessionService();
        $session = $sessionService->createSession('customer-456');

        // Act
        $response = $this->getJson("/qr-payment/customer/session/{$session->session_id}/status");

        // Assert
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'session_id',
                        'status',
                        'is_active',
                        'expires_at',
                        'merchant_id',
                        'amount',
                        'scanned_at'
                    ]
                ]);

        $data = $response->json('data');
        $this->assertEquals($session->session_id, $data['session_id']);
        $this->assertEquals('pending', $data['status']);
        $this->assertTrue($data['is_active']);
    }

    /** @test */
    public function it_can_confirm_transaction(): void
    {
        // Arrange
        $sessionService = new PaymentSessionService();
        $notificationService = new NotificationService();
        $transactionService = new TransactionService($notificationService);

        $session = $sessionService->createSession('customer-789');
        $transaction = $transactionService->processPayment(
            $session->session_id,
            'customer-789',
            'merchant-123',
            25.50
        );

        $payload = [
            'auth_method' => Transaction::AUTH_BIOMETRIC,
            'auth_data' => [
                'fingerprint_hash' => 'secure_hash_123'
            ]
        ];

        // Act
        $response = $this->postJson("/qr-payment/customer/transaction/{$transaction->transaction_id}/confirm", $payload);

        // Assert
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        'transaction_id',
                        'status',
                        'amount',
                        'currency',
                        'merchant_id',
                        'confirmed_at',
                        'auth_method'
                    ]
                ]);

        $data = $response->json('data');
        $this->assertEquals($transaction->transaction_id, $data['transaction_id']);
        $this->assertEquals(Transaction::STATUS_CONFIRMED, $data['status']);
        $this->assertEquals(Transaction::AUTH_BIOMETRIC, $data['auth_method']);
    }

    /** @test */
    public function it_validates_confirm_transaction_request(): void
    {
        // Arrange
        $sessionService = new PaymentSessionService();
        $notificationService = new NotificationService();
        $transactionService = new TransactionService($notificationService);

        $session = $sessionService->createSession('customer-validate');
        $transaction = $transactionService->processPayment(
            $session->session_id,
            'customer-validate',
            'merchant-validate',
            15.00
        );

        // Act - Missing auth_method
        $response = $this->postJson("/qr-payment/customer/transaction/{$transaction->transaction_id}/confirm", []);

        // Assert
        $response->assertStatus(422)
                ->assertJsonValidationErrors(['auth_method']);
    }

    /** @test */
    public function it_can_cancel_transaction(): void
    {
        // Arrange
        $sessionService = new PaymentSessionService();
        $notificationService = new NotificationService();
        $transactionService = new TransactionService($notificationService);

        $session = $sessionService->createSession('customer-cancel');
        $transaction = $transactionService->processPayment(
            $session->session_id,
            'customer-cancel',
            'merchant-cancel',
            30.00
        );

        $payload = [
            'reason' => 'Customer changed mind'
        ];

        // Act
        $response = $this->postJson("/qr-payment/customer/transaction/{$transaction->transaction_id}/cancel", $payload);

        // Assert
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        'transaction_id',
                        'status',
                        'cancelled_at',
                        'reason'
                    ]
                ]);

        $data = $response->json('data');
        $this->assertEquals($transaction->transaction_id, $data['transaction_id']);
        $this->assertEquals(Transaction::STATUS_CANCELLED, $data['status']);
        $this->assertEquals('Customer changed mind', $data['reason']);
    }

    /** @test */
    public function it_can_get_transaction_history(): void
    {
        // Arrange
        $customerId = 'customer-history';
        $sessionService = new PaymentSessionService();
        $notificationService = new NotificationService();
        $transactionService = new TransactionService($notificationService);

        // Create multiple transactions
        $session1 = $sessionService->createSession($customerId);
        $session2 = $sessionService->createSession($customerId);

        $transaction1 = $transactionService->processPayment(
            $session1->session_id,
            $customerId,
            'merchant-1',
            25.00
        );

        $transaction2 = $transactionService->processPayment(
            $session2->session_id,
            $customerId,
            'merchant-2',
            35.00
        );

        $payload = [
            'customer_id' => $customerId,
            'limit' => 10
        ];

        // Act
        $response = $this->getJson('/qr-payment/customer/transactions?' . http_build_query($payload));

        // Assert
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'transactions' => [
                            '*' => [
                                'transaction_id',
                                'amount',
                                'currency',
                                'status',
                                'type',
                                'merchant_id',
                                'created_at'
                            ]
                        ],
                        'count'
                    ]
                ]);

        $data = $response->json('data');
        $this->assertEquals(2, $data['count']);
    }

    /** @test */
    public function it_can_get_transaction_details(): void
    {
        // Arrange
        $sessionService = new PaymentSessionService();
        $notificationService = new NotificationService();
        $transactionService = new TransactionService($notificationService);

        $session = $sessionService->createSession('customer-details');
        $transaction = $transactionService->processPayment(
            $session->session_id,
            'customer-details',
            'merchant-details',
            40.00
        );

        // Act
        $response = $this->getJson("/qr-payment/customer/transaction/{$transaction->transaction_id}");

        // Assert
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'transaction_id',
                        'session_id',
                        'amount',
                        'currency',
                        'fees',
                        'net_amount',
                        'status',
                        'type',
                        'merchant_id',
                        'created_at',
                        'timeout_at'
                    ]
                ]);

        $data = $response->json('data');
        $this->assertEquals($transaction->transaction_id, $data['transaction_id']);
        $this->assertEquals(40.00, $data['amount']);
    }

    /** @test */
    public function it_returns_404_for_non_existent_transaction(): void
    {
        // Act
        $response = $this->getJson('/qr-payment/customer/transaction/non-existent-transaction');

        // Assert
        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Transaction not found'
                ]);
    }

    /** @test */
    public function it_handles_api_response_time_requirement(): void
    {
        // Arrange
        $payload = [
            'customer_id' => 'customer-performance',
            'currency' => 'USD'
        ];

        $startTime = microtime(true);

        // Act
        $response = $this->postJson('/qr-payment/customer/qr-code', $payload);

        $endTime = microtime(true);

        // Assert - NFR-P-001: API response time < 200ms (p95)
        $responseTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
        $this->assertLessThan(200, $responseTime, 'API response time should be less than 200ms');
        $response->assertStatus(200);
    }
}