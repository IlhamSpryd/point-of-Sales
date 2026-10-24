<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        return $user->role?->name === 'Owner' || (bool) ($user->role->is_admin ?? false);
    }

    protected function prepareForValidation(): void
    {
        $user = $this->user();
        if ($user && $user->tenant) {
            $tenant = $user->tenant;
            $this->merge([
                'business_name' => $this->input('business_name') ?: $tenant->name,
                'country_code' => $this->input('country_code') ?: ($tenant->country_code ?? 'ID'),
                'default_currency' => $this->input('default_currency') ?: 'IDR',
                'owner_phone' => $this->input('owner_phone') ?: $user->phone_number,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'business_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'business_category' => [
                'required',
                'string',
                Rule::in(['fnb_cafe', 'fnb_restaurant', 'fnb_bakery', 'fnb_cloud_kitchen', 'retail_other']),
            ],
            'country_code' => ['nullable', 'string', 'size:2'],
            'tenant_timezone' => ['required', 'string', 'timezone:all'],
            'default_currency' => ['nullable', 'string', 'size:3'],
            'owner_phone' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_category.required' => 'Business category is required.',
            'tenant_timezone.required' => 'Primary timezone is required.',
        ];
    }
}
