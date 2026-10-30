<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;

class StoreStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Store::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'timezone' => ['required', 'string', 'in:Asia/Jakarta,Asia/Makassar,Asia/Jayapura'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:20'],
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
