<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tablePrefix = config('qr-payment.database.table_prefix', 'qr_payment_');
        
        Schema::create($tablePrefix . 'sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique();
            $table->string('customer_id');
            $table->string('merchant_id')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->enum('status', ['pending', 'scanned', 'confirmed', 'expired', 'cancelled'])
                  ->default('pending');
            $table->string('security_token');
            $table->timestamp('expires_at');
            $table->timestamp('scanned_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['customer_id', 'status']);
            $table->index(['merchant_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        $tablePrefix = config('qr-payment.database.table_prefix', 'qr_payment_');
        Schema::dropIfExists($tablePrefix . 'sessions');
    }
};