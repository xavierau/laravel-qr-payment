<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tablePrefix = config('qr-payment.database.table_prefix', 'qr_payment_');
        
        Schema::create($tablePrefix . 'transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique();
            $table->string('session_id');
            $table->string('customer_id');
            $table->string('merchant_id');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->enum('type', ['payment', 'refund', 'settlement'])->default('payment');
            $table->enum('status', [
                'pending', 
                'processing', 
                'confirmed', 
                'completed', 
                'failed', 
                'cancelled', 
                'refunded'
            ])->default('pending');
            $table->enum('auth_method', ['pin', 'biometric', 'password', 'pattern'])->nullable();
            $table->json('auth_data')->nullable();
            $table->decimal('fees', 8, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->string('reference_id')->nullable();
            $table->string('parent_transaction_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('timeout_at')->nullable();
            $table->json('metadata')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['customer_id', 'status']);
            $table->index(['merchant_id', 'status']);
            $table->index(['session_id']);
            $table->index(['status', 'created_at']);
            $table->index(['parent_transaction_id']);
            $table->index('transaction_id');

            // Note: Foreign key constraints can be added later if needed
        });
    }

    public function down(): void
    {
        $tablePrefix = config('qr-payment.database.table_prefix', 'qr_payment_');
        Schema::dropIfExists($tablePrefix . 'transactions');
    }
};