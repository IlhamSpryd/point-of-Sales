<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PairDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pairing_token' => ['required', 'string', 'max:64'],
            'device_fingerprint' => ['required', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'pairing_token.required' => 'Pairing code is required.',
            'device_fingerprint.required' => 'Physical device fingerprint could not be detected.',
        ];
    }
}
