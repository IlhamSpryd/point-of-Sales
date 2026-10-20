<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $roleName = $this->user()?->role?->name;

        return in_array($roleName, ['Owner', 'Manager'], true);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        return [
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('price_lists', 'code')->where('tenant_id', $tenantId),
            ],
            'name' => 'required|string|max:150',
            'type' => 'required|string|in:standard,branch,wholesale,customer_tier,customer_specific,channel',
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'loyalty_tier_id' => ['nullable', Rule::exists('loyalty_tiers', 'id')->where('tenant_id', $tenantId)],
            'store_id' => ['nullable', Rule::exists('stores', 'id')->where('tenant_id', $tenantId)],
            'channel' => 'nullable|string|max:50',
            'priority' => 'nullable|integer|min:0',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'is_active' => 'nullable|boolean',

            'items' => 'nullable|array',
            'items.*.product_id' => ['required_with:items', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.product_variant_id' => ['nullable', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'items.*.min_quantity' => 'required_with:items|integer|min:1',
            'items.*.price' => 'required_with:items|numeric|min:0',
        ];
    }
}
