<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductAssortmentRequest extends FormRequest
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
            'store_id' => ['required', Rule::exists('stores', 'id')->where('tenant_id', $tenantId)],
            'product_id' => ['required', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'product_variant_id' => ['nullable', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'is_available' => 'required|boolean',
            'store_price_override' => 'nullable|numeric|min:0',
            'allowed_channels' => 'nullable|array',
            'allowed_channels.*' => 'string|max:50',
        ];
    }
}
