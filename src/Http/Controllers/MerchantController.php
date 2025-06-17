<?php

namespace XavierAu\LaravelQrPayment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use XavierAu\LaravelQrPayment\Contracts\PaymentSessionServiceInterface;
use XavierAu\LaravelQrPayment\Contracts\TransactionServiceInterface;
use XavierAu\LaravelQrPayment\Http\Requests\ScanQrCodeRequest;
use XavierAu\LaravelQrPayment\Http\Requests\ProcessPaymentRequest;
use XavierAu\LaravelQrPayment\Http\Requests\RefundTransactionRequest;

class MerchantController extends BaseApiController
{
    private PaymentSessionServiceInterface $sessionService;
    private TransactionServiceInterface $transactionService;

    public function __construct(
        PaymentSessionServiceInterface $sessionService,
        TransactionServiceInterface $transactionService
    ) {
        $this->sessionService = $sessionService;
        $this->transactionService = $transactionService;
    }

    /**
     * Scan and validate QR code
     * FR-MA-001: Scan QR using device camera or dedicated scanner
     * FR-MA-002: Validate QR within 2 seconds
     * FR-MA-004: Prevent duplicate scans of same QR
     */
    public function scanQrCode(ScanQrCodeRequest $request): JsonResponse
    {
        try {
            $qrData = $request->input('qr_data');
            $merchantId = $request->input('merchant_id');

            // Parse QR code data
            $sessionData = json_decode($qrData, true);
            if (!$sessionData || !isset($sessionData['session_id'])) {
                return $this->error('Invalid QR code format', 400);
            }

            $sessionId = $sessionData['session_id'];

            // Validate session exists and is active
            $session = $this->sessionService->getSession($sessionId);
            if (!$session) {
                return $this->notFound('Payment session not found');
            }

            if (!$this->sessionService->isSessionActive($sessionId)) {
                return $this->error('Payment session has expired or is no longer active', 400);
            }

            // Check for duplicate scan
            if ($session->status !== 'pending') {
                return $this->error('QR code has already been scanned', 400);
            }

            return $this->success([
                'session_id' => $sessionId,
                'customer_id' => $session->customer_id,
                'session_status' => $session->status,
                'expires_at' => $session->expires_at->toISOString(),
                'customer_info' => [
                    'id' => $session->customer_id,
                    // In real implementation, would fetch customer details
                    'member_status' => 'verified',
                ],
            ], 'QR code scanned successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to scan QR code: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Process payment transaction
     * FR-MA-005: Manual amount entry with numeric keypad
     * FR-MA-007: Add items/description (optional)
     */
    public function processPayment(ProcessPaymentRequest $request): JsonResponse
    {
        try {
            $sessionId = $request->input('session_id');
            $merchantId = $request->input('merchant_id');
            $amount = $request->input('amount');
            $customerId = $request->input('customer_id');

            // Validate session
            $session = $this->sessionService->getSession($sessionId);
            if (!$session || !$this->sessionService->isSessionActive($sessionId)) {
                return $this->error('Invalid or expired payment session', 400);
            }

            // Update session with merchant scan data
            $updatedSession = $this->sessionService->updateWithMerchantScan(
                $sessionId,
                $merchantId,
                $amount,
                $request->only(['items', 'description', 'location', 'tip_amount'])
            );

            // Create transaction
            $transaction = $this->transactionService->processPayment(
                $sessionId,
                $customerId,
                $merchantId,
                $amount,
                [
                    'currency' => $request->input('currency', 'USD'),
                    'merchant_info' => [
                        'name' => $request->input('merchant_name'),
                        'location' => $request->input('location'),
                        'verification_status' => 'verified'
                    ],
                    'transaction_details' => [
                        'items' => $request->input('items', []),
                        'description' => $request->input('description'),
                        'tip_amount' => $request->input('tip_amount', 0),
                    ],
                    'calculate_fees' => true,
                ]
            );

            return $this->success([
                'transaction_id' => $transaction->transaction_id,
                'session_id' => $sessionId,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'fees' => $transaction->fees,
                'net_amount' => $transaction->net_amount,
                'status' => $transaction->status,
                'timeout_at' => $transaction->timeout_at->toISOString(),
                'customer_id' => $customerId,
            ], 'Payment initiated successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to process payment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get real-time payment status
     * FR-MA-008: Show real-time payment status
     */
    public function getPaymentStatus(string $transactionId): JsonResponse
    {
        try {
            $transaction = $this->transactionService->getTransaction($transactionId);
            if (!$transaction) {
                return $this->notFound('Transaction not found');
            }

            $isTimedOut = $transaction->isTimedOut();
            $timeRemaining = null;

            if ($transaction->timeout_at && !$isTimedOut) {
                $timeRemaining = max(0, now()->diffInSeconds($transaction->timeout_at, false));
            }

            return $this->success([
                'transaction_id' => $transaction->transaction_id,
                'status' => $transaction->status,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'customer_id' => $transaction->customer_id,
                'is_timed_out' => $isTimedOut,
                'time_remaining_seconds' => $timeRemaining,
                'processed_at' => $transaction->processed_at?->toISOString(),
                'confirmed_at' => $transaction->confirmed_at?->toISOString(),
                'auth_method' => $transaction->auth_method,
            ]);

        } catch (\Exception $e) {
            return $this->error('Failed to get payment status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get merchant transaction history
     * FR-MA-012: Daily transaction reports
     */
    public function getTransactionHistory(Request $request): JsonResponse
    {
        try {
            $merchantId = $request->input('merchant_id');
            $filters = $request->only(['status', 'type', 'from_date', 'to_date']);
            $limit = $request->input('limit', 50);

            $transactions = $this->transactionService->getMerchantTransactions(
                $merchantId,
                $filters,
                $limit
            );

            $totalAmount = array_sum(array_map(function ($transaction) {
                return $transaction->status === 'completed' ? $transaction->amount : 0;
            }, $transactions));

            return $this->success([
                'transactions' => array_map(function ($transaction) {
                    return [
                        'transaction_id' => $transaction->transaction_id,
                        'customer_id' => $transaction->customer_id,
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'fees' => $transaction->fees,
                        'net_amount' => $transaction->net_amount,
                        'status' => $transaction->status,
                        'type' => $transaction->type,
                        'created_at' => $transaction->created_at->toISOString(),
                        'confirmed_at' => $transaction->confirmed_at?->toISOString(),
                    ];
                }, $transactions),
                'summary' => [
                    'total_count' => count($transactions),
                    'total_amount' => $totalAmount,
                    'currency' => $transactions[0]->currency ?? 'USD',
                ],
            ]);

        } catch (\Exception $e) {
            return $this->error('Failed to get transaction history: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Process refund
     * FR-MA-011: Process refunds with manager approval
     */
    public function processRefund(RefundTransactionRequest $request, string $transactionId): JsonResponse
    {
        try {
            $amount = $request->input('amount');
            $reason = $request->input('reason', 'Merchant refund');
            $managerApproval = $request->input('manager_approval_code');

            // In real implementation, validate manager approval code
            if (!$managerApproval) {
                return $this->error('Manager approval required for refunds', 403);
            }

            $refundTransaction = $this->transactionService->refundTransaction(
                $transactionId,
                $amount,
                $reason
            );

            return $this->success([
                'refund_transaction_id' => $refundTransaction->transaction_id,
                'original_transaction_id' => $transactionId,
                'refund_amount' => $refundTransaction->amount,
                'currency' => $refundTransaction->currency,
                'status' => $refundTransaction->status,
                'reason' => $refundTransaction->failure_reason,
                'processed_at' => $refundTransaction->processed_at?->toISOString(),
            ], 'Refund processed successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to process refund: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get transaction receipt data
     * FR-MA-010: Print/email receipts
     */
    public function getReceipt(string $transactionId): JsonResponse
    {
        try {
            $transaction = $this->transactionService->getTransaction($transactionId);
            if (!$transaction) {
                return $this->notFound('Transaction not found');
            }

            if (!$transaction->isCompleted()) {
                return $this->error('Receipt only available for completed transactions', 400);
            }

            return $this->success([
                'receipt' => [
                    'transaction_id' => $transaction->transaction_id,
                    'merchant_id' => $transaction->merchant_id,
                    'customer_id' => $transaction->customer_id,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'fees' => $transaction->fees,
                    'net_amount' => $transaction->net_amount,
                    'payment_method' => 'QR Payment',
                    'auth_method' => $transaction->auth_method,
                    'transaction_date' => $transaction->created_at->toISOString(),
                    'confirmed_date' => $transaction->confirmed_at?->toISOString(),
                    'metadata' => $transaction->metadata,
                ],
            ]);

        } catch (\Exception $e) {
            return $this->error('Failed to get receipt: ' . $e->getMessage(), 500);
        }
    }
}