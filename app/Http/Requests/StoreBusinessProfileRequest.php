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

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'min:2', 'max:120'],
            'business_category' => [
                'required',
                'string',
                Rule::in(['fnb_cafe', 'fnb_restaurant', 'fnb_bakery', 'fnb_cloud_kitchen', 'retail_other']),
            ],
            'country_code' => ['required', 'string', 'size:2'],
            'tenant_timezone' => ['required', 'string', 'timezone:all'],
            'default_currency' => ['required', 'string', 'size:3'],
            'owner_phone' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_name.required' => 'Business or brand name is required.',
            'business_category.required' => 'Business category is required.',
            'tenant_timezone.required' => 'Primary timezone is required.',
            'default_currency.required' => 'Default operating currency is required.',
        ];
    }
}
