<?php

use Illuminate\Support\Facades\Route;
use XavierAu\LaravelQrPayment\Http\Controllers\CustomerController;
use XavierAu\LaravelQrPayment\Http\Controllers\MerchantController;

Route::middleware(['api'])->prefix('qr-payment')->name('qr-payment.')->group(function () {
    
    // Customer API Routes
    Route::prefix('customer')->name('customer.')->group(function () {
        Route::post('qr-code', [CustomerController::class, 'generateQrCode'])->name('generate-qr');
        Route::put('qr-code/{sessionId}/regenerate', [CustomerController::class, 'regenerateQrCode'])->name('regenerate-qr');
        Route::get('session/{sessionId}/status', [CustomerController::class, 'getSessionStatus'])->name('session-status');
        Route::post('transaction/{transactionId}/confirm', [CustomerController::class, 'confirmTransaction'])->name('confirm-transaction');
        Route::post('transaction/{transactionId}/cancel', [CustomerController::class, 'cancelTransaction'])->name('cancel-transaction');
        Route::get('transactions', [CustomerController::class, 'getTransactionHistory'])->name('transaction-history');
        Route::get('transaction/{transactionId}', [CustomerController::class, 'getTransaction'])->name('get-transaction');
    });

    // Merchant API Routes
    Route::prefix('merchant')->name('merchant.')->group(function () {
        Route::post('qr-code/scan', [MerchantController::class, 'scanQrCode'])->name('scan-qr');
        Route::post('payment/process', [MerchantController::class, 'processPayment'])->name('process-payment');
        Route::get('payment/{transactionId}/status', [MerchantController::class, 'getPaymentStatus'])->name('payment-status');
        Route::get('transactions', [MerchantController::class, 'getTransactionHistory'])->name('transaction-history');
        Route::post('transaction/{transactionId}/refund', [MerchantController::class, 'processRefund'])->name('process-refund');
        Route::get('transaction/{transactionId}/receipt', [MerchantController::class, 'getReceipt'])->name('get-receipt');
    });
});