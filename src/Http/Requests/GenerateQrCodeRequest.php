<?php

namespace XavierAu\LaravelQrPayment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateQrCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'required|string|max:255',
            'currency' => 'sometimes|string|size:3',
            'size' => 'sometimes|integer|min:100|max:1000',
            'format' => 'sometimes|string|in:png,jpg,svg',
            'metadata' => 'sometimes|array',
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Customer ID is required',
            'currency.size' => 'Currency must be a 3-character ISO code',
            'size.min' => 'QR code size must be at least 100px',
            'size.max' => 'QR code size must not exceed 1000px',
            'format.in' => 'QR code format must be png, jpg, or svg',
        ];
    }
}