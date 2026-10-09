<?php

namespace App\Http\Requests;

use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request bertugas mengesahkan isian tambah (Store) data entitas Produk dagangan.
 */
class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', Rule::exists('categories', 'id')->where('tenant_id', app(TenantContext::class)->requireTenantId())],
            'product_name' => 'required|string|max:255',
            'product_price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'product_photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:30720',
            'product_description' => 'nullable|string',
            'is_active' => 'nullable|boolean',

            // BOM / Ingredients validation
            'ingredients' => 'nullable|array',
            'ingredients.*.id' => ['required_with:ingredients', Rule::exists('ingredients', 'id')->where('tenant_id', app(TenantContext::class)->requireTenantId())],
            'ingredients.*.quantity' => 'required_with:ingredients|numeric|min:0.0001',

            // Modifier Groups
            'modifier_groups' => 'nullable|array',
            'modifier_groups.*' => [Rule::exists('modifier_groups', 'id')->where('tenant_id', app(TenantContext::class)->requireTenantId())],
        ];
    }
}
