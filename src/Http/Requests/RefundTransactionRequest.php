<?php

namespace XavierAu\LaravelQrPayment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefundTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'sometimes|string|max:500',
            'manager_approval_code' => 'required|string|min:6',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Refund amount is required',
            'amount.min' => 'Refund amount must be at least $0.01',
            'reason.max' => 'Refund reason must not exceed 500 characters',
            'manager_approval_code.required' => 'Manager approval code is required for refunds',
            'manager_approval_code.min' => 'Manager approval code must be at least 6 characters',
        ];
    }
}