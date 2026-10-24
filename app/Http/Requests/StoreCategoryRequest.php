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
        $roleName = $this->user()?->role?->name;

        return in_array($roleName, ['Owner', 'Manager', 'Inventory'], true);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        return [
            // Nama kategori unik PER TENANT (bukan global) — dua tenant boleh
            // sama-sama punya kategori "Minuman". Selaras dengan unique index
            // categories_tenant_id_category_name_active_unique.
            'category_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'category_name')
                    ->where('tenant_id', $tenantId),
            ],
            'category_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('categories', 'category_code')
                    ->where('tenant_id', $tenantId),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('tenant_id', $tenantId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
