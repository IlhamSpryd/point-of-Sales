<?php

namespace App\Http\Requests;

use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request untuk memvalidasi masukan pembuatan kategori baru.
 */
class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Nama kategori unik PER TENANT (bukan global) — dua tenant boleh
            // sama-sama punya kategori "Minuman". Selaras dengan unique index
            // categories_tenant_id_category_name_active_unique.
            'category_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'category_name')
                    ->where('tenant_id', app(TenantContext::class)->requireTenantId()),
            ],
        ];
    }
}
