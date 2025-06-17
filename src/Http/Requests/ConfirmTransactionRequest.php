<?php

namespace XavierAu\LaravelQrPayment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use XavierAu\LaravelQrPayment\Models\Transaction;

class ConfirmTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'auth_method' => [
                'required',
                'string',
                'in:' . implode(',', [
                    Transaction::AUTH_PIN,
                    Transaction::AUTH_BIOMETRIC,
                    Transaction::AUTH_PASSWORD,
                    Transaction::AUTH_PATTERN,
                ])
            ],
            'auth_data' => 'sometimes|array',
            'auth_data.pin' => 'required_if:auth_method,pin|string|min:4|max:6',
            'auth_data.fingerprint_hash' => 'required_if:auth_method,biometric|string',
            'auth_data.password' => 'required_if:auth_method,password|string|min:6',
            'auth_data.pattern' => 'required_if:auth_method,pattern|string',
        ];
    }

    public function messages(): array
    {
        return [
            'auth_method.required' => 'Authentication method is required',
            'auth_method.in' => 'Invalid authentication method',
            'auth_data.pin.required_if' => 'PIN is required for PIN authentication',
            'auth_data.pin.min' => 'PIN must be at least 4 digits',
            'auth_data.pin.max' => 'PIN must not exceed 6 digits',
            'auth_data.fingerprint_hash.required_if' => 'Fingerprint hash is required for biometric authentication',
            'auth_data.password.required_if' => 'Password is required for password authentication',
            'auth_data.password.min' => 'Password must be at least 6 characters',
            'auth_data.pattern.required_if' => 'Pattern is required for pattern authentication',
        ];
    }
}