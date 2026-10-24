<?php

namespace App\Http\Requests;

use App\Models\Category;
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
        $roleName = $this->user()?->role?->name;

        return in_array($roleName, ['Owner', 'Manager', 'Inventory'], true);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $categoryId = $this->route('category') instanceof Category
            ? $this->route('category')->id
            : $this->route('category');

        return [
            // Nama kategori unik PER TENANT, mengecualikan baris yang sedang diedit.
            'category_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'category_name')
                    ->where('tenant_id', $tenantId)
                    ->ignore($categoryId),
            ],
            'category_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('categories', 'category_code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($categoryId),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                'not_in:'.$categoryId,
                Rule::exists('categories', 'id')->where('tenant_id', $tenantId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
