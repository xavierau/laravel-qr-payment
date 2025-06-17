<?php

namespace XavierAu\LaravelQrPayment\Tests\Unit\Services;

use Carbon\Carbon;
use XavierAu\LaravelQrPayment\Exceptions\InsufficientBalanceException;
use XavierAu\LaravelQrPayment\Exceptions\TransactionNotFoundException;
use XavierAu\LaravelQrPayment\Exceptions\TransactionAlreadyProcessedException;
use XavierAu\LaravelQrPayment\Exceptions\TransactionTimeoutException;
use XavierAu\LaravelQrPayment\Models\PaymentSession;
use XavierAu\LaravelQrPayment\Models\Transaction;
use XavierAu\LaravelQrPayment\Services\PaymentSessionService;
use XavierAu\LaravelQrPayment\Services\TransactionService;
use XavierAu\LaravelQrPayment\Services\NotificationService;
use XavierAu\LaravelQrPayment\Tests\TestCase;

class TransactionServiceTest extends TestCase
{
    private TransactionService $transactionService;
    private PaymentSessionService $sessionService;

    protected function setUp(): void
    {
        parent::setUp();
        $notificationService = new NotificationService();
        $this->transactionService = new TransactionService($notificationService);
        $this->sessionService = new PaymentSessionService();
    }

    /** @test */
    public function it_can_process_payment_transaction(): void
    {
        // Arrange
        $customerId = 'customer-123';
        $merchantId = 'merchant-456';
        $amount = 25.50;
        $session = $this->sessionService->createSession($customerId);
        
        // Act
        $transaction = $this->transactionService->processPayment(
            $session->session_id,
            $customerId,
            $merchantId,
            $amount
        );
        
        // Assert
        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertEquals($session->session_id, $transaction->session_id);
        $this->assertEquals($customerId, $transaction->customer_id);
        $this->assertEquals($merchantId, $transaction->merchant_id);
        $this->assertEquals($amount, $transaction->amount);
        $this->assertEquals(Transaction::STATUS_PENDING, $transaction->status);
        $this->assertEquals(Transaction::TYPE_PAYMENT, $transaction->type);
        $this->assertNotEmpty($transaction->transaction_id);
    }

    /** @test */
    public function it_processes_transaction_within_time_limit(): void
    {
        // Arrange
        $customerId = 'customer-speed';
        $merchantId = 'merchant-speed';
        $amount = 10.00;
        $session = $this->sessionService->createSession($customerId);
        
        $startTime = microtime(true);
        
        // Act
        $transaction = $this->transactionService->processPayment(
            $session->session_id,
            $customerId,
            $merchantId,
            $amount
        );
        
        $endTime = microtime(true);
        
        // Assert
        $processingTime = $endTime - $startTime;
        $this->assertLessThan(5.0, $processingTime, 'Transaction processing should complete within 5 seconds');
        $this->assertInstanceOf(Transaction::class, $transaction);
    }

    /** @test */
    public function it_can_confirm_pending_transaction(): void
    {
        // Arrange
        $customerId = 'customer-confirm';
        $merchantId = 'merchant-confirm';
        $amount = 15.75;
        $session = $this->sessionService->createSession($customerId);
        
        $transaction = $this->transactionService->processPayment(
            $session->session_id,
            $customerId,
            $merchantId,
            $amount
        );
        
        // Act
        $confirmedTransaction = $this->transactionService->confirmTransaction(
            $transaction->transaction_id,
            Transaction::AUTH_BIOMETRIC,
            ['fingerprint_hash' => 'abc123']
        );
        
        // Assert
        $this->assertEquals(Transaction::STATUS_CONFIRMED, $confirmedTransaction->status);
        $this->assertEquals(Transaction::AUTH_BIOMETRIC, $confirmedTransaction->auth_method);
        $this->assertEquals(['fingerprint_hash' => 'abc123'], $confirmedTransaction->auth_data);
        $this->assertNotNull($confirmedTransaction->confirmed_at);
    }

    /** @test */
    public function it_can_cancel_pending_transaction(): void
    {
        // Arrange
        $customerId = 'customer-cancel';
        $merchantId = 'merchant-cancel';
        $amount = 30.00;
        $session = $this->sessionService->createSession($customerId);
        
        $transaction = $this->transactionService->processPayment(
            $session->session_id,
            $customerId,
            $merchantId,
            $amount
        );
        
        // Act
        $cancelledTransaction = $this->transactionService->cancelTransaction(
            $transaction->transaction_id,
            'Customer cancelled'
        );
        
        // Assert
        $this->assertEquals(Transaction::STATUS_CANCELLED, $cancelledTransaction->status);
        $this->assertEquals('Customer cancelled', $cancelledTransaction->failure_reason);
        $this->assertNotNull($cancelledTransaction->cancelled_at);
    }

    /** @test */
    public function it_can_retrieve_transaction_by_id(): void
    {
        // Arrange
        $customerId = 'customer-retrieve';
        $merchantId = 'merchant-retrieve';
        $amount = 45.00;
        $session = $this->sessionService->createSession($customerId);
        
        $transaction = $this->transactionService->processPayment(
            $session->session_id,
            $customerId,
            $merchantId,
            $amount
        );
        
        // Act
        $retrievedTransaction = $this->transactionService->getTransaction($transaction->transaction_id);
        
        // Assert
        $this->assertInstanceOf(Transaction::class, $retrievedTransaction);
        $this->assertEquals($transaction->transaction_id, $retrievedTransaction->transaction_id);
        $this->assertEquals($amount, $retrievedTransaction->amount);
    }

    /** @test */
    public function it_returns_null_for_non_existent_transaction(): void
    {
        // Arrange
        $nonExistentId = 'non-existent-transaction-id';
        
        // Act
        $transaction = $this->transactionService->getTransaction($nonExistentId);
        
        // Assert
        $this->assertNull($transaction);
    }

    /** @test */
    public function it_validates_customer_balance(): void
    {
        // Arrange
        $customerId = 'customer-balance';
        $amount = 100.00;
        
        // Act
        $hasBalance = $this->transactionService->validateCustomerBalance($customerId, $amount);
        
        // Assert
        $this->assertTrue($hasBalance); // Mock implementation returns true
    }

    /** @test */
    public function it_throws_exception_for_insufficient_balance(): void
    {
        // Arrange
        $customerId = 'customer-poor';
        $amount = 1000000.00; // Very large amount
        
        // Act & Assert
        $this->expectException(InsufficientBalanceException::class);
        $this->transactionService->processPayment(
            'session-id',
            $customerId,
            'merchant-id',
            $amount
        );
    }

    /** @test */
    public function it_can_refund_completed_transaction(): void
    {
        // Arrange
        $customerId = 'customer-refund';
        $merchantId = 'merchant-refund';
        $amount = 50.00;
        $refundAmount = 25.00;
        $session = $this->sessionService->createSession($customerId);
        
        $transaction = $this->transactionService->processPayment(
            $session->session_id,
            $customerId,
            $merchantId,
            $amount
        );
        
        // Mark as completed first
        $transaction->markAsCompleted();
        
        // Act
        $refundTransaction = $this->transactionService->refundTransaction(
            $transaction->transaction_id,
            $refundAmount,
            'Customer request'
        );
        
        // Assert
        $this->assertEquals(Transaction::TYPE_REFUND, $refundTransaction->type);
        $this->assertEquals($refundAmount, $refundTransaction->amount);
        $this->assertEquals($transaction->transaction_id, $refundTransaction->parent_transaction_id);
        $this->assertEquals('Customer request', $refundTransaction->failure_reason);
    }

    /** @test */
    public function it_can_get_customer_transaction_history(): void
    {
        // Arrange
        $customerId = 'customer-history';
        $merchantId = 'merchant-history';
        $session1 = $this->sessionService->createSession($customerId);
        $session2 = $this->sessionService->createSession($customerId);
        
        $transaction1 = $this->transactionService->processPayment(
            $session1->session_id,
            $customerId,
            $merchantId,
            25.00
        );
        
        $transaction2 = $this->transactionService->processPayment(
            $session2->session_id,
            $customerId,
            $merchantId,
            35.00
        );
        
        // Act
        $transactions = $this->transactionService->getCustomerTransactions($customerId);
        
        // Assert
        $this->assertCount(2, $transactions);
        $this->assertEquals($customerId, $transactions[0]->customer_id);
        $this->assertEquals($customerId, $transactions[1]->customer_id);
    }

    /** @test */
    public function it_can_get_merchant_transaction_history(): void
    {
        // Arrange
        $customerId = 'customer-merchant-history';
        $merchantId = 'merchant-test';
        $session = $this->sessionService->createSession($customerId);
        
        $transaction = $this->transactionService->processPayment(
            $session->session_id,
            $customerId,
            $merchantId,
            40.00
        );
        
        // Act
        $transactions = $this->transactionService->getMerchantTransactions($merchantId);
        
        // Assert
        $this->assertCount(1, $transactions);
        $this->assertEquals($merchantId, $transactions[0]->merchant_id);
    }

    /** @test */
    public function it_implements_idempotency_for_operations(): void
    {
        // Arrange
        $idempotencyKey = 'test-key-123';
        $callCount = 0;
        
        $operation = function () use (&$callCount) {
            $callCount++;
            return 'result-' . $callCount;
        };
        
        // Act
        $result1 = $this->transactionService->executeIdempotentOperation($idempotencyKey, $operation);
        $result2 = $this->transactionService->executeIdempotentOperation($idempotencyKey, $operation);
        
        // Assert
        $this->assertEquals($result1, $result2);
        $this->assertEquals(1, $callCount); // Operation should only be called once
    }

    /** @test */
    public function it_throws_exception_for_already_processed_transaction(): void
    {
        // Arrange
        $customerId = 'customer-processed';
        $merchantId = 'merchant-processed';
        $amount = 20.00;
        $session = $this->sessionService->createSession($customerId);
        
        $transaction = $this->transactionService->processPayment(
            $session->session_id,
            $customerId,
            $merchantId,
            $amount
        );
        
        // Confirm the transaction first
        $this->transactionService->confirmTransaction(
            $transaction->transaction_id,
            Transaction::AUTH_PIN
        );
        
        // Act & Assert
        $this->expectException(TransactionAlreadyProcessedException::class);
        $this->transactionService->confirmTransaction(
            $transaction->transaction_id,
            Transaction::AUTH_PIN
        );
    }

    /** @test */
    public function it_handles_transaction_timeout(): void
    {
        // Arrange
        $customerId = 'customer-timeout';
        $merchantId = 'merchant-timeout';
        $amount = 15.00;
        $session = $this->sessionService->createSession($customerId);
        
        $transaction = $this->transactionService->processPayment(
            $session->session_id,
            $customerId,
            $merchantId,
            $amount
        );
        
        // Manually set timeout in the past
        $transaction->update(['timeout_at' => Carbon::now()->subMinutes(5)]);
        
        // Act & Assert
        $this->expectException(TransactionTimeoutException::class);
        $this->transactionService->confirmTransaction(
            $transaction->transaction_id,
            Transaction::AUTH_PIN
        );
    }

    /** @test */
    public function it_calculates_fees_and_net_amount(): void
    {
        // Arrange
        $customerId = 'customer-fees';
        $merchantId = 'merchant-fees';
        $amount = 100.00;
        $session = $this->sessionService->createSession($customerId);
        
        // Act
        $transaction = $this->transactionService->processPayment(
            $session->session_id,
            $customerId,
            $merchantId,
            $amount,
            ['calculate_fees' => true]
        );
        
        // Assert
        $this->assertGreaterThan(0, $transaction->fees);
        $this->assertLessThan($amount, $transaction->net_amount);
        $this->assertEquals($amount - $transaction->fees, $transaction->net_amount);
    }
}