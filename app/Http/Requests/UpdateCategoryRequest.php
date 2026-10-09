<?php

namespace App\Http\Requests;

use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request untuk mengawal integritas pengeditan (Update) pada spesifik kategori.
 */
class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Nama kategori unik PER TENANT, mengecualikan baris yang sedang diedit.
            'category_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'category_name')
                    ->where('tenant_id', app(TenantContext::class)->requireTenantId())
                    ->ignore($this->category->id),
            ],
        ];
    }
}
