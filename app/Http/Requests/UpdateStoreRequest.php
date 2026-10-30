<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $store = $this->route('store');

        return $store && $this->user()?->can('update', $store);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'in:Asia/Jakarta,Asia/Makassar,Asia/Jayapura'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:20'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Branch name is required.',
            'timezone.required' => 'Branch timezone is required.',
            'timezone.in' => 'Timezone must be one of WIB (Asia/Jakarta), WITA (Asia/Makassar), or WIT (Asia/Jayapura).',
        ];
    }
}
