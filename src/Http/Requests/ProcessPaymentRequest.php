<?php

namespace XavierAu\LaravelQrPayment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxAmount = config('qr-payment.transaction.max_amount', 1000000) / 100; // Convert from cents

        return [
            'session_id' => 'required|string',
            'merchant_id' => 'required|string|max:255',
            'customer_id' => 'required|string|max:255',
            'amount' => "required|numeric|min:0.01|max:{$maxAmount}",
            'currency' => 'sometimes|string|size:3',
            'merchant_name' => 'sometimes|string|max:255',
            'location' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|max:500',
            'items' => 'sometimes|array',
            'items.*' => 'string|max:255',
            'tip_amount' => 'sometimes|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'session_id.required' => 'Session ID is required',
            'merchant_id.required' => 'Merchant ID is required',
            'customer_id.required' => 'Customer ID is required',
            'amount.required' => 'Transaction amount is required',
            'amount.min' => 'Transaction amount must be at least $0.01',
            'amount.max' => 'Transaction amount exceeds maximum limit',
            'currency.size' => 'Currency must be a 3-character ISO code',
            'description.max' => 'Description must not exceed 500 characters',
            'tip_amount.min' => 'Tip amount cannot be negative',
        ];
    }
}