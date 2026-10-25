<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request penanggung jawab pembatasan input untuk suntingan (Update) Data Produk.
 */
class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $roleName = $this->user()?->role?->name;

        return in_array($roleName, ['Owner', 'Manager', 'Inventory'], true);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $productId = $this->route('product') instanceof Product
            ? $this->route('product')->id
            : $this->route('product');

        return [
            'category_id' => ['required', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)],
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where('tenant_id', $tenantId)],
            'base_uom_id' => ['nullable', Rule::exists('units_of_measure', 'id')->where('tenant_id', $tenantId)],
            'tax_class_id' => ['nullable', Rule::exists('tax_classes', 'id')->where('tenant_id', $tenantId)],
            'is_tax_inclusive' => ['nullable', 'boolean'],
            'product_name' => 'required|string|max:255',
            'product_code' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('products', 'product_code')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($productId),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($productId),
            ],
            'product_type' => ['nullable', 'string', 'in:standard,service,non_inventory,bundle'],
            'status' => ['nullable', 'string', 'in:draft,active,inactive,discontinued'],
            'track_inventory' => ['nullable', 'boolean'],
            'product_price' => 'required|numeric|min:0',
            'costing_method' => ['nullable', 'string', 'in:standard,fifo,moving_average'],
            'standard_cost' => ['nullable', 'numeric', 'min:0'],
            'stock' => 'nullable|integer|min:0',
            'product_photo' => 'nullable|image|mimes:jpeg,png,jpg,webp,avif|max:2048',
            'product_description' => 'nullable|string',
            'is_active' => 'nullable|boolean',

            // BOM / Ingredients validation
            'ingredients' => 'nullable|array',
            'ingredients.*.id' => ['required_with:ingredients', Rule::exists('ingredients', 'id')->where('tenant_id', $tenantId)],
            'ingredients.*.quantity' => 'required_with:ingredients|numeric|min:0.0001',

            'cost_change_reason' => 'nullable|string|max:255',

            // Modifier Groups
            'modifier_groups' => 'nullable|array',
            'modifier_groups.*' => [Rule::exists('modifier_groups', 'id')->where('tenant_id', $tenantId)],

            // Variants
            'variants' => 'nullable|array',
            'variants.*.variant_name' => 'required_with:variants|string|max:150',
            'variants.*.sku' => 'nullable|string|max:64',
            'variants.*.barcode' => 'nullable|string|max:100',
            'variants.*.price_override' => 'nullable|numeric|min:0',
            'variants.*.cost_price' => 'nullable|numeric|min:0',
            'variants.*.attributes' => 'nullable|array',
            'variants.*.attributes.*.attribute_name' => 'required_with:variants.*.attributes|string|max:100',
            'variants.*.attributes.*.attribute_value' => 'required_with:variants.*.attributes|string|max:150',

            // Bundle Items
            'bundle_items' => 'nullable|array',
            'bundle_items.*.component_product_id' => ['required_with:bundle_items', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'bundle_items.*.quantity' => 'required_with:bundle_items|numeric|min:0.0001',
            'bundle_items.*.unit_price_override' => 'nullable|numeric|min:0',
        ];
    }
}
