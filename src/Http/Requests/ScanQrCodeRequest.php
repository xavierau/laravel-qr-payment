<?php

namespace XavierAu\LaravelQrPayment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScanQrCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qr_data' => 'required|string',
            'merchant_id' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'qr_data.required' => 'QR code data is required',
            'merchant_id.required' => 'Merchant ID is required',
        ];
    }
}