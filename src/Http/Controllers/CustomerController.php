<?php

namespace XavierAu\LaravelQrPayment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use XavierAu\LaravelQrPayment\Contracts\QrCodeServiceInterface;
use XavierAu\LaravelQrPayment\Contracts\PaymentSessionServiceInterface;
use XavierAu\LaravelQrPayment\Contracts\TransactionServiceInterface;
use XavierAu\LaravelQrPayment\Http\Requests\GenerateQrCodeRequest;
use XavierAu\LaravelQrPayment\Http\Requests\ConfirmTransactionRequest;

class CustomerController extends BaseApiController
{
    private QrCodeServiceInterface $qrCodeService;
    private PaymentSessionServiceInterface $sessionService;
    private TransactionServiceInterface $transactionService;

    public function __construct(
        QrCodeServiceInterface $qrCodeService,
        PaymentSessionServiceInterface $sessionService,
        TransactionServiceInterface $transactionService
    ) {
        $this->qrCodeService = $qrCodeService;
        $this->sessionService = $sessionService;
        $this->transactionService = $transactionService;
    }

    /**
     * Generate QR code for payment
     * FR-CW-001: User can access payment QR with one tap from home screen
     * FR-CW-002: QR code must be generated within 2 seconds
     */
    public function generateQrCode(GenerateQrCodeRequest $request): JsonResponse
    {
        try {
            $customerId = $request->input('customer_id');
            $options = $request->only(['currency', 'metadata']);

            // Create payment session
            $session = $this->sessionService->createSession($customerId, $options);

            // Generate QR code
            $qrCode = $this->qrCodeService->generatePaymentQrCode(
                $session->session_id,
                $request->only(['size', 'format'])
            );

            $expiryTime = $this->qrCodeService->getQrCodeExpiry($session->session_id);

            return $this->success([
                'session_id' => $session->session_id,
                'qr_code' => $qrCode,
                'expires_at' => $expiryTime?->toISOString(),
                'timeout_minutes' => config('qr-payment.qr_code.expiry_minutes', 5),
            ], 'QR code generated successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to generate QR code: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Regenerate expired QR code
     * FR-CW-004: QR code regenerates automatically upon expiry
     */
    public function regenerateQrCode(Request $request, string $sessionId): JsonResponse
    {
        try {
            // Validate session exists and belongs to customer
            $session = $this->sessionService->getSession($sessionId);
            if (!$session) {
                return $this->notFound('Session not found');
            }

            // Regenerate QR code
            $qrCode = $this->qrCodeService->regenerateQrCode(
                $sessionId,
                $request->only(['size', 'format'])
            );

            $expiryTime = $this->qrCodeService->getQrCodeExpiry($sessionId);

            return $this->success([
                'session_id' => $sessionId,
                'qr_code' => $qrCode,
                'expires_at' => $expiryTime?->toISOString(),
            ], 'QR code regenerated successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to regenerate QR code: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get payment session status
     */
    public function getSessionStatus(string $sessionId): JsonResponse
    {
        try {
            $session = $this->sessionService->getSession($sessionId);
            if (!$session) {
                return $this->notFound('Session not found');
            }

            $isActive = $this->sessionService->isSessionActive($sessionId);
            $expiryTime = $this->qrCodeService->getQrCodeExpiry($sessionId);

            return $this->success([
                'session_id' => $sessionId,
                'status' => $session->status,
                'is_active' => $isActive,
                'expires_at' => $expiryTime?->toISOString(),
                'merchant_id' => $session->merchant_id,
                'amount' => $session->amount,
                'scanned_at' => $session->scanned_at?->toISOString(),
            ]);

        } catch (\Exception $e) {
            return $this->error('Failed to get session status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Confirm transaction payment
     * FR-CW-008: Support authentication methods (PIN, biometric, password, pattern)
     */
    public function confirmTransaction(ConfirmTransactionRequest $request, string $transactionId): JsonResponse
    {
        try {
            $authMethod = $request->input('auth_method');
            $authData = $request->input('auth_data', []);

            $transaction = $this->transactionService->confirmTransaction(
                $transactionId,
                $authMethod,
                $authData
            );

            return $this->success([
                'transaction_id' => $transaction->transaction_id,
                'status' => $transaction->status,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'merchant_id' => $transaction->merchant_id,
                'confirmed_at' => $transaction->confirmed_at?->toISOString(),
                'auth_method' => $transaction->auth_method,
            ], 'Transaction confirmed successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to confirm transaction: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Cancel pending transaction
     */
    public function cancelTransaction(Request $request, string $transactionId): JsonResponse
    {
        try {
            $reason = $request->input('reason', 'Customer cancelled');

            $transaction = $this->transactionService->cancelTransaction($transactionId, $reason);

            return $this->success([
                'transaction_id' => $transaction->transaction_id,
                'status' => $transaction->status,
                'cancelled_at' => $transaction->cancelled_at?->toISOString(),
                'reason' => $transaction->failure_reason,
            ], 'Transaction cancelled successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to cancel transaction: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get customer transaction history
     * FR-CW-010: View transaction history with filters
     */
    public function getTransactionHistory(Request $request): JsonResponse
    {
        try {
            $customerId = $request->input('customer_id');
            $filters = $request->only(['status', 'type', 'from_date', 'to_date']);
            $limit = $request->input('limit', 50);

            $transactions = $this->transactionService->getCustomerTransactions(
                $customerId,
                $filters,
                $limit
            );

            return $this->success([
                'transactions' => array_map(function ($transaction) {
                    return [
                        'transaction_id' => $transaction->transaction_id,
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'status' => $transaction->status,
                        'type' => $transaction->type,
                        'merchant_id' => $transaction->merchant_id,
                        'created_at' => $transaction->created_at->toISOString(),
                        'confirmed_at' => $transaction->confirmed_at?->toISOString(),
                    ];
                }, $transactions),
                'count' => count($transactions),
            ]);

        } catch (\Exception $e) {
            return $this->error('Failed to get transaction history: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get transaction details
     */
    public function getTransaction(string $transactionId): JsonResponse
    {
        try {
            $transaction = $this->transactionService->getTransaction($transactionId);
            if (!$transaction) {
                return $this->notFound('Transaction not found');
            }

            return $this->success([
                'transaction_id' => $transaction->transaction_id,
                'session_id' => $transaction->session_id,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'fees' => $transaction->fees,
                'net_amount' => $transaction->net_amount,
                'status' => $transaction->status,
                'type' => $transaction->type,
                'merchant_id' => $transaction->merchant_id,
                'auth_method' => $transaction->auth_method,
                'created_at' => $transaction->created_at->toISOString(),
                'processed_at' => $transaction->processed_at?->toISOString(),
                'confirmed_at' => $transaction->confirmed_at?->toISOString(),
                'timeout_at' => $transaction->timeout_at?->toISOString(),
                'metadata' => $transaction->metadata,
            ]);

        } catch (\Exception $e) {
            return $this->error('Failed to get transaction: ' . $e->getMessage(), 500);
        }
    }
}